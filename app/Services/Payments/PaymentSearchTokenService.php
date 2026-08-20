<?php

namespace App\Services\Payments;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentSearchTokenService
{
    use ResolvesMerchantApiKey;

    public function issue(string $apiKey): string
    {
        $user = $this->resolveUserByApiKey($apiKey);

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
}
