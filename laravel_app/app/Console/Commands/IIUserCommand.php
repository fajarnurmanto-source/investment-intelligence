<?php

namespace App\Console\Commands;

use App\Services\LocalResearchAccountService;
use Illuminate\Console\Command;

class IIUserCommand extends Command
{
    protected $signature = 'ii:user {action=status : status|create|reset-password|set-role} {--email=} {--role=analyst}';

    protected $description = 'Safely inspect or manage a local II research account without changing ownership data';

    public function handle(LocalResearchAccountService $accounts): int
    {
        $action = strtolower((string) $this->argument('action'));
        $email = (string) ($this->option('email') ?: $this->ask('Research account email'));
        try {
            if ($action === 'status') {
                $user = $accounts->find($email);
                $this->line('II_ACCOUNT_EXISTS='.($user ? 'YES' : 'NO'));
                if ($user) {
                    $this->line('II_ACCOUNT_ROLE='.strtoupper((string) $user->ii_research_role));
                    $this->line('II_ACCOUNT_ID='.$user->id);
                }

                return $user ? self::SUCCESS : self::FAILURE;
            }
            if ($action === 'create') {
                $user = $accounts->create($email, $this->password(), (string) $this->option('role'));
                $this->info('II_ACCOUNT=CREATED;ID='.$user->id.';ROLE='.strtoupper((string) $user->ii_research_role));

                return self::SUCCESS;
            }
            if ($action === 'reset-password') {
                $user = $accounts->resetPassword($email, $this->password());
                $this->info('II_ACCOUNT_PASSWORD=RESET;ID='.$user->id.';SESSIONS=INVALIDATED;OWNERSHIP=PRESERVED');

                return self::SUCCESS;
            }
            if ($action === 'set-role') {
                $user = $accounts->setRole($email, (string) $this->option('role'));
                $this->info('II_ACCOUNT_ROLE=UPDATED;ID='.$user->id.';ROLE='.strtoupper((string) $user->ii_research_role));

                return self::SUCCESS;
            }
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->error('Action must be status, create, reset-password, or set-role.');

        return self::FAILURE;
    }

    private function password(): string
    {
        $first = (string) $this->secret('New password');
        $second = (string) $this->secret('Confirm new password');
        if (! hash_equals($first, $second)) {
            throw new \InvalidArgumentException('Password confirmation does not match.');
        }

        return $first;
    }
}
