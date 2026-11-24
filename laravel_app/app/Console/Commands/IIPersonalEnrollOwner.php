<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IIPersonalEnrollOwner extends Command
{
    protected $signature = 'ii:prt:enroll-owner {--confirm-single-owner : Acknowledge local-only one-owner enrollment}';

    protected $description = 'Interactively enroll the first personal terminal owner in an isolated, migrated local database';

    public function handle(): int
    {
        // This command is CLI-only. No web registration endpoint is added.
        if (! $this->option('confirm-single-owner')) {
            $this->error('II_PRT_OWNER_CONFIRMATION_REQUIRED');
            return self::FAILURE;
        }
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'ii_research_role')) {
            $this->error('II_PRT_MIGRATION_REQUIRED');
            return self::FAILURE;
        }
        if (User::query()->exists()) {
            $this->error('II_PRT_OWNER_ALREADY_ENROLLED_OR_DATABASE_NOT_EMPTY');
            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Owner name'));
        $email = strtolower(trim((string) $this->ask('Owner email')));
        $password = (string) $this->secret('Owner password (minimum 14 characters)');
        $confirm = (string) $this->secret('Repeat owner password');
        if ($name === '' || strlen($name) > 200
            || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
            || strlen($password) < 14 || ! hash_equals($password, $confirm)) {
            $this->error('II_PRT_OWNER_INPUT_INVALID');
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($name, $email, $password): void {
                // Serializes the SQLite initial write; UNIQUE(email) is a
                // secondary guard. No default/demo admin is created.
                if (User::query()->exists()) {
                    throw new \RuntimeException('II_PRT_OWNER_ALREADY_PRESENT');
                }
                $owner = new User();
                $owner->forceFill([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password, // hashed model cast
                    'ii_research_role' => 'admin',
                ])->saveOrFail();
            }, 1);
        } catch (\Throwable $e) {
            $this->error('II_PRT_OWNER_ENROLLMENT_FAILED');
            return self::FAILURE;
        } finally {
            unset($password, $confirm);
        }
        $this->info('II_PRT_OWNER_ENROLLED=YES;TRADING_AUTHORITY=NONE');
        return self::SUCCESS;
    }
}
