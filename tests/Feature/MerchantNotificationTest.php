<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CybersourceController;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentSessionService;
use App\Services\CyberSource\CyberSourceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Covers the merchant-notification contract described in PROJECT_SUMMARY.md:
 *
 *   1. POST  {customer_url}/api/webhooks/payments/token   with only {bookCode}
 *   2. PATCH {customer_url}/api/webhooks/payments/{token} with the final status
 *
 * using the per-merchant `customer_url` / `merchant_notification_api_key`
 * stored on `auth_user` — never a global fixed key.
 *
 * Historically this contract was only implemented by
 * CybersourceController::notifyMerchantWithUserToken(), while two of the four
 * 3DS branches (the step-up/"enrollment_result" success and decline paths)
 * called the legacy CommerceNotificationService instead, which used a single
 * POST (no token, no PATCH) and fell back to a global API key from
 * config('services.merchant_notifications'). These tests pin down that every
 * branch — with and without a 3DS step-up challenge — now follows the same,
 * correct contract, and that the global fallback is gone for good.
 */
class MerchantNotificationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeMerchantUser(array $overrides = []): \App\Models\User
    {
        return \App\Models\User::factory()->create(array_merge([
            'username' => 'merchant-' . uniqid(),
            'first_name' => 'Merchant',
            'last_name' => 'Test',
            'email' => 'merchant-' . uniqid() . '@example.com',
            'customer_url' => 'https://merchant.example.test',
            'merchant_notification_api_key' => 'merchant-secret-key-' . uniqid(),
        ], $overrides));
    }

    private function fakeMerchantEndpoints(string $notificationToken = 'notif-token-abc'): void
    {
        Http::fake([
            'https://merchant.example.test/api/webhooks/payments/token' => Http::response([
                'success' => true,
                'notification_token' => $notificationToken,
                'expires_in' => 300,
            ], 200),
            'https://merchant.example.test/api/webhooks/payments/' . $notificationToken => Http::response([
                'success' => true,
            ], 200),
        ]);
    }

    private function assertTokenCreatedWithOnlyBookCode(string $apiKey, string $bookCode): void
    {
        Http::assertSent(function ($request) use ($apiKey, $bookCode) {
            $data = $request->data();

            return $request->url() === 'https://merchant.example.test/api/webhooks/payments/token'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer ' . $apiKey)
                && ($data['bookCode'] ?? null) === $bookCode
                && ! array_key_exists('status', $data);
        });
    }

    private function assertPaymentPatchedWithFinalStatus(
        string $apiKey,
        string $notificationToken,
        string $bookCode,
        string $status,
        ?string $reason = null
    ): void {
        Http::assertSent(function ($request) use ($apiKey, $notificationToken, $bookCode, $status, $reason) {
            $data = $request->data();

            return $request->url() === 'https://merchant.example.test/api/webhooks/payments/' . $notificationToken
                && $request->method() === 'PATCH'
                && $request->hasHeader('Authorization', 'Bearer ' . $apiKey)
                && ($data['bookCode'] ?? null) === $bookCode
                && ($data['status'] ?? null) === $status
                && ($data['currency'] ?? null) === 'BOB'
                && (float) ($data['amount'] ?? 0) === 125.5
                && ($data['reason'] ?? null) === $reason;
        });
    }

    // -----------------------------------------------------------------
    // Frictionless flow (no 3DS step-up) — enrollment_callback()
    // -----------------------------------------------------------------

    public function test_frictionless_authorized_payment_notifies_merchant_via_token_and_patch_contract(): void
    {
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'FRICT-OK';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-frict-ok';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('enrollmentSetup')
            ->with('ref-frict-ok', $payload, $booking, Mockery::type('array'))
            ->andReturn(response()->json(['status' => 'AUTHENTICATION_SUCCESSFUL']));
        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), 'NO_3DS', $payload['context'])
            ->andReturn(response()->json(['status' => 'AUTHORIZED', 'id' => 'pay-frict-ok']));

        $booking->shouldReceive('update')->with(['status' => 'confirmed'])->once()->andReturnTrue();
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('FRICT-OK')->once();

        $request = Request::create('/api/payments/FRICT-OK/enrollment-callback', 'POST');
        $response = $controller->enrollment_callback('FRICT-OK', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'FRICT-OK');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'FRICT-OK', 'AUTHORIZED');
    }

    public function test_frictionless_declined_payment_notifies_merchant_with_error_status(): void
    {
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'FRICT-DECL';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-frict-decl';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('enrollmentSetup')
            ->with('ref-frict-decl', $payload, $booking, Mockery::type('array'))
            ->andReturn(response()->json(['status' => 'AUTHENTICATION_SUCCESSFUL']));
        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), 'NO_3DS', $payload['context'])
            ->andReturn(response()->json([
                'status' => 'DECLINED',
                'errorInformation' => ['message' => 'Rechazada por el banco.', 'reason' => 'DECLINED'],
            ]));

        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('FRICT-DECL')->once();

        $request = Request::create('/api/payments/FRICT-DECL/enrollment-callback', 'POST');
        $response = $controller->enrollment_callback('FRICT-DECL', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'FRICT-DECL');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'FRICT-DECL', 'ERROR', 'payment_not_authorized');
    }

    public function test_frictionless_3ds_authentication_failure_notifies_merchant_via_token_contract(): void
    {
        // This branch used to call the legacy CommerceNotificationService (single POST,
        // global-key fallback). It must now use the same token+PATCH contract as everything else.
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'FRICT-3DSFAIL';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-frict-3dsfail';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('enrollmentSetup')
            ->with('ref-frict-3dsfail', $payload, $booking, Mockery::type('array'))
            ->andReturn(response()->json([
                'status' => 'AUTHENTICATION_FAILED',
                'errorInformation' => ['message' => 'No se pudo autenticar.', 'reason' => 'AUTHENTICATION_FAILED'],
            ]));

        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();

        $request = Request::create('/api/payments/FRICT-3DSFAIL/enrollment-callback', 'POST');
        $response = $controller->enrollment_callback('FRICT-3DSFAIL', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'FRICT-3DSFAIL');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'FRICT-3DSFAIL', 'ERROR', '3ds_authentication_failed');
    }

    // -----------------------------------------------------------------
    // Step-up / 3DS challenge flow — enrollment_result()
    // These are the two branches that were still on the legacy contract.
    // -----------------------------------------------------------------

    public function test_step_up_challenge_authorized_payment_notifies_merchant_via_token_and_patch_contract(): void
    {
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
            'authentication_transaction_id' => 'trx-stepup-ok',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'STEPUP-OK';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-stepup-ok';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('validAuthDynamic')
            ->with('trx-stepup-ok', $payload, $booking)
            ->andReturn(response()->json(['status' => 'AUTHENTICATION_SUCCESSFUL']));
        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), '3DS', $payload['context'])
            ->andReturn(response()->json(['status' => 'AUTHORIZED', 'id' => 'pay-stepup-ok']));

        $booking->shouldReceive('update')->with(['status' => 'confirmed'])->once()->andReturnTrue();
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('STEPUP-OK')->once();

        $request = Request::create('/api/payments/STEPUP-OK/enrollment-result', 'POST', ['TransactionId' => 'trx-stepup-ok']);
        $response = $controller->enrollment_result('STEPUP-OK', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Comprobante de Pago', $response->getContent());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'STEPUP-OK');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'STEPUP-OK', 'AUTHORIZED');
    }

    public function test_step_up_challenge_declined_payment_notifies_merchant_with_error_status(): void
    {
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
            'authentication_transaction_id' => 'trx-stepup-decl',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'STEPUP-DECL';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-stepup-decl';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('validAuthDynamic')
            ->with('trx-stepup-decl', $payload, $booking)
            ->andReturn(response()->json(['status' => 'AUTHENTICATION_SUCCESSFUL']));
        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), '3DS', $payload['context'])
            ->andReturn(response()->json([
                'status' => 'DECLINED',
                'errorInformation' => ['message' => 'Rechazada por el banco.', 'reason' => 'DECLINED'],
            ]));

        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('STEPUP-DECL')->once();

        $request = Request::create('/api/payments/STEPUP-DECL/enrollment-result', 'POST', ['TransactionId' => 'trx-stepup-decl']);
        $response = $controller->enrollment_result('STEPUP-DECL', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Error al Procesar el Pago', $response->getContent());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'STEPUP-DECL');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'STEPUP-DECL', 'ERROR', 'payment_not_authorized');
    }

    public function test_step_up_challenge_3ds_authentication_failure_notifies_merchant_via_token_contract(): void
    {
        // This branch already used the correct contract before the fix; kept here as a
        // regression guard so it can't silently regress back to the legacy path.
        $user = $this->makeMerchantUser();
        $this->fakeMerchantEndpoints();

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
            'authentication_transaction_id' => 'trx-stepup-3dsfail',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'STEPUP-3DSFAIL';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-stepup-3dsfail';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('validAuthDynamic')
            ->with('trx-stepup-3dsfail', $payload, $booking)
            ->andReturn(response()->json([
                'status' => 'AUTHENTICATION_FAILED',
                'errorInformation' => ['message' => 'No se pudo autenticar.', 'reason' => 'AUTHENTICATION_FAILED'],
            ]));

        // This branch calls forget() twice in the existing controller code (once before
        // the notification, once after) — unrelated to this fix, so we assert what it does.
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->twice();
        $paymentSessionService->shouldReceive('forgetByCode')->with('STEPUP-3DSFAIL')->once();

        $request = Request::create('/api/payments/STEPUP-3DSFAIL/enrollment-result', 'POST', ['TransactionId' => 'trx-stepup-3dsfail']);
        $response = $controller->enrollment_result('STEPUP-3DSFAIL', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTokenCreatedWithOnlyBookCode($user->merchant_notification_api_key, 'STEPUP-3DSFAIL');
        $this->assertPaymentPatchedWithFinalStatus($user->merchant_notification_api_key, 'notif-token-abc', 'STEPUP-3DSFAIL', 'ERROR', '3ds_authentication_failed');
    }

    // -----------------------------------------------------------------
    // Guard rails
    // -----------------------------------------------------------------

    public function test_merchant_notification_never_patches_when_token_request_fails(): void
    {
        $user = $this->makeMerchantUser();

        Http::fake([
            'https://merchant.example.test/api/webhooks/payments/token' => Http::response([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401),
        ]);

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'amount' => 125.5,
            'currency' => 'BOB',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'TOKENFAIL';
        $booking->status = 'pending';
        $booking->total_amount = 125.5;
        $booking->cybersource_reference_id = 'ref-tokenfail';
        $booking->cybersource_payment_data = $payload;

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService) extends CybersourceController {
            private $booking;

            public function setBooking($booking): void
            {
                $this->booking = $booking;
            }

            protected function resolveBooking(string $code): \App\Models\Booking
            {
                return $this->booking;
            }
        };
        $controller->setBooking($booking);

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn($payload);
        $cyberSourceService->shouldReceive('enrollmentSetup')
            ->with('ref-tokenfail', $payload, $booking, Mockery::type('array'))
            ->andReturn(response()->json(['status' => 'AUTHENTICATION_SUCCESSFUL']));
        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), 'NO_3DS', $payload['context'])
            ->andReturn(response()->json(['status' => 'AUTHORIZED', 'id' => 'pay-tokenfail']));

        $booking->shouldReceive('update')->with(['status' => 'confirmed'])->once()->andReturnTrue();
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('TOKENFAIL')->once();

        $request = Request::create('/api/payments/TOKENFAIL/enrollment-callback', 'POST');
        $response = $controller->enrollment_callback('TOKENFAIL', $request);

        // The payment itself still succeeds even though the merchant webhook failed.
        $this->assertSame(200, $response->getStatusCode());
        Http::assertSentCount(1); // only the failed token request — no PATCH follows it
    }

    public function test_services_config_no_longer_exposes_a_global_merchant_notification_fallback(): void
    {
        $this->assertNull(config('services.merchant_notifications'));
    }
}
