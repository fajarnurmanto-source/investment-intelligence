<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Native Artisan health check: permanent replacement for disposable diagnosis
 * snippets. Never prints credentials, modifies a database, or requests SEC.
 */
class IIEnvironmentDoctor extends Command
{
    protected $signature = 'ii:doctor {--email= : Optional local account to inspect} {--strict : Return nonzero if production prerequisites fail}';

    protected $description = 'Inspect canonical II SQLite, user-scoped data and SEC HTTPS CA readiness without writes';

    public function handle(): int
    {
        $failed = false;
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $this->line('II_DOCTOR_DATABASE_DRIVER='.$driver);
        if ($driver !== 'sqlite') {
            $this->error('II_DOCTOR_DATABASE=FAIL;EXPECTED=sqlite');

            return self::FAILURE;
        }

        try {
            $integrity = $connection->selectOne('PRAGMA quick_check');
            $value = (string) (array_values((array) $integrity)[0] ?? '');
        } catch (\Throwable $e) {
            $value = 'ERROR';
        }
        $this->line('II_DOCTOR_DATABASE_INTEGRITY='.$value);
        $failed = $failed || $value !== 'ok';

        if (! Schema::hasTable('users')) {
            $this->error('II_DOCTOR_USERS_TABLE=MISSING');

            return self::FAILURE;
        }
        $this->line('II_DOCTOR_USERS_TABLE=PASS');

        $docsTable = Schema::hasTable('ii_issuer_documents');
        $globalCount = $docsTable ? DB::table('ii_issuer_documents')->count() : 0;
        $this->line('II_DOCTOR_GLOBAL_DOCUMENTS='.$globalCount);
        $this->line('II_DOCTOR_DATA_SCOPE=PER_USER');

        $email = strtolower(trim((string) $this->option('email')));
        if ($email !== '') {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('II_DOCTOR_EMAIL=INVALID');

                return self::FAILURE;
            }
            $user = User::query()->where('email', $email)->first();
            $this->line('II_DOCTOR_ACCOUNT_EXISTS='.($user !== null ? 'YES' : 'NO'));
            if ($user === null) {
                $failed = true;
            } else {
                $role = strtolower((string) ($user->ii_research_role ?? ''));
                $validRole = in_array($role, ['analyst', 'reviewer', 'admin'], true);
                $this->line('II_DOCTOR_ACCOUNT_ROLE='.($validRole ? strtoupper($role) : 'INVALID'));
                $this->line('II_DOCTOR_ACCOUNT_DOCUMENTS='.($docsTable
                    ? DB::table('ii_issuer_documents')->where('user_id', $user->id)->count()
                    : 0));
                $this->line('II_DOCTOR_ACCOUNT_PERIODS='.(Schema::hasTable('ii_issuer_periods')
                    ? DB::table('ii_issuer_periods')->where('user_id', $user->id)->count()
                    : 0));
                $failed = $failed || ! $validRole;
            }
            // Deliberately never copy other accounts' data into this owner.
        }

        $runtime = (string) (getenv('II_RUNTIME_ROOT') ?: '');
        if ($runtime !== '' && PHP_OS_FAMILY === 'Windows') {
            $pem = rtrim($runtime, "\\/ ").DIRECTORY_SEPARATOR.'certs'.DIRECTORY_SEPARATOR.'cacert.pem';
            $caReady = is_file($pem) && is_readable($pem) && filesize($pem) > 100000;
            $this->line('II_DOCTOR_SEC_CA='.($caReady ? 'PASS' : 'MISSING'));
            $failed = $failed || ! $caReady;
        } else {
            $this->line('II_DOCTOR_SEC_CA=NOT_APPLICABLE');
        }

        $this->line('II_DOCTOR_NO_DATABASE_WRITES=YES');
        $this->line('II_DOCTOR_RESULT='.($failed ? 'ATTENTION_REQUIRED' : 'PASS'));

        return $failed && (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
