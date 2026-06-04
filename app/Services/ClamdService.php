<?php

namespace App\Services;

use App\Exceptions\ClamdUnavailableException;
use App\Exceptions\MalwareDetectedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

class ClamdService
{
    private const SOCKET_TIMEOUT_SECONDS = 5;
    private const STREAM_CHUNK_SIZE = 8192;

    public function scanUploadedFile(UploadedFile $file): void
    {
        $filePath = $file->getRealPath();

        if (!$filePath || !is_file($filePath)) {
            throw new ClamdUnavailableException('ClamAV scanner unavailable');
        }

        try {
            $scan = $this->scanUploadStream($filePath);
        } catch (ClamdUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ClamdUnavailableException('ClamAV scanner unavailable', 0, $exception);
        }

        if ($scan['clean']) {
            return;
        }

        $quarantinePath = null;

        try {
            $quarantinePath = $this->quarantineFile($file);
        } catch (Throwable) {
            $quarantinePath = null;
        }

        throw new MalwareDetectedException($scan['result'], $quarantinePath);
    }

    /**
     * @return array{clean: bool, result: string}
     */
    protected function scanUploadStream(string $filePath): array
    {
        $handle = @fopen($filePath, 'rb');

        if (!$handle) {
            throw new ClamdUnavailableException('ClamAV scanner unavailable: failed to open upload stream');
        }

        try {
            $socket = $this->openSocket();

            try {
                $this->writeAll($socket, "zINSTREAM\0");

                while (!feof($handle)) {
                    $chunk = fread($handle, self::STREAM_CHUNK_SIZE);

                    if ($chunk === false) {
                        throw new ClamdUnavailableException('ClamAV scanner unavailable: failed to read upload stream');
                    }

                    if ($chunk === '') {
                        break;
                    }

                    $this->writeAll($socket, pack('N', strlen($chunk)).$chunk);
                }

                $this->writeAll($socket, pack('N', 0));
                $reply = $this->readReply($socket);
            } finally {
                fclose($socket);
            }
        } finally {
            fclose($handle);
        }

        $result = trim(str_replace("\0", '', $reply));

        if (str_ends_with($result, ': OK')) {
            return [
                'clean' => true,
                'result' => $result,
            ];
        }

        if (str_contains($result, ' FOUND')) {
            return [
                'clean' => false,
                'result' => $result,
            ];
        }

        throw new ClamdUnavailableException('ClamAV scanner unavailable: '.($result ?: 'empty response'));
    }

    /**
     * @return resource
     */
    private function openSocket()
    {
        $host = trim((string) config('clamav.host', ''));
        $port = (int) config('clamav.port', 0);

        if ($host === '' || $port <= 0) {
            throw new ClamdUnavailableException('ClamAV scanner unavailable: CLAMAV_HOST and CLAMAV_PORT are required');
        }

        $target = sprintf('tcp://%s:%d', $host, $port);

        $socket = @stream_socket_client($target, $errno, $errstr, self::SOCKET_TIMEOUT_SECONDS);

        if (!$socket) {
            throw new ClamdUnavailableException("ClamAV scanner unavailable: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, self::SOCKET_TIMEOUT_SECONDS);

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function writeAll($socket, string $payload): void
    {
        $written = 0;
        $length = strlen($payload);

        while ($written < $length) {
            $chunk = fwrite($socket, substr($payload, $written));

            if ($chunk === false || $chunk === 0) {
                throw new ClamdUnavailableException('ClamAV scanner unavailable: failed to write scan command');
            }

            $written += $chunk;
        }
    }

    /**
     * @param resource $socket
     */
    private function readReply($socket): string
    {
        $reply = '';

        while (!feof($socket)) {
            $chunk = fread($socket, 4096);

            if ($chunk === false) {
                throw new ClamdUnavailableException('ClamAV scanner unavailable: failed to read scan reply');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($socket);

                if ($meta['timed_out'] ?? false) {
                    throw new ClamdUnavailableException('ClamAV scanner unavailable: scan reply timeout');
                }

                break;
            }

            $reply .= $chunk;
        }

        return $reply;
    }

    private function quarantineFile(UploadedFile $file): string
    {
        $filesDir = $this->quarantinePath('files');
        File::ensureDirectoryExists($filesDir, 0755, true);

        $originalName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safeName = Str::slug($originalName) ?: 'upload';
        $extension = trim($file->getClientOriginalExtension());
        $filename = now()->format('Ymd_His').'_'.Str::uuid().'_'.$safeName.($extension !== '' ? ".{$extension}" : '');
        $destination = $filesDir.DIRECTORY_SEPARATOR.$filename;
        $source = $file->getRealPath();

        if (!$source || !is_file($source)) {
            throw new ClamdUnavailableException('Failed to move infected file into quarantine: temp file is not readable');
        }

        if (!copy($source, $destination)) {
            throw new ClamdUnavailableException('Failed to move infected file into quarantine');
        }

        if (!@unlink($source)) {
            @unlink($destination);

            throw new ClamdUnavailableException('Failed to remove infected temp file after quarantine');
        }

        return $destination;
    }

    private function quarantinePath(?string $child = null): string
    {
        $basePath = rtrim((string) config('clamav.quarantine_path', '/home/devops/hasbi/quarantine'), DIRECTORY_SEPARATOR);

        return $child ? $basePath.DIRECTORY_SEPARATOR.$child : $basePath;
    }
}
