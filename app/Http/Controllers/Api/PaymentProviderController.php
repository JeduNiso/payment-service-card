<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;

abstract class PaymentProviderController extends Controller
{
    abstract public function providerName(): string;

    protected function resolveBooking(string $code): Booking
    {
        $normalizedCode = strtoupper((string) $code);
        $sessionData = cache()->get('payment_session_data:' . $normalizedCode);
        $sessionToken = is_array($sessionData) ? ($sessionData['payment_session_token'] ?? null) : null;

        if (is_string($sessionToken) && $sessionToken !== '') {
            try {
                $paymentData = app(\App\Services\Payments\PaymentSessionService::class)->get($sessionToken);
                $booking = $this->buildTemporaryBooking($normalizedCode, $paymentData, $paymentData);
                $booking->cybersource_reference_id = cache()->get('payment_reference_id_for_code:' . $normalizedCode);
                $booking->cybersource_payment_data = $paymentData;

                return $booking;
            } catch (\Throwable $e) {
                logger()->warning('No booking lookup was performed; payment session fallback used instead.', [
                    'code' => $normalizedCode,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->buildTemporaryBooking($normalizedCode, [
            'code' => $normalizedCode,
            'amount' => 0,
            'currency' => 'BOB',
        ], []);
    }

    protected function resolvePaymentSessionBooking(string $code, ?string $paymentSessionToken = null): Booking
    {
        $sessionMeta = cache()->get('payment_session_data:' . strtoupper((string) $code));
        $sessionToken = is_string($paymentSessionToken) && $paymentSessionToken !== ''
            ? $paymentSessionToken
            : (is_array($sessionMeta) ? ($sessionMeta['payment_session_token'] ?? null) : null);

        if (is_string($sessionToken) && $sessionToken !== '') {
            try {
                $paymentData = app(\App\Services\Payments\PaymentSessionService::class)->get($sessionToken);

                $booking = $this->buildTemporaryBooking($code, $paymentData, $paymentData);
                $booking->cybersource_reference_id = cache()->get('payment_reference_id_for_code:' . strtoupper((string) $code));
                $booking->cybersource_payment_data = $paymentData;

                return $booking;
            } catch (\Throwable $e) {
                logger()->warning('Falling back to legacy booking resolution after payment-session lookup failed.', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->resolveBooking($code);
    }

    protected function buildTemporaryBooking(string $code, array $paymentSession, array $data): Booking
    {
        $booking = new Booking();
        $booking->booking_code = strtoupper((string) $code);
        $booking->total_amount = (float) ($paymentSession['amount'] ?? 0);
        $booking->status = 'pending';
        $booking->contact_email = $data['billing_email'] ?? ($paymentSession['billing_email'] ?? null);
        $booking->passengers = collect();
        $booking->schedule = null;
        $booking->cybersource_reference_id = null;
        $booking->cybersource_payment_data = null;

        return $booking;
    }

    protected function resolveAuthenticationFailure(array $payload, string $defaultMessage): array
    {
        return [
            'status' => $payload['status'] ?? 'UNKNOWN',
            'message' => $payload['errorInformation']['message']
                ?? $payload['message']
                ?? $defaultMessage,
            'code' => $payload['errorInformation']['reason']
                ?? $payload['reason']
                ?? ($payload['status'] ?? 'UNKNOWN'),
        ];
    }
}
