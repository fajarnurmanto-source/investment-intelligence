<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\LocalByokCredentialVault;
use Illuminate\Console\Command;

final class ConnectMarketFeedCommand extends Command
{
    protected $signature = 'ii:connect-market-feed {--disconnect : Remove the configured owner credential}';
    protected $description = 'Manage the configured owner Twelve Data credential locally';

    public function handle(LocalByokCredentialVault $vault): int
    {
        $owner = filter_var(config('market_feed.owner_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner || !User::query()->whereKey($owner)->exists() || config('market_feed.provider') !== 'twelve_data') {
            $this->error('II_FEED_CONFIGURATION_INVALID');
            return self::FAILURE;
        }
        try {
            if ($this->option('disconnect')) {
                $vault->disconnect($owner, 'twelve_data');
                $this->info('II_FEED_KEY_DISCONNECTED');
                return self::SUCCESS;
            }
            if (!$this->input->isInteractive()) {
                $this->error('II_FEED_INTERACTIVE_REQUIRED');
                return self::FAILURE;
            }
            $key = $this->secret('Authorized Twelve Data API key', false);
            if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $key) || strtolower($key) === 'demo') {
                $this->error('II_FEED_KEY_INVALID');
                return self::FAILURE;
            }
            $vault->save($owner, 'twelve_data', $key);
            $this->info('II_FEED_KEY_STORED_LOCALLY');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('II_FEED_CREDENTIAL_OPERATION_FAILED');
            return self::FAILURE;
        }
    }
}
