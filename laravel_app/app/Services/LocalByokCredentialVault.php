<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * User-supplied credentials are encrypted with the installation-local APP_KEY.
 * Never return secrets through the UI or log them. No shared application key.
 */
final class LocalByokCredentialVault
{
    public function connected(int $userId, string $provider): bool
    {
        return DB::table('ii_byok_credentials')
            ->where('user_id', $userId)->where('provider', $provider)->exists();
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
                'encrypted_api_key' => Crypt::encryptString($key),
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

        return Crypt::decryptString((string) $entry->encrypted_api_key);
    }

    public function disconnect(int $userId, string $provider): void
    {
        DB::table('ii_byok_credentials')
            ->where('user_id', $userId)->where('provider', $provider)->delete();
    }
}
