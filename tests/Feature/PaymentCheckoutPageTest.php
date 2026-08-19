<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PaymentCheckoutPageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_payment_token_loads_checkout_page(): void
    {
        $token = app(PaymentSessionService::class)->store('PW6ET48B', [
            'code' => 'PW6ET48B',
            'amount' => 150.00,
            'currency' => 'BOB',
            'context' => ['seqNum' => 'redenlace_40003742'],
            'card_number' => '4000000000001000',
            'expiry_month' => '12',
            'expiry_year' => '2028',
            'cvv' => '123',
            'billing_email' => 'cliente@example.com',
        ], 15);

        $response = $this->get('/pay/' . rawurlencode($token));

        $response->assertOk();
        $response->assertSee('Pago de servicio');
        $response->assertSee('PW6ET48B');
    }

    public function test_invalid_payment_token_shows_expired_message(): void
    {
        $response = $this->get('/pay/not-a-valid-token');

        $response->assertStatus(200);
        $response->assertSee('ya no es válido');
    }
}
