<?php

namespace App\Http\Controllers;

use App\Services\Payments\PaymentCustomerSearchService;
use App\Services\Payments\PaymentTokenService;
use App\Services\Payments\ResolvesMerchantApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PaymentTokenController extends Controller
{
    use ResolvesMerchantApiKey;

    public function __construct(
        private readonly PaymentTokenService $paymentTokenService,
        private readonly PaymentCustomerSearchService $paymentCustomerSearchService,
    ) {
    }

    public function issue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $result = $this->paymentTokenService->issue(
                $data['code'],
                (float) $data['amount'],
                $data['currency'],
                (string) $request->bearerToken(),
                $data['provider'] ?? null,
            );

            return response()->json($result, 200);
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'credencial') ? 401 : 422;

            return response()->json(['message' => $message], $status);
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
        ]);

        try {
            $user = $this->resolveUserByApiKey((string) $request->bearerToken());

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
