<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class PaymentSessionService
{
    public function store(string $code, array $payload, int $minutes = 15): string
    {
        $token = $this->generateOpaqueToken();
        $expiresAt = now()->addMinutes($minutes);

        $session = [
            'payment_session_token' => $token,
            'code' => strtoupper((string) $code),
            'status' => $payload['status'] ?? 'pending',
            'expires_at' => $expiresAt->getTimestamp(),
            ...$payload,
        ];

        Cache::put($this->cacheKey($token), $session, $expiresAt);

        return $token;
    }

    public function get(string $token): array
    {
        return $this->resolve($token, false);
    }

    public function consume(string $token): array
    {
        return $this->resolve($token, true);
    }

    protected function resolve(string $token, bool $consume): array
    {
        $token = trim((string) $token);

        if ($token === '' || ! $this->isValidOpaqueTokenFormat($token)) {
            throw new InvalidArgumentException('The payment session token is invalid.');
        }

        $cacheKey = $this->cacheKey($token);
        $payload = Cache::get($cacheKey);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The payment session is not available anymore.');
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($expiresAt <= time()) {
            Cache::forget($cacheKey);
            throw new InvalidArgumentException('The payment session has expired.');
        }

        $status = strtolower((string) ($payload['status'] ?? 'pending'));

        if (in_array($status, ['cancelled', 'paid', 'completed', 'failed'], true)) {
            Cache::forget($cacheKey);
            throw new InvalidArgumentException('The payment session is not available for payment.');
        }

        if ($consume) {
            Cache::forget($cacheKey);
        }

        return $payload;
    }

    public function forget(string $token): void
    {
        $token = trim((string) $token);

        if ($token !== '') {
            Cache::forget($this->cacheKey($token));
        }
    }

    public function forgetByCode(string $code): void
    {
        $normalizedCode = strtoupper(trim((string) $code));
        if ($normalizedCode === '') {
            return;
        }

        $token = Cache::get('payment_token_for_code:' . $normalizedCode);
        if (is_string($token) && $token !== '') {
            $this->forget($token);
        }

        Cache::forget('payment_token_for_code:' . $normalizedCode);
        Cache::forget('payment_reference_id_for_code:' . $normalizedCode);
        Cache::forget('payment_session_data:' . $normalizedCode);
    }

    protected function generateOpaqueToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected function isValidOpaqueTokenFormat(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $token) === 1;
    }

    protected function cacheKey(string $token): string
    {
        return 'payment_session:' . hash('sha256', $token);
    }
}
