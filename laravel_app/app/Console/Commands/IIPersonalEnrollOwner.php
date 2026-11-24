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
        // Outside PHPUnit, enrollment is restricted to a deliberately
        // initialized II PRT device workspace, never an arbitrary database.
        if (! app()->environment('testing')) {
            $root = getenv('II_RUNTIME_ROOT');
            if (! is_string($root) || trim($root) === '') {
                $this->error('II_PRT_DEVICE_ROOT_REQUIRED');
                return self::FAILURE;
            }
            $metadataPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'device.json';
            $metadata = is_file($metadataPath) ? json_decode((string) file_get_contents($metadataPath), true) : null;
            if (! is_array($metadata)
                || ($metadata['schema'] ?? '') !== 'ii.prt.device-bootstrap.v1'
                || ($metadata['database_migrated'] ?? false) !== true
                || ($metadata['owner_enrolled'] ?? true) !== false) {
                $this->error('II_PRT_DEVICE_MIGRATION_OR_IDENTITY_NOT_READY');
                return self::FAILURE;
            }
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
