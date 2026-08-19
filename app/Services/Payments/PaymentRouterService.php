<?php

namespace App\Services\Payments;

class PaymentRouterService
{
    public function __construct(
        private readonly LegacyUserPermissionProvider $legacyUserPermissionProvider = new MockLegacyUserPermissionProvider(),
    ) {
    }

    public function resolveProvider(?string $provider = null, ?string $username = null): string
    {
        $provider = strtolower(trim((string) ($provider ?? '')));

        if ($provider !== '' && $this->isSupportedProvider($provider)) {
            return $provider;
        }

        $userProvider = $this->resolveProviderByUser($username);

        if ($userProvider !== null) {
            return $userProvider;
        }

        return 'cybersource';
    }

    public function resolveController(string $provider)
    {
        return match ($provider) {
            'cybersource' => app(\App\Http\Controllers\Api\CybersourceController::class),
            default => app(\App\Http\Controllers\Api\CybersourceController::class),
        };
    }

    protected function isSupportedProvider(string $provider): bool
    {
        return in_array($provider, ['cybersource'], true);
    }

    protected function resolveProviderByUser(?string $username): ?string
    {
        if ($username === null || $username === '') {
            return null;
        }

        $lookup = strtolower(trim((string) $username));

        $mapping = config('services.payment_providers_map', []);
        if (is_array($mapping)) {
            $provider = $mapping[$lookup] ?? null;
            if (is_string($provider)) {
                $provider = strtolower(trim($provider));

                return $this->isSupportedProvider($provider) ? $provider : null;
            }
        }

        $permissions = $this->legacyUserPermissionProvider->getUserPermissions($lookup);
        if (! is_array($permissions)) {
            return null;
        }

        $provider = strtolower(trim((string) ($permissions['provider'] ?? '')));
        $status = strtolower(trim((string) ($permissions['status'] ?? 'inactive')));

        if ($provider === '' || ! $this->isSupportedProvider($provider)) {
            return null;
        }

        if ($status !== 'active') {
            return null;
        }

        return $provider;
    }
}
