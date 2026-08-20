<?php

namespace App\Http\Controllers\Api;

use App\Models\Booking;
use App\Services\CyberSource\CyberSourceSanitizer;
use App\Services\CyberSource\CyberSourceService;
use App\Services\Payments\PaymentPersistenceService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class CybersourceController extends PaymentProviderController
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly CyberSourceService $cyberSourceService,
        private readonly PaymentSessionService $paymentSessionService,
        private ?PaymentPersistenceService $paymentPersistenceService = null,
    ) {
        $this->paymentPersistenceService ??= app(PaymentPersistenceService::class);
    }

    public function providerName(): string
    {
        return 'cybersource';
    }

    public function pay(string $code, Request $request, ?array $paymentSession = null): JsonResponse|Response
    {
        $paymentContext = $this->paymentService->buildPaymentContext($code);
        $context = $paymentContext['context'];
        logger()->info('Fetched seqNumber from DB', ['seqNum' => $paymentContext['seqnum']]);
        $data = $request->validate([
            'card_number'     => 'required|string',
            'cardholder_name' => 'required|string|max:100',
            'expiry_month'    => 'required|integer|min:1|max:12',
            'expiry_year'     => 'required|integer|min:2026',
            'cvv'             => 'required|string|min:3|max:4',
            'billing_first_name'        => 'nullable|string|max:60',
            'billing_last_name'         => 'nullable|string|max:60',
            'billing_address1'          => 'nullable|string|max:120',
            'billing_locality'          => 'nullable|string|max:60',
            'billing_administrative_area' => 'nullable|string|max:10',
            'billing_email'             => 'nullable|email|max:120',
            'billing_country'           => 'nullable|string|size:2',
            'billing_phone'             => 'nullable|string|max:30',
        ]);

        $paymentSession = is_array($paymentSession) ? $paymentSession : [
            'code' => strtoupper($code),
            'amount' => (float) ($request->input('amount', 0) ?: 0),
            'currency' => strtoupper((string) ($request->input('currency', 'BOB') ?: 'BOB')),
            'billing_email' => $data['billing_email'] ?? null,
        ];

        $booking = $this->buildTemporaryBooking($code, $paymentSession, $data);

        if (($booking->status ?? null) === 'confirmed') {
            return response()->json(['message' => 'Esta reserva ya fue pagada.'], 409);
        }

        if (($booking->status ?? null) === 'cancelled') {
            return response()->json(['message' => 'Esta reserva fue cancelada y no puede procesarse.'], 422);
        }

        // --- Validaciones de tarjeta ---
        $cardNumber = preg_replace('/\D/', '', $data['card_number']);

        if (!$this->luhnCheck($cardNumber)) {
            return response()->json([
                'message'      => 'El número de tarjeta no es válido.',
                'field'        => 'card_number',
                'decline_code' => 'invalid_number',
            ], 422);
        }

        if (!$this->isExpiryValid((int) $data['expiry_month'], (int) $data['expiry_year'])) {
            return response()->json([
                'message'      => 'La tarjeta está vencida.',
                'field'        => 'expiry',
                'decline_code' => 'expired_card',
            ], 422);
        }


        $authentication_setup = $this->cyberSourceService->authenticationSetup($data, $cardNumber);
        $authentication_setup_data = $authentication_setup->getData(true);
        $reference_id = $authentication_setup_data['consumerAuthenticationInformation']['referenceId'] ?? null;
        $accessToken = $authentication_setup_data['consumerAuthenticationInformation']['accessToken'] ?? null;
        $url = $authentication_setup_data['consumerAuthenticationInformation']['deviceDataCollectionUrl'] ?? null;

        if ($reference_id === null || $accessToken === null) {
            return response()->json([
                'message' => 'No se pudo obtener referenceId o accessToken desde authentication_setup.',
                'gateway_response' => $authentication_setup_data,
            ], 422);
        }

        cache()->put("3ds_ref_{$code}", $reference_id, now()->addMinutes(10));

        $sessionToken = $this->paymentSessionService->store($code, [
            'context' => $context,
            'card_number' => $cardNumber,
            'expiry_month' => str_pad((string) $data['expiry_month'], 2, '0', STR_PAD_LEFT),
            'expiry_year' => (string) $data['expiry_year'],
            'cvv' => $data['cvv'],
            'billing_first_name' => $data['billing_first_name'] ?? 'Roberto',
            'billing_last_name' => $data['billing_last_name'] ?? 'Jimenez',
            'billing_address1' => $data['billing_address1'] ?? 'Calle Herber 123',
            'billing_locality' => $data['billing_locality'] ?? 'La Paz',
            'billing_administrative_area' => $data['billing_administrative_area'] ?? 'L',
            'billing_country' => $data['billing_country'] ?? 'BO',
            'billing_phone' => $data['billing_phone'] ?? '65612459',
            'billing_email' => $data['billing_email'] ?? ($paymentSession['billing_email'] ?? null),
            'amount' => (float) ($paymentSession['amount'] ?? 0),
            'currency' => strtoupper((string) ($paymentSession['currency'] ?? 'BOB')),
            'user_id' => $paymentSession['user_id'] ?? $paymentSession['auth_user_id'] ?? $paymentSession['customer_id'] ?? null,
            'user' => $paymentSession['user'] ?? null,
            'email' => $paymentSession['email'] ?? ($data['billing_email'] ?? null),
            'status' => 'pending',
            'language' => $paymentSession['language'] ?? 'es',
            'description' => $paymentSession['description'] ?? null,
        ], 15);

        cache()->put("payment_token_for_code:{$code}", $sessionToken, now()->addMinutes(15));
        cache()->put("payment_reference_id_for_code:{$code}", $reference_id, now()->addMinutes(15));
        cache()->put("payment_session_data:{$code}", [
            'payment_session_token' => $sessionToken,
            'context' => $context,
        ], now()->addMinutes(15));

        return $this->render_collection_html($code, $reference_id, $accessToken, $url, $context);
    }

    public function enrollment_callback(string $code, Request $request): JsonResponse|Response|\Illuminate\Http\RedirectResponse
    {
        try {
            DB::table('seguence')->orderByDesc('seqNumber')->limit(1)->update(['seqNumber' => DB::raw('seqNumber + 1')]);
        } catch (Throwable $e) {
            logger()->error('No se pudo actualizar seqNumber', ['error' => $e->getMessage()]);
        }
        $paymentSessionToken = null;
        $sessionMeta = cache()->get('payment_session_data:' . strtoupper((string) $code));

        if (is_array($sessionMeta)) {
            $paymentSessionToken = $sessionMeta['payment_session_token'] ?? null;
        }

        $booking = $this->resolvePaymentSessionBooking($code, $paymentSessionToken);
        $reference_id = $booking->cybersource_reference_id;
        $paymentData = $booking->cybersource_payment_data;

        if ($reference_id === null || !is_array($paymentData)) {
            return response()->json([
                'message' => 'reference_id o datos de pago no encontrados.',
            ], 422);
        }

        $paymentSessionToken = $paymentData['payment_session_token'] ?? null;

        if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
            try {
                $paymentData = $this->paymentSessionService->get($paymentSessionToken);
            } catch (Throwable $e) {
                logger()->warning('Payment session token invalid in enrollment callback.', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'La sesión de pago expiró o fue alterada.',
                ], 422);
            }
        }

        app()->setLocale($paymentData['language'] ?? 'es');

        $browserInfo = [
            'acceptHeader'    => $request->query('browserAcceptHeader', 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'),
            'language'        => $request->query('browserLanguage', 'es-BO'),
            'userAgent'       => $request->query('browserUserAgent', $request->userAgent() ?? 'Mozilla/5.0'),
            'screenHeight'    => $request->query('browserScreenHeight', '768'),
            'screenWidth'     => $request->query('browserScreenWidth', '1366'),
            'javaEnabled'     => $request->query('browserJavaEnabled', 'false') === 'true',
        ];

        $enrollmentResponse = $this->cyberSourceService->enrollmentSetup($reference_id, $paymentData, $booking, $browserInfo);
        $enrollmentData = $enrollmentResponse->getData(true);
        logger()->info('ENROLLMENT_SETUP called', ['code' => $code, 'all_input' => $enrollmentData]);
        if (($enrollmentData['status'] ?? '') === 'AUTHENTICATION_SUCCESSFUL') {
            $paymentResponse = $this->cyberSourceService->paymentWith3dsDynamic($paymentData, $booking, $enrollmentData, 'NO_3DS', $paymentData['context'] ?? null);
            $paymentResult = $paymentResponse->getData(true);
            logger()->info('PaymentWith3dsDynamic called', ['code' => $code, 'all_input' => $paymentResult]);
            $paymentStatus = $paymentResult['status'] ?? 'UNKNOWN';

            if ($paymentStatus === 'AUTHORIZED') {
                $booking->update(['status' => 'confirmed']);
                $this->persistSuccessfulPayment($code, $paymentData, $paymentResult);

                try {
                    $this->notifyMerchantWithUserToken(
                        $paymentData,
                        'AUTHORIZED',
                        $booking->booking_code,
                        (float) ($paymentData['amount'] ?? 0),
                        (string) ($paymentData['currency'] ?? 'BOB')
                    );
                } catch (Throwable $e) {
                    logger()->warning('Merchant success notification failed', [
                        'code' => $code,
                        'error' => $e->getMessage(),
                    ]);
                }

                if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                    $this->paymentSessionService->forget($paymentSessionToken);
                }
                $this->paymentSessionService->forgetByCode($code);

                return response(
                    $this->render_invoice_html($booking, $paymentData, $paymentResult),
                    200,
                    ['Content-Type' => 'text/html; charset=UTF-8']
                );
            }

            $errorReason = $paymentResult['errorInformation']['message']
                ?? $paymentResult['message']
                ?? __('payment.error.default_processing_reason');
            $errorCode = $paymentResult['errorInformation']['reason']
                ?? $paymentResult['reason']
                ?? $paymentStatus;

            $customerUrl = $this->resolveCustomerUrl($paymentData);
            $this->persistPaymentRecord($code, $paymentData, $paymentResult, $customerUrl, 3);

            try {
                $this->notifyMerchantWithUserToken(
                    $paymentData,
                    'ERROR',
                    $booking->booking_code,
                    (float) ($paymentData['amount'] ?? 0),
                    (string) ($paymentData['currency'] ?? 'BOB'),
                    'payment_not_authorized'
                );
            } catch (Throwable $e) {
                logger()->warning('Merchant error notification failed', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }

            if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                $this->paymentSessionService->forget($paymentSessionToken);
            }
            $this->paymentSessionService->forgetByCode($code);

            return response(
                $this->render_error_html(
                    __('payment.error.title_processing'),
                    $errorReason,
                    $errorCode,
                    $booking->booking_code,
                    $this->resolveHomeUrlFromCustomerUrl($paymentData)
                ),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            );
        }

        if (($enrollmentData['status'] ?? '') === 'PENDING_AUTHENTICATION') {
            $stepUpUrl = $enrollmentData['consumerAuthenticationInformation']['stepUpUrl'] ?? 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/StepUp';
            $stepUpToken = $enrollmentData['consumerAuthenticationInformation']['accessToken'] ?? '';
            $authTransactionId = $enrollmentData['consumerAuthenticationInformation']['authenticationTransactionId'] ?? '';

            $booking->update([
                'cybersource_payment_data' => array_merge($paymentData, [
                    'authentication_transaction_id' => $authTransactionId,
                ]),
            ]);

            return $this->render_stepup_html($code, $stepUpUrl, $stepUpToken);
        }

        $authFailure = $this->resolveAuthenticationFailure($enrollmentData, __('payment.error.default_3ds_reason'));

        $customerUrl = $this->resolveCustomerUrl($paymentData);
        $this->persistPaymentRecord($code, $paymentData, $enrollmentData, $customerUrl, 3);

        try {
            $this->notifyMerchantWithUserToken(
                $paymentData,
                'ERROR',
                $booking->booking_code,
                (float) ($paymentData['amount'] ?? 0),
                (string) ($paymentData['currency'] ?? 'BOB'),
                '3ds_authentication_failed'
            );
        } catch (Throwable $e) {
            logger()->warning('Merchant error notification failed for 3DS failure', [
                'code' => $code,
                'error' => $e->getMessage(),
            ]);
        }

        if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
            $this->paymentSessionService->forget($paymentSessionToken);
        }

        return response(
            $this->render_error_html(
                __('payment.error.title_3ds_failed'),
                $authFailure['message'],
                $authFailure['code'],
                $booking->booking_code,
                $this->resolveHomeUrlFromCustomerUrl($paymentData)
            ),
            200,
            ['Content-Type' => 'text/html; charset=UTF-8']
        );
    }

    public function enrollment_result(string $code, Request $request): JsonResponse|Response|\Illuminate\Http\RedirectResponse
    {
        logger()->info('ENROLLMENT_RESULT called', ['code' => $code, 'all_input' => $request->all()]);

        $paymentSessionToken = null;
        $sessionMeta = cache()->get('payment_session_data:' . strtoupper((string) $code));

        if (is_array($sessionMeta)) {
            $paymentSessionToken = $sessionMeta['payment_session_token'] ?? null;
        }

        $booking = $this->resolvePaymentSessionBooking($code, $paymentSessionToken);
        $paymentData = $booking->cybersource_payment_data;
        logger()->info('Fetched payment session data', ['code' => $code, 'payment_data' => $this->sanitizePaymentDataForLog($paymentData)]);

        if (!is_array($paymentData)) {
            return response()->json(['message' => 'Datos de pago no encontrados.'], 422);
        }

        $paymentSessionToken = $paymentData['payment_session_token'] ?? null;

        if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
            try {
                $paymentData = $this->paymentSessionService->get($paymentSessionToken);
            } catch (Throwable $e) {
                logger()->warning('Payment session token invalid in enrollment result.', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'La sesión de pago expiró o fue alterada.',
                ], 422);
            }
        }

        app()->setLocale($paymentData['language'] ?? 'es');

        $authTransactionId = $paymentData['authentication_transaction_id'] ?? $request->input('TransactionId');

        if (!$authTransactionId) {
            return response()->json(['message' => 'authenticationTransactionId no encontrado.'], 422);
        }

        $validAuthResponse = $this->cyberSourceService->validAuthDynamic($authTransactionId, $paymentData, $booking);
        logger()->info('VALID_AUTH_DYNAMIC called', ['code' => $code, 'authTransactionId' => $authTransactionId]);
        $validAuthData = $validAuthResponse->getData(true);

        logger()->info('VALID_AUTH_DYNAMIC RESULT', $validAuthData);

        $status = $validAuthData['status'] ?? 'UNKNOWN';

        if ($status === 'AUTHENTICATION_SUCCESSFUL') {
            $paymentResponse = $this->cyberSourceService->paymentWith3dsDynamic($paymentData, $booking, $validAuthData, '3DS', $paymentData['context'] ?? null);
            $paymentResult = $paymentResponse->getData(true);

            logger()->info('PAYMENT_WITH_3DS_DYNAMIC RESULT', $paymentResult);

            $paymentStatus = $paymentResult['status'] ?? 'UNKNOWN';

            if ($paymentStatus === 'AUTHORIZED') {
                $booking->update(['status' => 'confirmed']);
                $this->persistSuccessfulPayment($code, $paymentData, $paymentResult);

                try {
                    $this->notifyMerchantWithUserToken(
                        $paymentData,
                        'AUTHORIZED',
                        $booking->booking_code,
                        (float) ($paymentData['amount'] ?? 0),
                        (string) ($paymentData['currency'] ?? 'BOB')
                    );
                } catch (Throwable $e) {
                    logger()->warning('Merchant success notification failed', [
                        'code' => $code,
                        'error' => $e->getMessage(),
                    ]);
                }

                if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                    $this->paymentSessionService->forget($paymentSessionToken);
                }
                $this->paymentSessionService->forgetByCode($code);

                $customerUrl = $this->resolveCustomerUrl($paymentData);
                $html = $this->render_invoice_html($booking, $paymentData, $paymentResult);
            } else {
                $errorReason = $paymentResult['errorInformation']['message']
                    ?? $paymentResult['message']
                    ?? __('payment.error.default_processing_reason');
                $errorCode = $paymentResult['errorInformation']['reason']
                    ?? $paymentResult['reason']
                    ?? $paymentStatus;

                $customerUrl = $this->resolveCustomerUrl($paymentData);
                $this->persistPaymentRecord($code, $paymentData, $paymentResult, $customerUrl, 3);

                try {
                    $this->notifyMerchantWithUserToken(
                        $paymentData,
                        'ERROR',
                        $booking->booking_code,
                        (float) ($paymentData['amount'] ?? 0),
                        (string) ($paymentData['currency'] ?? 'BOB'),
                        'payment_not_authorized'
                    );
                } catch (Throwable $e) {
                    logger()->warning('Merchant error notification failed', [
                        'code' => $code,
                        'error' => $e->getMessage(),
                    ]);
                }

                if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                    $this->paymentSessionService->forget($paymentSessionToken);
                }
                $this->paymentSessionService->forgetByCode($code);

                $html = $this->render_error_html(
                    __('payment.error.title_processing'),
                    $errorReason,
                    $errorCode,
                    $booking->booking_code,
                    $this->resolveHomeUrlFromCustomerUrl($paymentData)
                );
            }
        } else {
            $errorInfo = $this->resolveAuthenticationFailure($validAuthData, __('payment.error.default_3ds_reason'));

            if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                $this->paymentSessionService->forget($paymentSessionToken);
            }

            $customerUrl = $this->resolveCustomerUrl($paymentData);
            $this->persistPaymentRecord($code, $paymentData, $validAuthData, $customerUrl, 3);

            try {
                $this->notifyMerchantWithUserToken(
                    $paymentData,
                    'ERROR',
                    $booking->booking_code,
                    (float) ($paymentData['amount'] ?? 0),
                    (string) ($paymentData['currency'] ?? 'BOB'),
                    '3ds_authentication_failed'
                );
            } catch (Throwable $e) {
                logger()->warning('Merchant error notification failed for 3DS failure', [
                    'code' => $code,
                    'error' => $e->getMessage(),
                ]);
            }

            if (is_string($paymentSessionToken) && $paymentSessionToken !== '') {
                $this->paymentSessionService->forget($paymentSessionToken);
            }
            $this->paymentSessionService->forgetByCode($code);

            $html = $this->render_error_html(
                __('payment.error.title_3ds_failed'),
                $errorInfo['message'],
                $errorInfo['code'],
                $booking->booking_code,
                $this->resolveHomeUrlFromCustomerUrl($paymentData)
            );
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function authentication_setup(array $frontendData, string $cardNumber): JsonResponse
    {
        return $this->cyberSourceService->authenticationSetup($frontendData, $cardNumber);
    }

    public function enrollment_setup(string $reference_id, array $paymentData, Booking $booking, array $browserInfo = []): JsonResponse
    {
        return $this->cyberSourceService->enrollmentSetup($reference_id, $paymentData, $booking, $browserInfo);
    }

    public function valid_auth_dynamic(string $authTransactionId, array $paymentData, Booking $booking): JsonResponse
    {
        return $this->cyberSourceService->validAuthDynamic($authTransactionId, $paymentData, $booking);
    }

    public function payment_with_3ds_dynamic(array $paymentData, Booking $booking, array $authData, string $type, ?array $context = null): JsonResponse
    {
        return $this->cyberSourceService->paymentWith3dsDynamic($paymentData, $booking, $authData, $type, $context);
    }


    /**
     * Never log a payment session payload as-is — it carries the full PAN and CVV.
     * Mask those two fields before handing the array to logger(); everything else
     * (billing info, amounts) stays untouched for debuggability.
     */
    private function sanitizePaymentDataForLog(array $paymentData): array
    {
        if (isset($paymentData['card_number']) && is_string($paymentData['card_number'])) {
            $paymentData['card_number'] = CyberSourceSanitizer::maskPan($paymentData['card_number']);
        }

        if (isset($paymentData['cvv']) && is_string($paymentData['cvv'])) {
            $paymentData['cvv'] = CyberSourceSanitizer::maskCvv($paymentData['cvv']);
        }

        return $paymentData;
    }

    private function persistSuccessfulPayment(string $code, array $paymentData, array $paymentResult): void
    {
        $service = app(PaymentPersistenceService::class);
        $customerUrl = $this->resolveCustomerUrl($paymentData);
        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;
        $this->updateCustomerUrlForUser($customerUrl, $userId);

        $service->updateStatus(
            $code,
            [
                'state' => 2,
                'status' => 'authorized',
                'customer_url' => $customerUrl,
                'transaction_id' => $paymentResult['id'] ?? $paymentResult['transactionId'] ?? null,
                'reference' => $paymentResult['id'] ?? $paymentResult['transactionId'] ?? null,
                'user_id' => $userId,
            ]
        );
    }

    private function notifyMerchantWithUserToken(array $paymentData, string $status, string $bookCode, float $amount, string $currency, ?string $reason = null): void
    {
        $sessionUser = auth()->user();
        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;

        $user = null;
        if ($sessionUser !== null) {
            $userId = $sessionUser->id ?? $sessionUser->auth_user_id ?? $sessionUser->customer_id ?? $userId;
        }

        if ($userId !== null && $userId !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $user = DB::table($userTable)->where('id', (int) $userId)->first();
        }

        if ($user === null && isset($paymentData['email']) && is_string($paymentData['email'])) {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $user = DB::table($userTable)
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($paymentData['email']))])
                ->first();
        }

        if ($user === null) {
            logger()->warning('Merchant notification skipped: auth_user not found for payment', [
                'payment_data' => $paymentData,
            ]);
            return;
        }

        $customerUrl = is_string($user->customer_url ?? null) ? trim((string) $user->customer_url) : null;
        $merchantApiKey = is_string($user->merchant_notification_api_key ?? null) ? trim((string) $user->merchant_notification_api_key) : null;

        if ($customerUrl === '' || $merchantApiKey === '') {
            logger()->warning('Merchant notification skipped: missing customer_url or merchant_notification_api_key', [
                'user_id' => $user->id ?? null,
            ]);
            return;
        }

        $baseUrl = rtrim($customerUrl, '/');
        $tokenUrl = $baseUrl . '/api/webhooks/payments/token';

        $tokenResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $merchantApiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post($tokenUrl, [
            'bookCode' => $bookCode,
        ]);

        if (! $tokenResponse->successful()) {
            logger()->warning('Merchant notification token request failed', [
                'url' => $tokenUrl,
                'status' => $tokenResponse->status(),
                'body' => $tokenResponse->body(),
            ]);
            return;
        }

        $tokenData = $tokenResponse->json();
        $notificationToken = $tokenData['notification_token'] ?? null;

        if (! is_string($notificationToken) || trim($notificationToken) === '') {
            logger()->warning('Merchant notification token missing from response', [
                'response' => $tokenData,
            ]);
            return;
        }

        $updateResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $merchantApiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->patch($baseUrl . '/api/webhooks/payments/' . $notificationToken, [
            'bookCode' => $bookCode,
            'status' => $status,
            'paymentDate' => now()->toIso8601String(),
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reason' => $reason,
        ]);

        if (! $updateResponse->successful()) {
            logger()->warning('Merchant payment update failed', [
                'token' => $notificationToken,
                'status' => $updateResponse->status(),
                'body' => $updateResponse->body(),
            ]);
        }
    }

    private function resolveCustomerUrl(array $paymentData): ?string
    {
        $candidate = $paymentData['customer_url'] ?? $paymentData['redirect_url'] ?? null;

        if (is_string($candidate)) {
            $normalized = $this->normalizeCustomerUrl($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (isset($paymentData['user'])) {
            $user = $paymentData['user'];
            if (is_array($user)) {
                $candidate = $user['customer_url'] ?? $user['redirect_url'] ?? null;
                if (is_string($candidate)) {
                    $normalized = $this->normalizeCustomerUrl($candidate);
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }
        }

        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;

        if (isset($paymentData['user']) && is_array($paymentData['user']) && isset($paymentData['user']['id'])) {
            $userId = $paymentData['user']['id'] ?? $userId;
        }

        if ($userId !== null && $userId !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $stored = DB::table($userTable)->where('id', (int) $userId)->value('customer_url');
            if (is_string($stored)) {
                $normalized = $this->normalizeCustomerUrl($stored);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        $emailCandidate = $paymentData['billing_email'] ?? $paymentData['email'] ?? null;
        if (is_string($emailCandidate) && trim($emailCandidate) !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $storedByEmail = DB::table($userTable)
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($emailCandidate))])
                ->value('customer_url');

            if (is_string($storedByEmail)) {
                $normalized = $this->normalizeCustomerUrl($storedByEmail);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function resolveHomeUrlFromCustomerUrl(array $paymentData): ?string
    {
        $userId = $paymentData['user_id'] ?? $paymentData['auth_user_id'] ?? $paymentData['customer_id'] ?? null;

        if (isset($paymentData['user']) && is_array($paymentData['user']) && isset($paymentData['user']['id'])) {
            $userId = $paymentData['user']['id'] ?? $userId;
        }

        if ($userId !== null && $userId !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $stored = DB::table($userTable)->where('id', (int) $userId)->value('customer_url');

            $normalized = is_string($stored) ? $this->normalizeCustomerUrl($stored) : null;
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $emailCandidate = $paymentData['billing_email'] ?? $paymentData['email'] ?? null;
        if (is_string($emailCandidate) && trim($emailCandidate) !== '') {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $storedByEmail = DB::table($userTable)
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($emailCandidate))])
                ->value('customer_url');

            $normalized = is_string($storedByEmail) ? $this->normalizeCustomerUrl($storedByEmail) : null;
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $this->resolveCustomerUrl($paymentData);
    }

    private function updateCustomerUrlForUser(?string $customerUrl, mixed $userId): void
    {
        if (! is_string($customerUrl) || trim($customerUrl) === '') {
            return;
        }

        if ($userId === null || $userId === '') {
            return;
        }

        $table = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
        DB::table($table)->where('id', (int) $userId)->update(['customer_url' => trim($customerUrl)]);
    }

    private function normalizeCustomerUrl(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        $validUrl = filter_var($candidate, FILTER_VALIDATE_URL);
        if ($validUrl === false) {
            return null;
        }

        $scheme = strtolower(parse_url($validUrl, PHP_URL_SCHEME) ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return rtrim($validUrl, "?&");
    }

    private function buildCustomerRedirectUrl(?string $customerUrl, string $status, string $code, ?string $transactionId = null, ?string $message = null): ?string
    {
        $normalizedUrl = $this->normalizeCustomerUrl($customerUrl);
        if ($normalizedUrl === null) {
            return null;
        }

        $baseUrl = rtrim($normalizedUrl, "?&");
        $params = [
            'payment_status' => $status,
            'payment_code' => $code,
        ];

        if (is_string($transactionId) && trim($transactionId) !== '') {
            $params['transaction_id'] = trim($transactionId);
        }

        if (is_string($message) && trim($message) !== '') {
            $params['message'] = trim($message);
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function persistPaymentRecord(string $code, array $paymentData, array $providerResult, ?string $customerUrl = null, int $state = 0): void
    {
        if (! $this->paymentPersistenceService instanceof PaymentPersistenceService) {
            return;
        }

        $transactionId = $providerResult['id']
            ?? $providerResult['transactionId']
            ?? $providerResult['referenceId']
            ?? $providerResult['consumerAuthenticationInformation']['authenticationTransactionId']
            ?? null;

        $customerUrl = $customerUrl ?? $this->resolveCustomerUrl($paymentData);
        $userId = $paymentData['user_id'] ?? $paymentData['customer_id'] ?? $paymentData['auth_user_id'] ?? null;
        $this->updateCustomerUrlForUser($customerUrl, $userId);

        $payload = [
            'code' => $code,
            'amount' => (float) ($paymentData['amount'] ?? 0),
            'currency' => strtoupper((string) ($paymentData['currency'] ?? 'BOB')),
            'state' => $state,
            'status' => match ($state) {
                0 => 'pending',
                1 => 'processing',
                2 => 'paid',
                3 => 'error',
                default => 'pending',
            },
            'provider' => 'cybersource',
            'user_id' => $userId,
            'customer_url' => $customerUrl,
            'transaction_id' => $transactionId,
            'reference' => $transactionId,
        ];

        try {
            $this->paymentPersistenceService->updateStatus($code, $payload);
        } catch (\Throwable $e) {
            logger()->warning('Payment DB persistence failed.', [
                'code' => $code,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function render_invoice_html(Booking $booking, array $paymentData, array $paymentResult): string
    {
        $cardLast4 = preg_replace('/\D+/', '', (string) ($paymentData['card_number'] ?? ''));
        $cardLast4 = $cardLast4 !== '' ? '**** ' . substr($cardLast4, -4) : '—';

        return view('payment.invoice', [
            'reference' => $booking->booking_code ?? '—',
            'transactionId' => $paymentResult['id'] ?? '—',
            'cardLast4' => $cardLast4,
            'paidAt' => now()->format('d/m/Y H:i'),
            'email' => $paymentData['billing_email'] ?? ($booking->contact_email ?? '—'),
            'currency' => 'BOB',
            'amount' => number_format((float) ($booking->total_amount ?? 0), 2, ',', '.'),
            'homeUrl' => $this->resolveHomeUrlFromCustomerUrl($paymentData),
        ])->render();
    }

    private function render_error_html(string $title, string $reason, string $errorCode, string $bookingCode, ?string $homeUrl = null): string
    {
        return view('payment.error', [
            'title' => $title,
            'reason' => $reason,
            'errorCode' => $errorCode,
            'reference' => $bookingCode !== '' ? $bookingCode : '—',
            'homeUrl' => is_string($homeUrl) && trim($homeUrl) !== '' ? $homeUrl : '#',
        ])->render();
    }

    private function render_collection_html(string $code, string $reference_id, string $accessToken, ?string $url, $context): Response
    {
        $callbackUrl = route('payment.cybersource.enrollment.callback', ['code' => $code]);

        $domain = parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST);

        $html = '<!DOCTYPE html>'
            . '<html>'
            . '<head>'
            . '<title>Payment</title>'
            . '<meta charset="UTF-8">'
            . '<script type="text/javascript" src="https://h.online-metrix.net/fp/tags.js?org_id='. e($context['id']) .'&session_id=' . e($context['seqNum']) . '"></script>'
            . '</head>'
            . '<body>'
            . '<iframe id="cardinal_collection_iframe" name="collectionIframe" height="10" width="10" style="display:none;"></iframe>'
            . '<form id="cardinal_collection_form" method="POST" target="collectionIframe" action="' . e($url) . '">'
            . '<input id="cardinal_collection_form_input" type="hidden" name="JWT" value="' . e($accessToken) . '">'
            . '</form>'
            . '<script>'
            . 'window.onload = function() {'
            . 'var cardinalCollectionForm = document.querySelector("#cardinal_collection_form");'
            . 'if(cardinalCollectionForm){cardinalCollectionForm.submit();}'
            . '};'
            . '</script>'
            . '<script>'
            . 'window.addEventListener("message", function(event) {'
            . 'if(event.origin === "' . e($domain) . '"){'
            . 'console.log(event.data);'
            . 'var data = event.data;'
            . 'if(typeof data === "string"){ try { data = JSON.parse(data); } catch(e){} }'
            . 'if(data && data.MessageType === "profile.completed"){'
            . 'console.log("Cardinal collection complete, redirecting...");'
            . 'var url = ' . json_encode($callbackUrl) . ';'
            . 'url += "?browserAcceptHeader=" + encodeURIComponent("text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8");'
            . 'url += "&browserLanguage=" + encodeURIComponent(navigator.language || "es-BO");'
            . 'url += "&browserUserAgent=" + encodeURIComponent(navigator.userAgent);'
            . 'url += "&browserScreenHeight=" + encodeURIComponent(screen.height);'
            . 'url += "&browserScreenWidth=" + encodeURIComponent(screen.width);'
            . 'url += "&browserJavaEnabled=" + encodeURIComponent(navigator.javaEnabled ? navigator.javaEnabled() : false);'
            . 'window.location.href = url;'
            . '}'
            . '}'
            . '}, false);'
            . '</script>'
            . '<noscript>'
            . '<iframe style="width: 100px; height: 100px; border: 0; position: absolute; top: -5000px;" src="https://h.online-metrix.net/fp/tags?org_id='. e($context['id']) .'&session_id=' . e($context['seqNum']) . '"></iframe>'
            . '</noscript>'
            . '</body>'
            . '</html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function render_stepup_html(string $code, string $stepUpUrl, string $accessToken): Response
    {
        $enrollmentResultUrl = route('payment.cybersource.enrollment.result', ['code' => $code]);

        $html = '<!DOCTYPE html>'
            . '<html lang="en">'
            . '<head>'
            . '<meta charset="UTF-8">'
            . '<title>3DS2 Step-Up Challenge</title>'
            . '</head>'
            . '<body>'
            . '<form id="step-up-form" method="post" action="' . e($stepUpUrl) . '">'
            . '<input type="hidden" name="JWT" value="' . e($accessToken) . '" />'
            . '<input type="hidden" name="MD" value="' . e($code) . '" />'
            . '<input type="hidden" name="TermUrl" value="' . e($enrollmentResultUrl) . '" />'
            . '</form>'
            . '<script>'
            . 'window.onload = function() {'
            . 'var stepUpForm = document.querySelector("#step-up-form");'
            . 'if(stepUpForm) stepUpForm.submit();'
            . '};'
            . '</script>'
            . '</body>'
            . '</html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    // -----------------------------------------------------------------------
    // Algoritmo de Luhn para validar número de tarjeta
    // -----------------------------------------------------------------------
    private function luhnCheck(string $number): bool
    {
        if (!ctype_digit($number) || strlen($number) < 13 || strlen($number) > 19) {
            return false;
        }

        $sum    = 0;
        $parity = strlen($number) % 2;

        for ($i = 0; $i < strlen($number); $i++) {
            $digit = (int) $number[$i];
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) $digit -= 9;
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    private function isExpiryValid(int $month, int $year): bool
    {
        $now = now();
        if ($year > $now->year) return true;
        if ($year === $now->year && $month >= $now->month) return true;
        return false;
    }

    private function get_date(): string
    {
        return gmdate('D, d M Y H:i:s') . ' GMT';
    }

    private function get_blueprint(string $jsonString): string
    {
        $hash_data = hash('sha256', $jsonString, true);
        $encoded_data = base64_encode($hash_data);
        $blue_print = $encoded_data;

        return $blue_print;
    }

    private function get_headers_string(string $date_transaction, string $blue_print, string $url, string $merchant): string
    {
        $headers = "host: api.cybersource.com\n";
        $headers .= 'date: ' . $date_transaction . "\n";
        $headers .= '(request-target): post ' . $url . "\n";
        $headers .= 'digest: SHA-256=' . $blue_print . "\n";
        $headers .= 'v-c-merchant-id: ' . $merchant;
        logger()->info('HEADERS STRING1');
        logger()->info($headers);

        return $headers;
    }

    private function get_signature_hash(string $headers, string $merchant_secret_key): string
    {
        $sig_value_string = (string) $headers;
        $secret = base64_decode($merchant_secret_key, true);
        if ($secret === false) {
            throw new \RuntimeException('Invalid merchant secret key');
        }
        $hash_value = hash_hmac('sha256', $sig_value_string, $secret, true);
        $signature_hash = base64_encode($hash_value);

        return $signature_hash;
    }

    private function get_signature(string $signature_hash, string $key_id): string
    {
        $signature = 'keyid="' . $key_id . '", algorithm="HmacSHA256", headers="host date (request-target) digest v-c-merchant-id", signature="' . $signature_hash . '"';

        return $signature;
    }

    private function resolveCardType(string $cardNumber): string
    {
        $normalizedCardNumber = preg_replace('/\D+/', '', $cardNumber);

        if (!is_string($normalizedCardNumber) || $normalizedCardNumber === '') {
            return '001'; // Visa (default)
        }

        // AMEX: starts with 34/37 and has 15 digits.
        if (preg_match('/^3[47][0-9]{13}$/', $normalizedCardNumber)) {
            return '003'; // American Express
        }

        // Mastercard: 51-55 or 2221-2720, 16 digits.
        if (preg_match('/^5[1-5][0-9]{14}$/', $normalizedCardNumber)
            || preg_match('/^2(2[2-9][0-9]{12}|[3-6][0-9]{13}|7[01][0-9]{12}|720[0-9]{12})$/', $normalizedCardNumber)) {
            return '002'; // Mastercard
        }

        return '001'; // Visa (default)
    }
}


