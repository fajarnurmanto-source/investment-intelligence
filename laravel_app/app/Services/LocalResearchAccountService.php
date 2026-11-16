<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class LocalResearchAccountService
{
    public const ROLES = ['analyst', 'reviewer', 'admin'];

    public function find(string $email): ?User
    {
        return User::query()->where('email', $this->email($email))->first();
    }

    public function create(string $email, string $password, string $role): User
    {
        $email = $this->email($email);
        $role = $this->role($role);
        $this->password($password);
        if ($this->find($email) !== null) {
            throw new \InvalidArgumentException('Account already exists; refusing to overwrite credentials.');
        }

        return DB::transaction(function () use ($email, $password, $role): User {
            $user = User::query()->create([
                'name' => 'II Research Account', 'email' => $email, 'password' => Hash::make($password),
            ]);
            $user->forceFill(['ii_research_role' => $role])->save();

            return $user->fresh();
        });
    }

    public function resetPassword(string $email, string $password): User
    {
        $this->password($password);
        $user = $this->find($email) ?? throw new \InvalidArgumentException('Account does not exist.');

        return DB::transaction(function () use ($user, $password): User {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => null])->save();
            if (DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            return $user->fresh();
        });
    }

    public function setRole(string $email, string $role): User
    {
        $user = $this->find($email) ?? throw new \InvalidArgumentException('Account does not exist.');
        $user->forceFill(['ii_research_role' => $this->role($role)])->save();

        return $user->fresh();
    }

    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('A valid email is required.');
        }

        return $email;
    }

    private function role(string $role): string
    {
        $role = strtolower(trim($role));
        if (! in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Role must be analyst, reviewer, or admin.');
        }

        return $role;
    }

    private function password(string $password): void
    {
        $validator = Validator::make(['password' => $password], [
            'password' => ['required', 'string', Password::min(14)->mixedCase()->letters()->numbers()->symbols()],
        ]);
        if ($validator->fails()) {
            throw new \InvalidArgumentException('Password must be at least 14 characters with upper/lowercase letters, a number, and a symbol.');
        }
    }
}
