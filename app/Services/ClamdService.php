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
    public function scanUploadedFile(UploadedFile $file, array $context = []): void
    {
        $filePath = $file->getRealPath();

        if (!$filePath || !is_file($filePath)) {
            $this->logScan('ERROR', $file, $context, [
                'scan_result' => 'Temporary upload file is not readable',
            ]);

            throw new ClamdUnavailableException('ClamAV scanner unavailable');
        }

        try {
            $scan = $this->scanPath($filePath);
        } catch (ClamdUnavailableException $exception) {
            $this->logScan('ERROR', $file, $context, [
                'scan_result' => $exception->getMessage(),
            ]);

            throw $exception;
        } catch (Throwable $exception) {
            $this->logScan('ERROR', $file, $context, [
                'scan_result' => $exception->getMessage(),
            ]);

            throw new ClamdUnavailableException('ClamAV scanner unavailable', 0, $exception);
        }

        if ($scan['clean']) {
            $this->logScan('CLEAN', $file, $context, [
                'scan_result' => $scan['result'],
            ]);

            return;
        }

        $quarantinePath = null;

        try {
            $quarantinePath = $this->quarantineFile($file);
        } catch (Throwable $exception) {
            $this->logScan('ERROR', $file, $context, [
                'scan_result' => 'Failed to quarantine infected file: '.$exception->getMessage(),
            ]);
        }

        $this->logScan('FOUND', $file, $context, [
            'scan_result' => $scan['result'],
            'quarantine_path' => $quarantinePath,
        ]);

        throw new MalwareDetectedException($scan['result'], $quarantinePath);
    }

    /**
     * @return array{clean: bool, result: string}
     */
    protected function scanPath(string $filePath): array
    {
        if (!config('clamav.enabled', true)) {
            return [
                'clean' => true,
                'result' => "{$filePath}: SKIPPED",
            ];
        }

        $socket = $this->openSocket();
        $command = "zSCAN {$filePath}\0";
        $this->writeAll($socket, $command);
        $reply = $this->readReply($socket);
        fclose($socket);

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
        $timeout = (float) config('clamav.timeout', 5.0);
        $connection = config('clamav.connection', 'unix');

        $target = $connection === 'tcp'
            ? sprintf('tcp://%s:%d', config('clamav.host', '127.0.0.1'), (int) config('clamav.port', 3310))
            : 'unix://'.config('clamav.socket_path', '/run/clamav/clamd.sock');

        $socket = @stream_socket_client($target, $errno, $errstr, $timeout);

        if (!$socket) {
            throw new ClamdUnavailableException("ClamAV scanner unavailable: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, (int) ceil($timeout));

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

        if (!copy($file->getRealPath(), $destination)) {
            throw new ClamdUnavailableException('Failed to copy infected file into quarantine');
        }

        return $destination;
    }

    private function logScan(string $status, UploadedFile $file, array $context, array $extra = []): void
    {
        try {
            $basePath = $this->quarantinePath();
            File::ensureDirectoryExists($basePath, 0755, true);

            $payload = [
                'timestamp' => now()->toISOString(),
                'status' => $status,
                'original_name' => $file->getClientOriginalName(),
                'client_mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'temp_path' => $file->getRealPath(),
                'context' => $context,
                ...$extra,
            ];

            file_put_contents(
                $basePath.DIRECTORY_SEPARATOR.'clamav-upload.log',
                json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );
        } catch (Throwable) {
            // Logging must not turn a scan result into a different user-facing error.
        }
    }

    private function quarantinePath(?string $child = null): string
    {
        $basePath = rtrim((string) config('clamav.quarantine_path', '/home/devops/hasbi/quarantine'), DIRECTORY_SEPARATOR);

        return $child ? $basePath.DIRECTORY_SEPARATOR.$child : $basePath;
    }
}
