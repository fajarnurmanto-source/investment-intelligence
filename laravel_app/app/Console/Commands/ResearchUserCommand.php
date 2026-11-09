<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResearchUserCommand extends Command
{
    protected $signature = 'ii:research-user {--email=} {--role=reviewer}';
    protected $description = 'Create a local II synthetic research user via secure password prompt';
    public function handle(): int
    {
        $email = (string) ($this->option('email') ?: $this->ask('Research user email'));
        $role = (string) $this->option('role');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['analyst','reviewer','admin'], true)) {
            $this->error('Valid email and analyst/reviewer/admin role required.'); return self::FAILURE;
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            $this->error('User already exists. Refusing to overwrite credentials.'); return self::FAILURE;
        }
        $password = (string) $this->secret('New password (minimum 12 characters)');
        if (mb_strlen($password) < 12) { $this->error('Password too short.'); return self::FAILURE; }
        DB::table('users')->insert(['name'=>'II Research '.Str::random(6),'email'=>$email,'password'=>Hash::make($password),'ii_research_role'=>$role,'created_at'=>now(),'updated_at'=>now()]);
        $this->info('II_VS6_RESEARCH_USER=CREATED;ROLE='.$role); return self::SUCCESS;
    }
}
