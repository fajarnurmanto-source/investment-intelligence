<?php

namespace App\Console\Commands;

use App\Services\MarketFeedSettings;
use Illuminate\Console\Command;

final class InitializeMarketOwnerCommand extends Command
{
    protected $signature = 'ii:initialize-market-owner {--user= : Explicit local user ID} {--confirm : Confirm initial owner assignment}';

    protected $description = 'Assign an unconfigured local market owner without changing roles or activating acquisition';

    public function handle(MarketFeedSettings $settings): int
    {
        $owner = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$owner || !$this->option('confirm')) {
            $this->error('II_OWNER_BOOTSTRAP_EXPLICIT_CONFIRMATION_REQUIRED');
            return self::FAILURE;
        }
        try {
            $settings->initializeOwner($owner);
        } catch (\Throwable $e) {
            $code = $e->getMessage();
            $this->error(preg_match('/^II_[A-Z0-9_]+$/D', $code) ? $code : 'II_OWNER_BOOTSTRAP_FAILED');
            return self::FAILURE;
        }
        $this->info('II_OWNER_BOOTSTRAP=PASS; OWNER_ID='.$owner.'; DISCOVERY=DISABLED; ACQUISITION=DISABLED');
        return self::SUCCESS;
    }
}
