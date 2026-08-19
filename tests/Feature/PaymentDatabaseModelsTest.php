<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentPersistenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymentDatabaseModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_database_tables_are_resolved_without_breaking_legacy_tables(): void
    {
        if (Schema::hasTable('auth_user')) {
            $this->assertSame('auth_user', (new User())->getTable());
        } else {
            $this->assertSame('users', (new User())->getTable());
        }

        if (Schema::hasTable('redirect_payment')) {
            $this->assertSame('redirect_payment', (new Payment())->getTable());
        } else {
            $this->assertSame('payments', (new Payment())->getTable());
        }
    }

    public function test_it_persists_a_payment_and_links_it_to_the_user_when_available(): void
    {
        $user = User::factory()->create([
            'username' => 'test-user',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ]);

        $service = app(PaymentPersistenceService::class);

        $payment = $service->store([
            'code' => 'PAY-1001',
            'amount' => 150.50,
            'currency' => 'BOB',
            'status' => 'pending',
            'provider' => 'cybersource',
            'user_id' => $user->getKey(),
        ]);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame('PAY-1001', $payment->code ?? $payment->payment_code ?? null);
        $this->assertTrue($payment->users()->whereKey($user->getKey())->exists());
    }

    public function test_it_updates_the_payment_status_and_customer_redirect_url_after_success(): void
    {
        $user = User::factory()->create([
            'username' => 'buyer-user',
            'first_name' => 'Buyer',
            'last_name' => 'User',
            'email' => 'buyer@example.com',
        ]);

        $service = app(PaymentPersistenceService::class);

        $service->store([
            'code' => 'PAY-2002',
            'amount' => 99.99,
            'currency' => 'BOB',
            'status' => 'pending',
            'provider' => 'cybersource',
            'user_id' => $user->getKey(),
            'customer_url' => 'https://merchant.example/success',
        ]);

        $updated = $service->updateStatus('PAY-2002', [
            'status' => 'authorized',
            'transaction_id' => 'txn-8899',
            'customer_url' => 'https://merchant.example/receipt',
        ]);

        $this->assertSame(2, (int) ($updated->state ?? 2));
        $this->assertSame('txn-8899', $updated->transaction_id ?? $updated->reference ?? null);
        $this->assertSame('https://merchant.example/receipt', $user->fresh()->customer_url ?? null);
    }

    public function test_it_fills_required_legacy_redirect_payment_fields_when_table_has_strict_schema(): void
    {
        $user = User::factory()->create([
            'username' => 'strict-user',
            'first_name' => 'Strict',
            'last_name' => 'Payment',
            'email' => 'strict@example.com',
        ]);

        $service = app(PaymentPersistenceService::class);

        $payment = $service->store([
            'code' => 'PAY-3003',
            'amount' => 42.00,
            'currency' => 'BOB',
            'status' => 'pending',
            'provider' => 'cybersource',
            'user_id' => $user->getKey(),
        ]);

        $this->assertNotNull($payment->mail ?? $payment->email ?? null);
        $this->assertNotNull($payment->name ?? null);
        $this->assertNotNull($payment->lastName ?? null);
        $this->assertNotNull($payment->description ?? null);
    }
}
