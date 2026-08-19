<?php

namespace App\Services\Payments;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentSearchTokenService
{
    public function issue(string $username, string $password): string
    {
        $user = $this->resolveUser($username, $password);

        $payload = [
            'iss' => config('app.url', 'http://localhost:8000'),
            'sub' => (string) $user->id,
            'username' => $user->username,
            'email' => $user->email ?? null,
            'iat' => time(),
            'nbf' => time(),
            'exp' => time() + 900,
        ];

        return JWT::encode($payload, config('app.key'), 'HS256');
    }

    public function validate(string $token): object
    {
        try {
            $decoded = JWT::decode($token, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('Token inválido.', 0, $e);
        }

        $userId = (int) ($decoded->sub ?? 0);

        if ($userId <= 0) {
            throw new InvalidArgumentException('Token inválido.');
        }

        $table = Schema::hasTable('auth_user') ? 'auth_user' : 'users';
        $user = DB::table($table)->find($userId);

        if (! $user) {
            throw new InvalidArgumentException('Token inválido.');
        }

        return $user;
    }

    protected function resolveUser(string $username, string $password): object
    {
        $table = Schema::hasTable('auth_user') ? 'auth_user' : 'users';
        $candidate = strtolower(trim($username));

        $user = DB::table($table)
            ->where(function ($query) use ($candidate) {
                $query->whereRaw('LOWER(username) = ?', [$candidate])
                    ->orWhereRaw('LOWER(email) = ?', [$candidate]);
            })
            ->first();

        if (! $user) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        $storedPassword = (string) ($user->password ?? '');
        $matches = false;

        if ($storedPassword !== '') {
            try {
                $matches = Hash::check($password, $storedPassword);
            } catch (\RuntimeException $e) {
                $matches = false;
            }

            if (! $matches) {
                $matches = hash_equals($storedPassword, $password);
            }
        }

        if (! $matches) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        return $user;
    }
}
