<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentTokenService
{
    public function __construct(
        private readonly PaymentSessionService $paymentSessionService,
        private readonly PaymentRouterService $paymentRouterService,
        private readonly PaymentPersistenceService $paymentPersistenceService,
    ) {
    }

    public function issue(string $code, float $amount, string $currency, string $username, string $password, ?string $provider = null): array
    {
        $user = $this->resolveUser($username, $password);

        $code = trim((string) $code);
        $currency = strtoupper(trim($currency));

        if ($code === '' || $amount <= 0 || $currency === '') {
            throw new InvalidArgumentException('code, amount y currency son obligatorios.');
        }

        // A `code` is good for a single payment attempt. Once a result (paid or error)
        // is persisted for it, no new session token may be issued for that same code —
        // the caller must obtain a new code for a fresh attempt.
        if ($this->paymentPersistenceService->findByCode($code) !== null) {
            throw new InvalidArgumentException('Este código ya tiene un pago registrado. Genere un nuevo código para un nuevo intento.');
        }

        $provider = $this->paymentRouterService->resolveProvider($provider, $user->username ?? $username);

        $token = $this->paymentSessionService->store($code, [
            'code' => strtoupper($code),
            'amount' => (float) $amount,
            'currency' => $currency,
            'user_id' => $user->id,
            'user' => $user->username,
            'email' => $user->email ?? null,
            'provider' => $provider,
        ], 15);

        return [
            'token' => $token,
            'url' => url('/payments/' . rawurlencode($token)),
            'expires_in' => 900,
        ];
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

            // Only fall back to a raw string comparison for legacy accounts whose
            // `password` column never held a real hash (e.g. plaintext migrated from
            // another system). If $storedPassword IS a recognized hash, a raw compare
            // would let anyone who has leaked the hash authenticate with it directly
            // instead of the real password — never allow that.
            if (! $matches && (Hash::info($storedPassword)['algoName'] ?? 'unknown') === 'unknown') {
                $matches = hash_equals($storedPassword, $password);
            }
        }

        if (! $matches) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        return $user;
    }
}
