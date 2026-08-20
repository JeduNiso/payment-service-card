<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A `code` is good for exactly one payment attempt. Once a result — paid or error — is
 * persisted for it in redirect_payment, /api/payments/session must refuse to issue a
 * fresh session token for that same code; the caller needs a new code to try again.
 * Before any result is persisted, issuing a token must keep working normally.
 */
class PaymentSessionCodeReuseTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    private function insertMerchantUser(string $username, string $apiKey): void
    {
        DB::table('auth_user')->insert([
            'username' => $username,
            'first_name' => 'Code',
            'last_name' => 'Reuse',
            'email' => $username . '@example.com',
            'password' => 'unused-real-login-password',
            'merchant_notification_api_key' => $apiKey,
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
        ]);
    }

    private function insertExistingPayment(string $bookCode, string $state): void
    {
        DB::table('redirect_payment')->insert([
            'bookCode' => $bookCode,
            'total' => '10.00',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'Cliente',
            'lastName' => 'Pago',
            'mail' => 'no-reply@example.com',
            'description' => 'Pago online',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => $state,
            'creationDate' => now(),
            'paymentDate' => now(),
            'book' => '{}',
            'invoiceName' => 'Cliente Pago',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);
    }

    public function test_session_issuance_is_rejected_when_the_code_already_has_a_paid_record(): void
    {
        $this->insertMerchantUser('code-reuse-paid', 'reuse-paid-api-key');
        $this->insertExistingPayment('REUSE-PAID', '2'); // 2 = paid/authorized

        $response = $this->postJson('/api/payments/session', [
            'code' => 'REUSE-PAID',
            'amount' => 10,
            'currency' => 'BOB',
        ], [
            'HTTP_Authorization' => 'Bearer reuse-paid-api-key',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ya tiene un pago registrado', $response->json('message'));
    }

    public function test_session_issuance_is_rejected_when_the_code_already_has_an_error_record(): void
    {
        $this->insertMerchantUser('code-reuse-error', 'reuse-error-api-key');
        $this->insertExistingPayment('REUSE-ERROR', '3'); // 3 = error/declined

        $response = $this->postJson('/api/payments/session', [
            'code' => 'REUSE-ERROR',
            'amount' => 10,
            'currency' => 'BOB',
        ], [
            'HTTP_Authorization' => 'Bearer reuse-error-api-key',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ya tiene un pago registrado', $response->json('message'));
    }

    public function test_session_issuance_still_works_for_a_brand_new_code(): void
    {
        $this->insertMerchantUser('code-reuse-fresh', 'reuse-fresh-api-key');
        // No redirect_payment row for this code at all.

        $response = $this->postJson('/api/payments/session', [
            'code' => 'REUSE-FRESH',
            'amount' => 10,
            'currency' => 'BOB',
        ], [
            'HTTP_Authorization' => 'Bearer reuse-fresh-api-key',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_session_issuance_is_rejected_even_for_a_pending_record_left_over_from_a_prior_attempt(): void
    {
        $this->insertMerchantUser('code-reuse-pending', 'reuse-pending-api-key');
        $this->insertExistingPayment('REUSE-PENDING', '0'); // 0 = not_paid/pending

        $response = $this->postJson('/api/payments/session', [
            'code' => 'REUSE-PENDING',
            'amount' => 10,
            'currency' => 'BOB',
        ], [
            'HTTP_Authorization' => 'Bearer reuse-pending-api-key',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('ya tiene un pago registrado', $response->json('message'));
    }
}
