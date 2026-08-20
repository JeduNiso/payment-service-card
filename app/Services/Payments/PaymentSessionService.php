<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class PaymentSessionService
{
    public function __construct(
        private ?PaymentPersistenceService $paymentPersistenceService = null,
    ) {
        $this->paymentPersistenceService ??= app(PaymentPersistenceService::class);
    }

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

        $cacheKey = $this->cacheKey($token);
        Log::info('Storing payment session', ['token' => $token, 'cacheKey' => $cacheKey, 'expiresAt' => $expiresAt]);
        $result = Cache::put($cacheKey, $session, $expiresAt);
        Log::info('Cache put result', ['token' => $token, 'result' => $result, 'cacheStore' => config('cache.default')]);

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
        Log::info('Resolving payment session', ['token' => $token, 'cacheKey' => $cacheKey]);
        $payload = Cache::get($cacheKey);
        Log::info('Cache get result', ['token' => $token, 'found' => $payload !== null, 'cacheStore' => config('cache.default')]);

        if (! is_array($payload)) {
            throw new InvalidArgumentException('The payment session is not available anymore.');
        }

        $expiresAt = (int) ($payload['expires_at'] ?? 0);

        if ($expiresAt <= time()) {
            Cache::forget($cacheKey);
            throw new InvalidArgumentException('The payment session has expired.');
        }

        // The persisted redirect_payment record is the durable source of truth: once a
        // result (paid or error) is recorded for this code, the session must stop
        // resolving — even if some step of the payment flow never explicitly forgot
        // this exact cache key (e.g. the original checkout-URL token is distinct from
        // the internal session token minted once card details are submitted, and only
        // the latter gets forgotten today).
        $code = (string) ($payload['code'] ?? '');

        if ($code !== '' && $this->paymentPersistenceService->findByCode($code) !== null) {
            Cache::forget($cacheKey);
            throw new InvalidArgumentException('The payment session is not available anymore.');
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
