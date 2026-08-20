<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentCustomerSearchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Every username/password check in this codebase used to fall back to
 *
 *     hash_equals($storedPassword, $password)
 *
 * whenever Hash::check() failed. That fallback does a *raw* string compare, so if the
 * caller sends the bcrypt hash itself as the "password" (e.g. leaked from a DB dump, a
 * log line, or a doc like PROJECT_SUMMARY.md that happens to quote one), it matches
 * $storedPassword byte-for-byte and authentication succeeds — no need to ever know the
 * real password. The fallback was only meant to support legacy accounts whose `password`
 * column holds real plaintext (never hashed), so it must never fire when the stored value
 * is actually a recognized hash.
 */
class AuthenticationHashBypassTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    public function test_session_issuance_rejects_the_stored_password_hash_used_as_the_password(): void
    {
        $hashedPassword = Hash::make('the-real-password');

        DB::table('auth_user')->insert([
            'username' => 'hash-bypass-user',
            'first_name' => 'Hash',
            'last_name' => 'Bypass',
            'email' => 'hash-bypass@example.com',
            'password' => $hashedPassword,
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/hash-bypass',
        ]);

        $response = $this->postJson('/api/payments/session', [
            'code' => '123456789ABCGSZ123ABDSjorge12',
            'amount' => 10,
            'currency' => 'BOB',
            'username' => 'hash-bypass-user',
            'password' => $hashedPassword, // attacker only has the leaked hash, not the real password
        ]);

        $response->assertStatus(401);
        $this->assertSame('Credenciales inválidas.', $response->json('message'));
    }

    public function test_session_issuance_still_works_with_the_real_password(): void
    {
        DB::table('auth_user')->insert([
            'username' => 'hash-bypass-user-ok',
            'first_name' => 'Hash',
            'last_name' => 'Bypass',
            'email' => 'hash-bypass-ok@example.com',
            'password' => Hash::make('the-real-password'),
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/hash-bypass-ok',
        ]);

        $response = $this->postJson('/api/payments/session', [
            'code' => 'PAY-HASH-OK',
            'amount' => 10,
            'currency' => 'BOB',
            'username' => 'hash-bypass-user-ok',
            'password' => 'the-real-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_search_token_issuance_rejects_the_stored_password_hash_used_as_the_password(): void
    {
        $hashedPassword = Hash::make('another-real-password');

        DB::table('auth_user')->insert([
            'username' => 'hash-bypass-search-user',
            'first_name' => 'Hash',
            'last_name' => 'Search',
            'email' => 'hash-bypass-search@example.com',
            'password' => $hashedPassword,
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/hash-bypass-search',
        ]);

        $response = $this->postJson('/api/payments/search-token', [
            'username' => 'hash-bypass-search-user',
            'password' => $hashedPassword,
        ]);

        $response->assertStatus(401);
    }

    public function test_customer_search_service_rejects_the_stored_password_hash_used_as_the_password(): void
    {
        $hashedPassword = Hash::make('yet-another-real-password');

        DB::table('auth_user')->insert([
            'username' => 'hash-bypass-customer-search',
            'first_name' => 'Hash',
            'last_name' => 'Customer',
            'email' => 'hash-bypass-customer-search@example.com',
            'password' => $hashedPassword,
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/hash-bypass-customer-search',
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PaymentCustomerSearchService::class)->resolveUser('hash-bypass-customer-search', $hashedPassword);
    }

    public function test_legacy_plaintext_stored_password_still_authenticates(): void
    {
        // Some auth_user rows genuinely hold a plaintext value (never hashed, migrated
        // from another system). That fallback must keep working — it's only the "stored
        // value IS a real hash" case that must stop accepting a raw compare.
        DB::table('auth_user')->insert([
            'username' => 'legacy-plaintext-user',
            'first_name' => 'Legacy',
            'last_name' => 'Plaintext',
            'email' => 'legacy-plaintext@example.com',
            'password' => 'plain-legacy-password',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/legacy',
        ]);

        $response = $this->postJson('/api/payments/session', [
            'code' => 'PAY-LEGACY-PLAINTEXT',
            'amount' => 10,
            'currency' => 'BOB',
            'username' => 'legacy-plaintext-user',
            'password' => 'plain-legacy-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('token'));
    }
}
