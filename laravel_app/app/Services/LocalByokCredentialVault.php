<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Windows: DPAPI CurrentUser encryption binds keys to the OS logon identity.
 * Non-Windows development/test: Laravel Crypt, bound to local APP_KEY.
 * Never return secrets through the UI or log them. No shared application key.
 */
final class LocalByokCredentialVault
{
    public function connected(int $userId, string $provider): bool
    {
        $row = DB::table('ii_byok_credentials')
            ->where('user_id', $userId)->where('provider', $provider)->first(['encrypted_api_key']);
        if ($row === null) {
            return false;
        }
        // Old APP_KEY-encrypted credentials must be explicitly reconnected on
        // Windows. Do not advertise an undecryptable key as connected.
        return PHP_OS_FAMILY !== 'Windows'
            || str_starts_with((string) $row->encrypted_api_key, WindowsDpapiCredentialProtector::PREFIX);
    }

    public function save(int $userId, string $provider, string $key): void
    {
        if ($provider !== 'marketdata') {
            throw new \InvalidArgumentException('BYOK_PROVIDER_UNSUPPORTED');
        }
        $key = trim($key);
        if ($key === '' || strlen($key) > 512 || preg_match('/[\r\n\x00-\x1f]/', $key)) {
            throw new \InvalidArgumentException('BYOK_KEY_INVALID');
        }
        DB::table('ii_byok_credentials')->updateOrInsert(
            ['user_id' => $userId, 'provider' => $provider],
            [
                'encrypted_api_key' => $this->encrypt($key),
                'rights_profile' => ByokRightsPolicy::RESTRICTED,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function key(int $userId, string $provider): string
    {
        $entry = DB::table('ii_byok_credentials')
            ->where('user_id', $userId)->where('provider', $provider)->first();
        if ($entry === null || $entry->rights_profile !== ByokRightsPolicy::RESTRICTED) {
            throw new \RuntimeException('BYOK_NOT_CONNECTED');
        }

        return $this->decrypt((string) $entry->encrypted_api_key);
    }

    public function disconnect(int $userId, string $provider): void
    {
        DB::table('ii_byok_credentials')
            ->where('user_id', $userId)->where('provider', $provider)->delete();
    }
    private function encrypt(string $key): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows release MUST fail closed if DPAPI cannot run.
            return app(WindowsDpapiCredentialProtector::class)->protect($key);
        }

        return 'laravel-local-v1:'.Crypt::encryptString($key);
    }

    private function decrypt(string $encrypted): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            if (! str_starts_with($encrypted, WindowsDpapiCredentialProtector::PREFIX)) {
                // No silent migration of old APP_KEY-only data. Reconnect the
                // provider key after installing the Windows DPAPI-capable build.
                throw new \RuntimeException('BYOK_RECONNECT_REQUIRED_FOR_WINDOWS_DPAPI');
            }

            return app(WindowsDpapiCredentialProtector::class)->unprotect($encrypted);
        }

        if (! str_starts_with($encrypted, 'laravel-local-v1:')) {
            throw new \RuntimeException('BYOK_CREDENTIAL_FORMAT_NOT_LOCAL');
        }

        return Crypt::decryptString(substr($encrypted, strlen('laravel-local-v1:')));
    }

}
