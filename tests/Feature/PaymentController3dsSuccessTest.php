<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\CybersourceController;
use App\Services\CyberSource\CyberSourceService;
use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PaymentController3dsSuccessTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    /**
     * customer_url lives on payment_user (one value per payment), not on the user —
     * resolveCustomerUrl()'s fallback for a payment that doesn't carry its own
     * customer_url/redirect_url is "this user's most recent prior payment". Tests that
     * exercise that fallback need an actual prior payment_user row to find.
     */
    private function seedPriorPaymentCustomerUrl(int $userId, string $customerUrl): void
    {
        $paymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'PRIOR-' . uniqid(),
            'total' => '0',
            'currency' => 'BOB',
            'state' => '2',
            'creationDate' => now()->subDay(),
            'paymentDate' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        DB::table('payment_user')->insert([
            'auth_user_id' => $userId,
            'redirect_payment_id' => $paymentId,
            'customer_url' => $customerUrl,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_enrollment_result_handles_a_successful_3ds_flow(): void
    {
        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'card_number' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'cvv' => '123',
            'billing_first_name' => 'Roberto',
            'billing_last_name' => 'Jimenez',
            'billing_address1' => 'Calle Herber 123',
            'billing_locality' => 'La Paz',
            'billing_administrative_area' => 'L',
            'billing_country' => 'BO',
            'billing_phone' => '65612459',
            'billing_email' => 'cliente@example.com',
            'authentication_transaction_id' => 'trx-123',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->id = 42;
        $booking->booking_code = 'ABC123';
        $booking->status = 'pending';
        $booking->total_amount = 125.0;
        $booking->contact_email = 'cliente@example.com';
        $booking->cybersource_reference_id = 'ref-123';
        $booking->cybersource_payment_data = $payload;
        $booking->passengers = [
            (object) [
                'first_name' => 'Ana',
                'last_name' => 'Lopez',
                'document_type' => 'CI',
                'document_number' => '1234567',
                'seat' => (object) [
                    'seat_number' => 'A1',
                    'deck' => 'Superior',
                ],
            ],
        ];
        $booking->schedule = (object) [
            'departure_at' => now()->addDay(),
            'arrival_at' => now()->addDay()->addHours(6),
            'bus_name' => 'Trinidad',
            'bus_type' => 'Ejecutivo',
            'busRoute' => (object) [
                'originCity' => (object) ['name' => 'La Paz'],
                'destinationCity' => (object) ['name' => 'Santa Cruz'],
            ],
        ];

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
            ->with('trx-123', $payload, $booking)
            ->andReturn(response()->json([
                'status' => 'AUTHENTICATION_SUCCESSFUL',
                'consumerAuthenticationInformation' => [
                    'indicator' => 'internet',
                    'cavv' => 'abc123',
                    'xid' => 'xid123',
                    'directoryServerTransactionId' => 'dir123',
                ],
            ]));

        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with($payload, $booking, Mockery::type('array'), '3DS', $payload['context'])
            ->andReturn(response()->json([
                'status' => 'AUTHORIZED',
                'id' => 'pay-123',
            ]));

        $booking->shouldReceive('update')->with(['status' => 'confirmed'])->once()->andReturnTrue();
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('ABC123')->once();

        $request = Request::create('/api/bookings/ABC123/enrollment-result', 'POST', ['TransactionId' => 'trx-123']);

        $response = $controller->enrollment_result('ABC123', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Comprobante de Pago', $response->getContent());
        $this->assertStringContainsString('PAGADO', $response->getContent());
    }

    public function test_cybersource_enrollment_routes_are_available(): void
    {
        $callbackUrl = route('payment.cybersource.enrollment.callback', ['code' => 'ABC123']);
        $resultUrl = route('payment.cybersource.enrollment.result', ['code' => 'ABC123']);

        $this->assertStringContainsString('/api/payments/ABC123/enrollment-callback', $callbackUrl);
        $this->assertStringContainsString('/api/payments/ABC123/enrollment-result', $resultUrl);
    }

    public function test_it_uses_cached_payment_session_when_no_legacy_booking_exists(): void
    {
        $paymentSessionService = new \App\Services\Payments\PaymentSessionService();
        $token = $paymentSessionService->store('ABC123', [
            'code' => 'ABC123',
            'amount' => 125.0,
            'currency' => 'BOB',
            'status' => 'pending',
            'provider' => 'cybersource',
        ], 15);

        cache()->put('payment_session_data:ABC123', [
            'payment_session_token' => $token,
            'context' => ['seqNum' => 'redenlace_40003742'],
        ], now()->addMinutes(15));
        cache()->put('payment_reference_id_for_code:ABC123', 'ref-123', now()->addMinutes(15));

        $controller = new class($paymentSessionService) extends CybersourceController {
            public function __construct($paymentSessionService)
            {
                $this->paymentSessionService = $paymentSessionService;
            }

            public function exposeResolveBooking(string $code): \App\Models\Booking
            {
                return $this->resolveBooking($code);
            }
        };

        $booking = $controller->exposeResolveBooking('ABC123');

        $this->assertSame('ref-123', $booking->cybersource_reference_id);
        $this->assertSame($token, $booking->cybersource_payment_data['payment_session_token']);
    }

    public function test_enrollment_setup_uses_payment_route_return_url(): void
    {
        $service = new class extends CyberSourceService {
            public function sendSignedRequest(string $endpoint, string $jsonString, ?string $keyId, ?string $secretKey, ?string $merchantId): array
            {
                return [
                    'status' => 201,
                    'body' => json_decode($jsonString, true),
                ];
            }
        };

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'BOOK-12345';
        $booking->total_amount = 125.0;

        $response = $service->enrollmentSetup('ref-123', [
            'card_number' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'billing_address1' => 'Calle Herber 123',
            'billing_locality' => 'La Paz',
            'billing_first_name' => 'Roberto',
            'billing_last_name' => 'Jimenez',
            'billing_phone' => '65612459',
            'billing_email' => 'cliente@example.com',
        ], $booking, []);

        $payload = $response->getData(true);

        $this->assertStringContainsString('/api/payments/BOOK-12345/enrollment-result', $payload['consumerAuthenticationInformation']['returnUrl'] ?? '');
        $this->assertStringNotContainsString('/api/bookings/', $payload['consumerAuthenticationInformation']['returnUrl'] ?? '');
    }

    public function test_customer_redirect_uses_real_customer_url_and_status_params(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'customer-redirect-user',
            'first_name' => 'Customer',
            'last_name' => 'Redirect',
            'email' => 'customer-redirect@example.com',
        ]);
        $this->seedPriorPaymentCustomerUrl($user->getKey(), 'https://merchant.example/checkout/result');

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);
        $paymentPersistenceService = app(\App\Services\Payments\PaymentPersistenceService::class);

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService, $paymentPersistenceService) extends CybersourceController {
            public function exposeResolveCustomerUrl(array $paymentData): ?string
            {
                $method = new \ReflectionMethod(CybersourceController::class, 'resolveCustomerUrl');
                $method->setAccessible(true);

                return $method->invoke($this, $paymentData);
            }

            public function exposeBuildCustomerRedirectUrl(?string $customerUrl, string $status, string $code, ?string $transactionId = null, ?string $message = null): ?string
            {
                $method = new \ReflectionMethod(CybersourceController::class, 'buildCustomerRedirectUrl');
                $method->setAccessible(true);

                return $method->invoke($this, $customerUrl, $status, $code, $transactionId, $message);
            }
        };

        $resolvedUrl = $controller->exposeResolveCustomerUrl(['user_id' => $user->getKey()]);
        $redirectUrl = $controller->exposeBuildCustomerRedirectUrl($resolvedUrl, 'success', 'PAY-999', 'txn-123', 'Pago exitoso');

        $this->assertSame('https://merchant.example/checkout/result', $resolvedUrl);
        $this->assertStringContainsString('payment_status=success', $redirectUrl);
        $this->assertStringContainsString('payment_code=PAY-999', $redirectUrl);
        $this->assertStringContainsString('transaction_id=txn-123', $redirectUrl);
        $this->assertStringContainsString('message=Pago%20exitoso', $redirectUrl);
    }

    public function test_enrollment_result_shows_invoice_html_without_immediate_redirect(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'redirect-customer',
            'first_name' => 'Redirect',
            'last_name' => 'Customer',
            'email' => 'redirect-customer@example.com',
        ]);
        $this->seedPriorPaymentCustomerUrl($user->getKey(), 'https://merchant.example/checkout/result');

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);
        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'ABC123';
        $booking->status = 'pending';
        $booking->total_amount = 125.0;
        $booking->cybersource_reference_id = 'ref-123';
        $booking->cybersource_payment_data = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'authentication_transaction_id' => 'trx-123',
        ];

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

        $paymentSessionService->shouldReceive('get')->with('valid-token')->andReturn([
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => $user->email,
            'user_id' => $user->getKey(),
            'authentication_transaction_id' => 'trx-123',
        ]);

        $cyberSourceService->shouldReceive('validAuthDynamic')
            ->with('trx-123', Mockery::type('array'), $booking)
            ->andReturn(response()->json([
                'status' => 'AUTHENTICATION_SUCCESSFUL',
                'consumerAuthenticationInformation' => [
                    'indicator' => 'internet',
                    'cavv' => 'abc123',
                    'xid' => 'xid123',
                    'directoryServerTransactionId' => 'dir123',
                ],
            ]));

        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with(Mockery::type('array'), $booking, Mockery::type('array'), '3DS', Mockery::type('array'))
            ->andReturn(response()->json([
                'status' => 'AUTHORIZED',
                'id' => 'pay-redirect-123',
            ]));

        $booking->shouldReceive('update')->with(['status' => 'confirmed'])->once()->andReturnTrue();
        $paymentSessionService->shouldReceive('forget')->with('valid-token')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('ABC123')->once();

        $request = Request::create('/api/payments/ABC123/enrollment-result', 'POST', ['TransactionId' => 'trx-123']);

        $response = $controller->enrollment_result('ABC123', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Comprobante de Pago', $response->getContent());
        $this->assertStringContainsString('Volver al inicio', $response->getContent());
        $this->assertStringContainsString('https://merchant.example/checkout/result', $response->getContent());
    }

    public function test_enrollment_result_renders_error_html_when_payment_is_not_authorized(): void
    {
        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);
        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'ABCERR';
        $booking->status = 'pending';
        $booking->total_amount = 125.0;
        $booking->cybersource_reference_id = 'ref-err';
        $booking->cybersource_payment_data = [
            'payment_session_token' => 'valid-token-error',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => 'error@example.com',
            'authentication_transaction_id' => 'trx-err',
        ];

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

        $paymentSessionService->shouldReceive('get')->with('valid-token-error')->andReturn([
            'payment_session_token' => 'valid-token-error',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'billing_email' => 'error@example.com',
            'authentication_transaction_id' => 'trx-err',
        ]);

        $cyberSourceService->shouldReceive('validAuthDynamic')
            ->with('trx-err', Mockery::type('array'), $booking)
            ->andReturn(response()->json([
                'status' => 'AUTHENTICATION_SUCCESSFUL',
                'consumerAuthenticationInformation' => [
                    'indicator' => 'internet',
                    'cavv' => 'abc123',
                    'xid' => 'xid123',
                    'directoryServerTransactionId' => 'dir123',
                ],
            ]));

        $cyberSourceService->shouldReceive('paymentWith3dsDynamic')
            ->with(Mockery::type('array'), $booking, Mockery::type('array'), '3DS', Mockery::type('array'))
            ->andReturn(response()->json([
                'status' => 'DECLINED',
                'errorInformation' => [
                    'message' => 'La transacción fue rechazada por el banco.',
                    'reason' => 'DECLINED',
                ],
            ]));

        $paymentSessionService->shouldReceive('forget')->with('valid-token-error')->once();
        $paymentSessionService->shouldReceive('forgetByCode')->with('ABCERR')->once();

        $request = Request::create('/api/payments/ABCERR/enrollment-result', 'POST', ['TransactionId' => 'trx-err']);

        $response = $controller->enrollment_result('ABCERR', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Error al Procesar el Pago', $response->getContent());
        $this->assertStringContainsString('La transacción fue rechazada por el banco.', $response->getContent());
    }

    public function test_customer_url_is_resolved_from_email_when_user_id_is_missing(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'customer-email-user',
            'first_name' => 'Customer',
            'last_name' => 'Email',
            'email' => 'customer-email@example.com',
        ]);
        $this->seedPriorPaymentCustomerUrl($user->getKey(), 'https://merchant.example/checkout/from-email');

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);
        $paymentPersistenceService = app(\App\Services\Payments\PaymentPersistenceService::class);

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService, $paymentPersistenceService) extends CybersourceController {
            public function exposeResolveCustomerUrl(array $paymentData): ?string
            {
                $method = new \ReflectionMethod(CybersourceController::class, 'resolveCustomerUrl');
                $method->setAccessible(true);

                return $method->invoke($this, $paymentData);
            }
        };

        $resolvedUrl = $controller->exposeResolveCustomerUrl([
            'billing_email' => $user->email,
        ]);

        $this->assertSame('https://merchant.example/checkout/from-email', $resolvedUrl);
    }

    public function test_invalid_customer_url_is_rejected_for_redirects(): void
    {
        $user = \App\Models\User::factory()->create([
            'username' => 'invalid-customer-url-user',
            'first_name' => 'Invalid',
            'last_name' => 'Customer',
            'email' => 'invalid-customer-url@example.com',
        ]);
        $this->seedPriorPaymentCustomerUrl($user->getKey(), 'www.google.com');

        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);
        $paymentPersistenceService = app(\App\Services\Payments\PaymentPersistenceService::class);

        $controller = new class($paymentService, $cyberSourceService, $paymentSessionService, $paymentPersistenceService) extends CybersourceController {
            public function exposeResolveCustomerUrl(array $paymentData): ?string
            {
                $method = new \ReflectionMethod(CybersourceController::class, 'resolveCustomerUrl');
                $method->setAccessible(true);

                return $method->invoke($this, $paymentData);
            }

            public function exposeBuildCustomerRedirectUrl(?string $customerUrl, string $status, string $code): ?string
            {
                $method = new \ReflectionMethod(CybersourceController::class, 'buildCustomerRedirectUrl');
                $method->setAccessible(true);

                return $method->invoke($this, $customerUrl, $status, $code, null, null);
            }
        };

        $this->assertNull($controller->exposeResolveCustomerUrl(['user_id' => $user->getKey()]));
        $this->assertNull($controller->exposeBuildCustomerRedirectUrl('www.google.com', 'success', 'PAY-INVALID'));
    }

    public function test_pending_authentication_renders_step_up_form_with_return_url(): void
    {
        $paymentService = Mockery::mock(PaymentService::class);
        $cyberSourceService = Mockery::mock(CyberSourceService::class);
        $paymentSessionService = Mockery::mock(PaymentSessionService::class);

        $payload = [
            'payment_session_token' => 'valid-token',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'card_number' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'cvv' => '123',
            'billing_email' => 'cliente@example.com',
            'authentication_transaction_id' => 'trx-123',
        ];

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'ABC123';
        $booking->status = 'pending';
        $booking->total_amount = 125.0;
        $booking->cybersource_reference_id = 'ref-123';
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
            ->with('ref-123', $payload, $booking, Mockery::type('array'))
            ->andReturn(response()->json([
                'status' => 'PENDING_AUTHENTICATION',
                'consumerAuthenticationInformation' => [
                    'stepUpUrl' => 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/StepUp',
                    'accessToken' => 'jwt-otp',
                    'authenticationTransactionId' => 'trx-otp',
                ],
            ]));

        $booking->shouldReceive('update')->with([
            'cybersource_payment_data' => array_merge($payload, ['authentication_transaction_id' => 'trx-otp']),
        ])->once()->andReturnTrue();

        $request = Request::create('/api/payments/ABC123/enrollment-callback', 'POST', [
            'browserAcceptHeader' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'browserLanguage' => 'es-BO',
            'browserUserAgent' => 'Mozilla/5.0',
            'browserScreenHeight' => '768',
            'browserScreenWidth' => '1366',
            'browserJavaEnabled' => 'true',
        ]);

        $response = $controller->enrollment_callback('ABC123', $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('TermUrl', $response->getContent());
        $this->assertStringContainsString('/api/payments/ABC123/enrollment-result', $response->getContent());
        $this->assertStringContainsString('jwt-otp', $response->getContent());
    }

    public function test_it_uses_internet_indicator_for_3ds_test_card(): void
    {
        $service = new class extends CyberSourceService {
            public function sendSignedRequest(string $endpoint, string $jsonString, ?string $keyId, ?string $secretKey, ?string $merchantId): array
            {
                return [
                    'status' => 201,
                    'body' => json_decode($jsonString, true),
                ];
            }
        };

        $booking = Mockery::mock(\App\Models\Booking::class)->makePartial();
        $booking->booking_code = 'ABC456';
        $booking->total_amount = 149.99;
        $booking->passengers = [
            (object) [
                'first_name' => 'Ana',
                'last_name' => 'Lopez',
                'document_number' => '1234567',
            ],
        ];
        $booking->schedule = (object) [
            'busRoute' => (object) [
                'destinationCity' => (object) ['name' => 'Santa Cruz'],
            ],
        ];

        $paymentData = [
            'card_number' => '4000000000001000',
            'expiry_month' => '12',
            'expiry_year' => '2028',
            'cvv' => '123',
            'billing_address1' => 'Calle Herber 123',
            'billing_locality' => 'La Paz',
            'billing_first_name' => 'Roberto',
            'billing_last_name' => 'Jimenez',
            'billing_phone' => '65612459',
            'billing_email' => 'cliente@example.com',
            'context' => ['seqNum' => 'redenlace_40003742'],
        ];

        $response = $service->paymentWith3dsDynamic($paymentData, $booking, [], '3DS', $paymentData['context']);
        $payload = $response->getData(true);

        $this->assertSame('internet', $payload['processingInformation']['commerceIndicator']);
        $this->assertSame('4000000000001000', $payload['paymentInformation']['card']['number']);
    }
}
