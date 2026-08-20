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
 *
 * /api/payments/session and /api/payments/search no longer accept username/password at all
 * — they were migrated to `Authorization: Bearer <merchant_notification_api_key>` (see
 * PaymentSessionCodeReuseTest / PaymentTokenEndpointTest), which has no password/hash
 * comparison to bypass in the first place. PaymentCustomerSearchService still uses
 * username/password internally (it isn't wired to any route today), so it keeps the same
 * fix and the same regression coverage here.
 */
class AuthenticationHashBypassTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

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
        ]);

        $this->expectException(InvalidArgumentException::class);

        app(PaymentCustomerSearchService::class)->resolveUser('hash-bypass-customer-search', $hashedPassword);
    }

    public function test_customer_search_service_still_authenticates_with_the_real_password(): void
    {
        DB::table('auth_user')->insert([
            'username' => 'hash-bypass-customer-search-ok',
            'first_name' => 'Hash',
            'last_name' => 'Customer',
            'email' => 'hash-bypass-customer-search-ok@example.com',
            'password' => Hash::make('the-real-password'),
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
        ]);

        $user = app(PaymentCustomerSearchService::class)->resolveUser('hash-bypass-customer-search-ok', 'the-real-password');

        $this->assertSame('hash-bypass-customer-search-ok', $user->username);
    }

    public function test_customer_search_service_still_authenticates_legacy_plaintext_stored_passwords(): void
    {
        // Some auth_user rows genuinely hold a plaintext value (never hashed, migrated
        // from another system). That fallback must keep working — it's only the "stored
        // value IS a real hash" case that must stop accepting a raw compare.
        DB::table('auth_user')->insert([
            'username' => 'legacy-plaintext-customer-search',
            'first_name' => 'Legacy',
            'last_name' => 'Plaintext',
            'email' => 'legacy-plaintext-customer-search@example.com',
            'password' => 'plain-legacy-password',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
        ]);

        $user = app(PaymentCustomerSearchService::class)->resolveUser('legacy-plaintext-customer-search', 'plain-legacy-password');

        $this->assertSame('legacy-plaintext-customer-search', $user->username);
    }
}
