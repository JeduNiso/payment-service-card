<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaymentTokenEndpointTest extends TestCase
{
    use \Illuminate\Foundation\Testing\DatabaseTransactions;

    public function test_it_returns_a_valid_token_with_valid_credentials(): void
    {
        DB::table('auth_user')->insert([
            [
                'username' => 'db-user',
                'first_name' => 'DB',
                'last_name' => 'User',
                'email' => 'db-user@example.com',
                'password' => 'db-pass',
                'is_superuser' => false,
                'is_staff' => false,
                'is_active' => true,
                'date_joined' => now(),
                'customer_url' => 'https://client.example.com/token',
            ],
        ]);

        $response = $this->postJson('/api/payments/session', [
            'code' => 'ABC123',
            'amount' => 150.50,
            'currency' => 'BOB',
            'username' => 'db-user',
            'password' => 'db-pass',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'token',
            'url',
            'expires_in',
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertStringContainsString('/payments/', $response->json('url'));
    }

    public function test_it_rejects_invalid_credentials(): void
    {
        DB::table('auth_user')->insert([
            [
                'username' => 'db-user',
                'first_name' => 'DB',
                'last_name' => 'User',
                'email' => 'db-user@example.com',
                'password' => 'db-pass',
                'is_superuser' => false,
                'is_staff' => false,
                'is_active' => true,
                'date_joined' => now(),
                'customer_url' => 'https://client.example.com/token',
            ],
        ]);

        $response = $this->postJson('/api/payments/session', [
            'code' => 'ABC123',
            'amount' => 150.50,
            'currency' => 'BOB',
            'username' => 'wrong-user',
            'password' => 'wrong-pass',
        ]);

        $response->assertStatus(401);
        $this->assertSame('Credenciales inválidas.', $response->json('message'));
    }

    public function test_checkout_session_remains_available_until_payment_finishes(): void
    {
        $token = app(\App\Services\Payments\PaymentSessionService::class)->store('PAY-KEEP-SESSION', [
            'code' => 'PAY-KEEP-SESSION',
            'amount' => 100,
            'currency' => 'BOB',
            'user' => 'checkout-user',
            'provider' => 'cybersource',
        ], 15);

        $response = $this->get('/payments/' . $token);

        $response->assertOk();
        $this->assertSame('PAY-KEEP-SESSION', app(\App\Services\Payments\PaymentSessionService::class)->get($token)['code']);
    }

    public function test_it_issues_a_search_jwt_and_keeps_the_session_token_single_use(): void
    {
        DB::table('auth_user')->insert([
            [
                'username' => 'jwt-user',
                'first_name' => 'JWT',
                'last_name' => 'User',
                'email' => 'jwt-user@example.com',
                'password' => 'jwt-pass',
                'is_superuser' => false,
                'is_staff' => false,
                'is_active' => true,
                'date_joined' => now(),
                'customer_url' => 'https://client.example.com/jwt',
            ],
        ]);

        $sessionResponse = $this->postJson('/api/payments/session', [
            'code' => 'TOKEN-ONE-TIME',
            'amount' => 75,
            'currency' => 'BOB',
            'username' => 'jwt-user',
            'password' => 'jwt-pass',
        ]);

        $sessionResponse->assertOk();
        $sessionToken = $sessionResponse->json('token');
        $this->assertNotEmpty($sessionToken);

        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\Payments\PaymentSessionService::class)->consume($sessionToken);
        app(\App\Services\Payments\PaymentSessionService::class)->consume($sessionToken);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'jwt-user',
            'password' => 'jwt-pass',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');
        $this->assertNotEmpty($jwt);

        $searchResponse = $this->postJson('/api/payments/search', [
            'token' => $jwt,
        ]);

        $searchResponse->assertOk();
    }

    public function test_it_lists_customer_payments_by_date_range_with_valid_bearer_token(): void
    {
        config()->set('services.payment_api.username', 'demo-user');
        config()->set('services.payment_api.password', 'demo-pass');

        $userId = DB::table('auth_user')->insertGetId([
            'username' => 'search-user-bearer-range',
            'first_name' => 'Search',
            'last_name' => 'User',
            'email' => 'search-user-range@example.com',
            'password' => 'secret123',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/return',
        ]);

        $firstPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'BOOK-1001',
            'total' => '150.50',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'Search',
            'lastName' => 'User',
            'mail' => 'search-user@example.com',
            'description' => 'Pago prueba',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-01 08:00:00',
            'paymentDate' => '2026-08-01 08:30:00',
            'book' => '{}',
            'invoiceName' => 'Search User',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        $secondPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'BOOK-1002',
            'total' => '220.00',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'Search',
            'lastName' => 'User',
            'mail' => 'search-user@example.com',
            'description' => 'Otro pago',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-10 08:00:00',
            'paymentDate' => '2026-08-10 08:30:00',
            'book' => '{}',
            'invoiceName' => 'Search User',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        DB::table('payment_user')->insert([
            ['auth_user_id' => $userId, 'redirect_payment_id' => $firstPaymentId],
            ['auth_user_id' => $userId, 'redirect_payment_id' => $secondPaymentId],
        ]);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'search-user-bearer-range',
            'password' => 'secret123',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');

        $response = $this->postJson('/api/payments/search', [
            'from_date' => '2026-08-01',
            'to_date' => '2026-08-05',
        ], [
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('count'));
        $response->assertJsonCount(1, 'payments');
        $response->assertJsonCount(1, 'data');
        $this->assertSame('BOOK-1001', $response->json('payments.0.bookCode'));
        $this->assertSame('BOOK-1001', $response->json('data.0.bookCode'));
    }

    public function test_it_searches_directly_by_book_code_with_valid_bearer_token(): void
    {
        config()->set('services.payment_api.username', 'demo-user');
        config()->set('services.payment_api.password', 'demo-pass');

        $userId = DB::table('auth_user')->insertGetId([
            'username' => 'lookup-user',
            'first_name' => 'Lookup',
            'last_name' => 'User',
            'email' => 'lookup-user@example.com',
            'password' => 'secret456',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/lookup',
        ]);

        $paymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'BOOK-XYZ-789',
            'total' => '99.99',
            'currency' => 'USD',
            'service' => 'cybersource',
            'name' => 'Lookup',
            'lastName' => 'User',
            'mail' => 'lookup-user@example.com',
            'description' => 'Pago directo',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-12 10:00:00',
            'paymentDate' => '2026-08-12 10:15:00',
            'book' => '{}',
            'invoiceName' => 'Lookup User',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        DB::table('payment_user')->insert([
            ['auth_user_id' => $userId, 'redirect_payment_id' => $paymentId],
        ]);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'lookup-user',
            'password' => 'secret456',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');

        $response = $this->postJson('/api/payments/search', [
            'bookCode' => 'BOOK-XYZ-789',
        ], [
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('count'));
        $response->assertJsonCount(1, 'payments');
        $this->assertSame('BOOK-XYZ-789', $response->json('payments.0.bookCode'));
        $this->assertSame('BOOK-XYZ-789', $response->json('data.0.bookCode'));
    }

    public function test_it_lists_all_customer_payments_without_filters_using_bearer_token(): void
    {
        config()->set('services.payment_api.username', 'demo-user');
        config()->set('services.payment_api.password', 'demo-pass');

        $userId = DB::table('auth_user')->insertGetId([
            'username' => 'all-user',
            'first_name' => 'All',
            'last_name' => 'User',
            'email' => 'all-user@example.com',
            'password' => 'secret789',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/all',
        ]);

        $firstPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'ALL-001',
            'total' => '10.00',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'All',
            'lastName' => 'User',
            'mail' => 'all-user@example.com',
            'description' => 'Pago 1',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-15 09:00:00',
            'paymentDate' => '2026-08-15 09:05:00',
            'book' => '{}',
            'invoiceName' => 'All User',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        $secondPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'ALL-002',
            'total' => '20.00',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'All',
            'lastName' => 'User',
            'mail' => 'all-user@example.com',
            'description' => 'Pago 2',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-16 09:00:00',
            'paymentDate' => '2026-08-16 09:05:00',
            'book' => '{}',
            'invoiceName' => 'All User',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        DB::table('payment_user')->insert([
            ['auth_user_id' => $userId, 'redirect_payment_id' => $firstPaymentId],
            ['auth_user_id' => $userId, 'redirect_payment_id' => $secondPaymentId],
        ]);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'all-user',
            'password' => 'secret789',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');

        $response = $this->postJson('/api/payments/search', [], [
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(2, $response->json('count'));
        $response->assertJsonCount(2, 'payments');
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_lists_customer_payments_by_date_range_for_bearer_token_search(): void
    {
        config()->set('services.payment_api.username', 'demo-user');
        config()->set('services.payment_api.password', 'demo-pass');

        $userId = DB::table('auth_user')->insertGetId([
            'username' => 'client-date-user',
            'first_name' => 'Client',
            'last_name' => 'Date',
            'email' => 'client-date@example.com',
            'password' => 'clientsecret',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/date',
        ]);

        $paymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'CLIENT-DATE-01',
            'total' => '45.00',
            'currency' => 'BOB',
            'service' => 'cybersource',
            'name' => 'Client',
            'lastName' => 'Date',
            'mail' => 'client-date@example.com',
            'description' => 'Pago por rango',
            'paymentType' => 'cybersource',
            'postdata' => '{}',
            'response' => '{}',
            'extAuthorization' => '',
            'extErrorCode' => '',
            'extCode' => '',
            'extId' => '',
            'state' => '2',
            'creationDate' => '2026-08-20 12:00:00',
            'paymentDate' => '2026-08-20 12:10:00',
            'book' => '{}',
            'invoiceName' => 'Client Date',
            'invoiceNit' => '',
            'qrId' => '',
            'updated_at' => now(),
        ]);

        DB::table('payment_user')->insert([
            ['auth_user_id' => $userId, 'redirect_payment_id' => $paymentId],
        ]);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'client-date-user',
            'password' => 'clientsecret',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');

        $response = $this->postJson('/api/payments/search', [
            'from_date' => '2026-08-20',
            'to_date' => '2026-08-21',
        ], [
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(1, $response->json('count'));
        $this->assertSame('CLIENT-DATE-01', $response->json('payments.0.bookCode'));
    }

    public function test_it_returns_only_the_requested_payment_fields_with_status_label(): void
    {
        $userId = DB::table('auth_user')->insertGetId([
            'username' => 'filtered-user',
            'first_name' => 'Filtered',
            'last_name' => 'User',
            'email' => 'filtered-user@example.com',
            'password' => 'filtered-pass',
            'is_superuser' => false,
            'is_staff' => false,
            'is_active' => true,
            'date_joined' => now(),
            'customer_url' => 'https://client.example.com/filtered',
        ]);

        $paidPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'FILTER-PAID',
            'total' => '120.00',
            'currency' => 'BOB',
            'description' => 'Pago completado',
            'state' => '2',
            'creationDate' => '2026-08-25 08:00:00',
            'paymentDate' => '2026-08-25 08:10:00',
            'updated_at' => now(),
        ]);

        $pendingPaymentId = DB::table('redirect_payment')->insertGetId([
            'bookCode' => 'FILTER-PENDING',
            'total' => '75.50',
            'currency' => 'USD',
            'description' => 'Pago pendiente',
            'state' => '0',
            'creationDate' => '2026-08-26 09:00:00',
            'paymentDate' => null,
            'updated_at' => now(),
        ]);

        DB::table('payment_user')->insert([
            ['auth_user_id' => $userId, 'redirect_payment_id' => $paidPaymentId],
            ['auth_user_id' => $userId, 'redirect_payment_id' => $pendingPaymentId],
        ]);

        $jwtResponse = $this->postJson('/api/payments/search-token', [
            'username' => 'filtered-user',
            'password' => 'filtered-pass',
        ]);

        $jwtResponse->assertOk();
        $jwt = $jwtResponse->json('token');

        $response = $this->postJson('/api/payments/search', [], [
            'HTTP_Authorization' => 'Bearer ' . $jwt,
        ]);

        $response->assertOk();

        $bookCodes = array_map(fn (array $item) => $item['bookCode'], $response->json('data'));
        $statuses = array_map(fn (array $item) => $item['status'], $response->json('data'));

        $this->assertContains('FILTER-PAID', $bookCodes);
        $this->assertContains('FILTER-PENDING', $bookCodes);
        $this->assertContains('Pagado', $statuses);
        $this->assertContains('No Pagado', $statuses);

        $paidItem = collect($response->json('data'))->firstWhere('bookCode', 'FILTER-PAID');
        $pendingItem = collect($response->json('data'))->firstWhere('bookCode', 'FILTER-PENDING');

        $this->assertSame([
            'bookCode',
            'amount',
            'currency',
            'description',
            'status',
            'creationDate',
            'paymentDate',
        ], array_keys($paidItem));

        $this->assertSame('Pagado', $paidItem['status']);
        $this->assertSame('No Pagado', $pendingItem['status']);
        $this->assertArrayNotHasKey('id', $paidItem);
        $this->assertArrayNotHasKey('state', $paidItem);
    }
}
