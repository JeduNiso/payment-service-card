<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentRouterService;
use App\Services\Payments\PaymentSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

class PaymentCheckoutController extends Controller
{
    public function __construct(
        private readonly PaymentSessionService $paymentSessionService,
        private readonly PaymentRouterService $paymentRouterService,
    ) {
    }

    public function show(string $token): View
    {
        $payment = $this->getPaymentSession($token);

        if ($payment === null) {
            return view('payment.checkout', [
                'token' => $token,
                'valid' => false,
                'error' => 'La sesión de pago ya no es válido o ha expirado.',
            ]);
        }

        $bookingCode = strtoupper((string) ($payment['code'] ?? ''));

        return view('payment.checkout', [
            'token' => $token,
            'valid' => true,
            'booking' => [
                'code' => $bookingCode,
                'amount' => (float) ($payment['amount'] ?? $payment['total_amount'] ?? 0),
                'currency' => $payment['currency'] ?? 'BOB',
            ],
            'payment' => $payment,
        ]);
    }

    public function submit(string $token, Request $request)
    {
        $payment = $this->getPaymentSession($token);

        if ($payment === null) {
            return view('payment.checkout', [
                'token' => $token,
                'valid' => false,
                'error' => 'La sesión de pago ya no es válido o ha expirado.',
            ]);
        }

        $bookingCode = strtoupper((string) ($payment['code'] ?? ''));

        if ($bookingCode === '') {
            return back()->withInput()->with('payment_error', 'No se pudo recuperar el código de la reserva.');
        }

        $provider = $this->paymentRouterService->resolveProvider(
            $payment['provider'] ?? null,
            $payment['user'] ?? null,
        );
        $controller = $this->paymentRouterService->resolveController($provider);

        $response = $controller->pay($bookingCode, $request, $payment);

        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
            $message = is_array($payload) ? ($payload['message'] ?? 'No se pudo completar el pago.') : 'No se pudo completar el pago.';

            return redirect()->back()->withInput()->with('payment_error', $message);
        }

        return $response;
    }

    private function getPaymentSession(string $token): ?array
    {
        try {
            return $this->paymentSessionService->get($token);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

}
