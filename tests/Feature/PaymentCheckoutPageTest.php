<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentSessionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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

    public function test_checkout_page_stops_loading_once_the_code_already_has_a_persisted_result(): void
    {
        // The original checkout-URL token (from /api/payments/session) is a different
        // cache entry than the internal session token CybersourceController mints once
        // the card form is submitted — only the latter gets forgotten when the payment
        // concludes. So the durable source of truth (a redirect_payment row for this
        // code) must be checked directly, or the original URL keeps "working" forever.
        $token = app(PaymentSessionService::class)->store('PAY-ALREADY-DONE', [
            'code' => 'PAY-ALREADY-DONE',
            'amount' => 150.00,
            'currency' => 'BOB',
        ], 15);

        DB::table('redirect_payment')->insert([
            'bookCode' => 'PAY-ALREADY-DONE',
            'total' => '150.00',
            'currency' => 'BOB',
            'state' => '2', // already paid
            'creationDate' => now(),
            'paymentDate' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/pay/' . rawurlencode($token));

        $response->assertOk();
        $response->assertSee('ya no es válido');
    }
}
