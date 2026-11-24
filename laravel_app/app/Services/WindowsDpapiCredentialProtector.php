<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Windows per-user DPAPI encryption. No credentials on command line, in
 * environment variables, in temp files or in diagnostic output.
 *
 * Locked to the Windows logon identity that created the credential; running
 * Laravel under a different Windows account cannot decrypt it.
 */
final class WindowsDpapiCredentialProtector
{
    public const PREFIX = 'dpapi-current-user-v1:';

    public function protect(string $value): string
    {
        return self::PREFIX.$this->invoke('protect', $value);
    }

    public function unprotect(string $value): string
    {
        if (! str_starts_with($value, self::PREFIX)) {
            throw new \RuntimeException('II_DPAPI_FORMAT_UNSUPPORTED');
        }

        $ciphertext = substr($value, strlen(self::PREFIX));
        if (! $this->isBase64($ciphertext)) {
            throw new \RuntimeException('II_DPAPI_FORMAT_INVALID');
        }

        $decoded = base64_decode($this->invoke('unprotect', base64_decode($ciphertext, true)), true);
        if ($decoded === false || $decoded === '') {
            throw new \RuntimeException('II_DPAPI_DECRYPT_FAILED');
        }

        return $decoded;
    }

    private function invoke(string $operation, string $raw): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new \RuntimeException('II_DPAPI_WINDOWS_REQUIRED');
        }

        $systemRoot = getenv('SystemRoot') ?: getenv('WINDIR');
        if (! is_string($systemRoot) || $systemRoot === '') {
            throw new \RuntimeException('II_DPAPI_SYSTEM_ROOT_MISSING');
        }
        $powerShell = rtrim($systemRoot, '\\/').'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        $script = __DIR__.DIRECTORY_SEPARATOR.'WindowsDpapiCredential.ps1';
        if (! is_file($powerShell) || ! is_file($script)) {
            throw new \RuntimeException('II_DPAPI_RUNTIME_NOT_FOUND');
        }
        if (strlen($raw) > 4096 || $raw === '') {
            throw new \RuntimeException('II_DPAPI_INPUT_INVALID');
        }

        // Windows PowerShell startup and the DPAPI user profile can be
        // transiently unavailable under cold-start CI / interactive login.
        // Retry a bounded local-only operation once; never fall back to
        // plaintext, Laravel Crypt, or a different Windows user identity.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $process = new Process([
                    $powerShell, '-NoLogo', '-NoProfile', '-NonInteractive',
                    '-ExecutionPolicy', 'Bypass', '-File', $script, $operation,
                ]);
                // Stdin only. No plaintext in arguments, env or temp files.
                $process->setInput(base64_encode($raw));
                $process->setTimeout(45);
                $process->run();
                if (! $process->isSuccessful()) {
                    throw new \RuntimeException('II_DPAPI_OPERATION_FAILED');
                }
                $output = trim($process->getOutput());
                if (! $this->isBase64($output)) {
                    throw new \RuntimeException('II_DPAPI_OUTPUT_INVALID');
                }

                return $output;
            } catch (\Throwable) {
                if ($attempt === 2) {
                    throw new \RuntimeException('II_DPAPI_OPERATION_FAILED');
                }

                usleep(200_000);
            }
        }

        throw new \RuntimeException('II_DPAPI_OPERATION_FAILED');
    }

    private function isBase64(string $value): bool
    {
        return $value !== '' && strlen($value) <= 8192
            && preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $value) === 1
            && base64_decode($value, true) !== false;
    }
}
