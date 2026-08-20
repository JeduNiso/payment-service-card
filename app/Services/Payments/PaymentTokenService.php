<?php

namespace App\Services\Payments;

use InvalidArgumentException;

class PaymentTokenService
{
    use ResolvesMerchantApiKey;

    public function __construct(
        private readonly PaymentSessionService $paymentSessionService,
        private readonly PaymentRouterService $paymentRouterService,
        private readonly PaymentPersistenceService $paymentPersistenceService,
    ) {
    }

    /**
     * $options may contain:
     *   - provider (?string)
     *   - language (?string) — "esp"/"eng", normalized to es/en, defaults to es.
     *   - description (?string) — shown in the checkout "Servicio" box.
     *   - redirect (bool) — auto-redirect to redirect_url after the receipt/error
     *     page instead of only offering it as the "back to home" link.
     *   - redirect_url (?string) — where to send the customer; validated/normalized
     *     to an http(s) URL at render time by CybersourceController.
     */
    public function issue(string $code, float $amount, string $currency, string $apiKey, array $options = []): array
    {
        $user = $this->resolveUserByApiKey($apiKey);

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

        $provider = $this->paymentRouterService->resolveProvider($options['provider'] ?? null, $user->username ?? null);
        $description = $options['description'] ?? null;
        $description = is_string($description) && trim($description) !== '' ? trim($description) : null;
        $redirectUrl = $options['redirect_url'] ?? null;
        $redirectUrl = is_string($redirectUrl) && trim($redirectUrl) !== '' ? trim($redirectUrl) : null;

        $token = $this->paymentSessionService->store($code, [
            'code' => strtoupper($code),
            'amount' => (float) $amount,
            'currency' => $currency,
            'user_id' => $user->id,
            'user' => $user->username,
            'email' => $user->email ?? null,
            'provider' => $provider,
            'language' => $this->normalizeLanguage($options['language'] ?? null),
            'description' => $description,
            'auto_redirect' => (bool) ($options['redirect'] ?? false),
            'redirect_url' => $redirectUrl,
        ], 15);

        return [
            'token' => $token,
            'url' => url('/payments/' . rawurlencode($token)),
            'expires_in' => 900,
        ];
    }

    /**
     * Normalizes the caller-supplied `language` ("esp"/"eng", case-insensitive, plus a
     * few obvious variants) to the two-letter locale this app ships translations for.
     * Anything unrecognized (including no value at all) falls back to Spanish, since
     * that's this service's original/default language.
     */
    private function normalizeLanguage(?string $language): string
    {
        $normalized = strtolower(trim((string) $language));

        return match ($normalized) {
            'eng', 'en', 'english' => 'en',
            default => 'es',
        };
    }
}
