<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentService;
use App\Services\Payments\PaymentSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_and_retrieves_a_secure_payment_session()
    {
        $service = new PaymentSessionService();
        $code = 'ABC123';
        $payload = [
            'context' => ['seqNum' => 'redenlace_40003742'],
            'card_number' => '4111111111111111',
            'expiry_month' => '12',
            'expiry_year' => '2030',
            'cvv' => '123',
            'billing_email' => 'cliente@example.com',
        ];

        $token = $service->store($code, $payload, 15);
        $loaded = $service->get($token);

        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertStringNotContainsString('session_id', $token);
        $this->assertStringNotContainsString('ABC123', $token);
        $this->assertSame($payload['card_number'], $loaded['card_number']);
        $this->assertSame($payload['billing_email'], $loaded['billing_email']);
    }

    public function test_it_rejects_tampered_tokens()
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = new PaymentSessionService();
        $token = $service->store('ABC456', ['card_number' => '4111111111111111'], 15);

        $service->get($token . 'tampered');
    }

    public function test_it_builds_payment_context_without_legacy_sequence_table(): void
    {
        $service = app(PaymentService::class);

        $result = $service->buildPaymentContext('ABC123');

        $this->assertSame('ABC123', $result['code']);
        $this->assertArrayHasKey('context', $result);
        $this->assertMatchesRegularExpression('/^redenlace_[0-9]+$/', (string) ($result['context']['seqNum'] ?? ''));
        $this->assertNotNull($result['context']['seqNum'] ?? null);
    }
}
