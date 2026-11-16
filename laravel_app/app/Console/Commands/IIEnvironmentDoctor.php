<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RuntimeCaBundleService;
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
        $this->line('II_DOCTOR_USERS='.User::query()->count());
        $this->line('II_DOCTOR_APP_KEY='.((string) config('app.key') !== '' ? 'PRESENT' : 'MISSING'));
        $failed = $failed || (string) config('app.key') === '';
        $this->line('II_DOCTOR_SESSION_DRIVER='.strtoupper((string) config('session.driver')));
        $this->line('II_DOCTOR_QUEUE_CONNECTION='.strtoupper((string) config('queue.default')));
        $this->line('II_DOCTOR_CACHE_STORE='.strtoupper((string) config('cache.default')));

        $docsTable = Schema::hasTable('ii_issuer_documents');
        $globalCount = $docsTable ? DB::table('ii_issuer_documents')->count() : 0;
        $this->line('II_DOCTOR_GLOBAL_DOCUMENTS='.$globalCount);
        $this->line('II_DOCTOR_DATA_SCOPE=PER_USER');
        $orphanDocuments = $docsTable ? DB::table('ii_issuer_documents AS d')
            ->leftJoin('users AS u', 'd.user_id', '=', 'u.id')->whereNull('u.id')->count() : 0;
        $this->line('II_DOCTOR_ORPHAN_DOCUMENTS='.$orphanDocuments);
        $failed = $failed || $orphanDocuments > 0;

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
                $this->line('II_DOCTOR_ACCOUNT_PERIODS='.(Schema::hasTable('ii_issuer_periods') && $docsTable
                    ? DB::table('ii_issuer_periods AS p')
                        ->join('ii_issuer_documents AS d', 'p.document_id', '=', 'd.id')
                        ->where('d.user_id', $user->id)->count()
                    : 0));
                $failed = $failed || ! $validRole;
            }
            // Deliberately never copy other accounts' data into this owner.
        }

        $runtime = (string) (getenv('II_RUNTIME_ROOT') ?: '');
        if ($runtime !== '' && PHP_OS_FAMILY === 'Windows') {
            $ca = RuntimeCaBundleService::inspect($runtime);
            $this->line('II_DOCTOR_SEC_CA='.($ca['ready'] ? 'PASS' : $ca['reason']));
            $this->line('II_DOCTOR_SEC_CA_CERTIFICATES='.$ca['certificate_count']);
            $failed = $failed || ! $ca['ready'];
        } else {
            $this->line('II_DOCTOR_SEC_CA=NOT_APPLICABLE');
        }

        $this->line('II_DOCTOR_NO_DATABASE_WRITES=YES');
        $this->line('II_DOCTOR_RESULT='.($failed ? 'ATTENTION_REQUIRED' : 'PASS'));

        return $failed && (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
