<?php

use App\Exceptions\ClamdUnavailableException;
use App\Services\ClamdService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function useCleanClamdScanner(): void
{
    app()->instance(ClamdService::class, new class extends ClamdService
    {
        protected function scanPath(string $filePath): array
        {
            return [
                'clean' => true,
                'result' => "{$filePath}: OK",
            ];
        }
    });
}

function useFoundClamdScanner(string $signature = 'Eicar-Test-Signature'): void
{
    app()->instance(ClamdService::class, new class($signature) extends ClamdService
    {
        public function __construct(private readonly string $signature)
        {
        }

        protected function scanPath(string $filePath): array
        {
            return [
                'clean' => false,
                'result' => "{$filePath}: {$this->signature} FOUND",
            ];
        }
    });
}

function useUnavailableClamdScanner(): void
{
    app()->instance(ClamdService::class, new class extends ClamdService
    {
        protected function scanPath(string $filePath): array
        {
            throw new ClamdUnavailableException('ClamAV scanner unavailable');
        }
    });
}

function useTestClamavQuarantinePath(?string $path = null): string
{
    $path ??= storage_path('framework/testing/clamav-quarantine');

    File::deleteDirectory($path);
    config(['clamav.quarantine_path' => $path]);

    return $path;
}
