<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\MarketFeedSettings;
use Illuminate\Console\Command;

final class ConnectMarketFeedCommand extends Command
{
    protected $signature = 'ii:connect-market-feed {--disconnect : Remove the configured owner credential}';
    protected $description = 'Manage the configured owner Twelve Data credential locally';

    public function handle(MarketFeedSettings $settings): int
    {
        $cfg = $settings->current();
        $owner = filter_var($cfg['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner || !User::query()->whereKey($owner)->exists() || ($cfg['provider'] ?? null) !== 'twelve_data' || !$settings->canManage($owner)) {
            $this->error('II_FEED_CONFIGURATION_INVALID');
            return self::FAILURE;
        }
        try {
            if ($this->option('disconnect')) {
                $settings->disconnectCredential($owner);
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
            $settings->replaceCredential($owner, $key);
            $this->info('II_FEED_KEY_STORED_LOCALLY');
            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('II_FEED_CREDENTIAL_OPERATION_FAILED');
            return self::FAILURE;
        }
    }
}
