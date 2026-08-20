<?php

namespace App\Services\Commerce;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CommerceNotificationService
{
    public function notifyPaymentSuccess(array $paymentData, array $paymentResult): array
    {
        return $this->notifyMany('success', $paymentData, $paymentResult);
    }

    public function notifyPaymentError(array $paymentData, array $paymentResult, string $reason = 'payment_failed'): array
    {
        return $this->notifyMany('error', $paymentData, $paymentResult, $reason);
    }

    public function notifyMany(string $type, array $paymentData, array $paymentResult, string $reason = 'payment_failed'): array
    {
        $urls = $this->resolveEndpoints($type, $paymentData);
        $apiKey = $this->resolveApiKeyForCustomer($paymentData);

        if ($urls === []) {
            return [
                'sent' => false,
                'type' => $type,
                'reason' => 'No merchant notification endpoints configured.',
            ];
        }

        $payload = [
            'payment_code' => $paymentData['code'] ?? $paymentData['payment_code'] ?? null,
            'book_code' => $paymentData['code'] ?? $paymentData['book_code'] ?? null,
            'status' => $type === 'success' ? 'AUTHORIZED' : 'ERROR',
            'reason' => $type === 'success' ? 'payment_authorized' : $reason,
            'amount' => $paymentData['amount'] ?? 0,
            'currency' => $paymentData['currency'] ?? 'BOB',
            'transaction_id' => $paymentResult['id'] ?? $paymentResult['transactionId'] ?? null,
            'customer_email' => $paymentData['billing_email'] ?? $paymentData['email'] ?? null,
            'provider' => 'cybersource',
            'notified_at' => now()->toIso8601String(),
        ];

        $results = [];

        foreach ($urls as $url) {
            try {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                $results[] = [
                    'url' => $url,
                    'status' => $response->status(),
                    'ok' => $response->successful(),
                    'body' => $response->json(),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'url' => $url,
                    'status' => 0,
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'sent' => count(array_filter($results, fn ($item) => ($item['ok'] ?? false))) > 0,
            'type' => $type,
            'results' => $results,
        ];
    }

    protected function resolveEndpoints(string $type, array $paymentData): array
    {
        $baseUrls = [];

        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;
        if ($userId !== null && $userId !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $user = DB::table($userTable)->where('id', (int) $userId)->first();
            if ($user && ! empty($user->customer_url)) {
                $baseUrls[] = rtrim((string) $user->customer_url, '/');
            }
        }

        $customerUrl = $paymentData['customer_url'] ?? $paymentData['redirect_url'] ?? null;
        if (is_string($customerUrl) && trim($customerUrl) !== '') {
            $baseUrls[] = rtrim(trim($customerUrl), '/');
        }

        $defaultList = $type === 'success'
            ? config('services.merchant_notifications.success_endpoints', [])
            : config('services.merchant_notifications.error_endpoints', []);

        foreach ($defaultList as $endpoint) {
            if (is_string($endpoint) && trim($endpoint) !== '') {
                $baseUrls[] = rtrim(trim($endpoint), '/');
            }
        }

        $normalized = [];
        foreach ($baseUrls as $baseUrl) {
            $trimmed = trim((string) $baseUrl);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = rtrim($trimmed, '/') . '/api/webhooks/payments';
        }

        return array_values(array_unique($normalized));
    }

    protected function resolveApiKeyForCustomer(array $paymentData): string
    {
        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;

        if ($userId !== null && $userId !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $user = DB::table($userTable)->where('id', (int) $userId)->first();
            if ($user && ! empty($user->merchant_notification_api_key)) {
                return (string) $user->merchant_notification_api_key;
            }
        }

        $email = $paymentData['billing_email'] ?? $paymentData['email'] ?? null;
        if (is_string($email) && trim($email) !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $user = DB::table($userTable)->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->first();
            if ($user && ! empty($user->merchant_notification_api_key)) {
                return (string) $user->merchant_notification_api_key;
            }
        }

        return (string) config('services.merchant_notifications.api_key', '');
    }
}
