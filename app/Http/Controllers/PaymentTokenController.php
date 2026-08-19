<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentCustomerSearchService;
use App\Services\Payments\PaymentSearchTokenService;
use App\Services\Payments\PaymentTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentTokenController extends Controller
{
    public function __construct(
        private readonly PaymentTokenService $paymentTokenService,
        private readonly PaymentCustomerSearchService $paymentCustomerSearchService,
        private readonly PaymentSearchTokenService $paymentSearchTokenService,
    ) {
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'provider' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $result = $this->paymentTokenService->issue(
                $data['code'],
                (float) $data['amount'],
                $data['currency'],
                $data['username'],
                $data['password'],
                $data['provider'] ?? null,
            );

            return response()->json($result, 200);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'credencial') ? 401 : 422;

            return response()->json(['message' => $message], $status);
        }
    }

    public function issueSearchToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        try {
            $token = $this->paymentSearchTokenService->issue(
                (string) $data['username'],
                (string) $data['password'],
            );

            return response()->json(['success' => true, 'token' => $token, 'expires_in' => 900], 200);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 401);
        }
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_date' => ['nullable', 'string'],
            'to_date' => ['nullable', 'string'],
            'fromDate' => ['nullable', 'string'],
            'toDate' => ['nullable', 'string'],
            'bookCode' => ['nullable', 'string', 'max:100'],
            'book_code' => ['nullable', 'string', 'max:100'],
            'payment_code' => ['nullable', 'string', 'max:100'],
            'token' => ['nullable', 'string'],
        ]);

        try {
            $token = $request->bearerToken() ?: (string) ($data['token'] ?? '');

            if ($token === '') {
                throw new InvalidArgumentException('Token no proporcionado. Envíe el token en la cabecera Authorization: Bearer <token>.');
            }

            $user = $this->paymentSearchTokenService->validate($token);

            $fromDate = $data['from_date'] ?? $data['fromDate'] ?? null;
            $toDate = $data['to_date'] ?? $data['toDate'] ?? null;
            $bookCode = $data['bookCode'] ?? $data['book_code'] ?? $data['payment_code'] ?? null;

            $result = $this->paymentCustomerSearchService->searchByUserId(
                (int) $user->id,
                $fromDate,
                $toDate,
                $bookCode,
            );

            $payload = [
                'success' => true,
                'user' => $result['user'] ?? null,
                'count' => $result['count'] ?? 0,
                'payments' => $result['data'] ?? [],
                'data' => $result['data'] ?? [],
            ];

            return response()->json($payload, 200);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'token') || str_contains(strtolower($message), 'credencial') ? 401 : 422;

            return response()->json(['message' => $message], $status);
        }
    }
}
