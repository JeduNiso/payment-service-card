<?php

namespace App\Services\CyberSource;

use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CyberSourceService
{
    public function authenticationSetup(array $frontendData, string $cardNumber): JsonResponse
    {
        $randomCode = 'Pago_comercio_' . random_int(100000, 999999);
        $jsonString = $this->jsonDumps([
            'clientReferenceInformation' => [
                'code' => $randomCode,
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $cardNumber,
                    'expirationMonth' => str_pad((string) $frontendData['expiry_month'], 2, '0', STR_PAD_LEFT),
                    'expirationYear' => (string) $frontendData['expiry_year'],
                    'type' => $this->resolveCardType($cardNumber),
                ],
            ],
        ]);

        $response = $this->sendSignedRequest(
            '/risk/v1/authentication-setups',
            $jsonString,
            config('services.cybersource.key_id'),
            config('services.cybersource.secret_key'),
            config('services.cybersource.merchant_id')
        );

        return response()->json($response['body'] ?? ['raw' => $response], $response['status'] ?? 200);
    }

    public function enrollmentSetup(string $referenceId, array $paymentData, Booking $booking, array $browserInfo = []): JsonResponse
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => $booking->booking_code,
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'currency' => 'BOB',
                    'totalAmount' => number_format((float) $booking->total_amount, 2, '.', ''),
                ],
                'billTo' => [
                    'address1' => $paymentData['billing_address1'],
                    'administrativeArea' => 'L',
                    'country' => 'BO',
                    'locality' => $paymentData['billing_locality'],
                    'firstName' => $paymentData['billing_first_name'],
                    'lastName' => $paymentData['billing_last_name'],
                    'phoneNumber' => $paymentData['billing_phone'],
                    'email' => $paymentData['billing_email'],
                    'postalCode' => '000000',
                ],
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $paymentData['card_number'],
                    'expirationMonth' => $paymentData['expiry_month'],
                    'expirationYear' => $paymentData['expiry_year'],
                    'type' => $this->resolveCardType($paymentData['card_number']),
                ],
            ],
            'consumerAuthenticationInformation' => [
                'referenceId' => $referenceId,
                'returnUrl' => rtrim((string) config('app.url'), '/') . '/api/payments/' . rawurlencode($booking->booking_code) . '/enrollment-result',
                'transactionMode' => 'S',
            ],
            'browserInformation' => [
                'acceptHeader' => $browserInfo['acceptHeader'] ?? 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'language' => $browserInfo['language'] ?? 'es-BO',
                'userAgent' => $browserInfo['userAgent'] ?? 'Mozilla/5.0',
                'screenHeight' => (int) ($browserInfo['screenHeight'] ?? 768),
                'screenWidth' => (int) ($browserInfo['screenWidth'] ?? 1366),
                'javaEnabled' => (bool) ($browserInfo['javaEnabled'] ?? false),
                'javascriptEnabled' => true,
            ],
        ];

        $jsonString = $this->jsonDumps($payload);
        $response = $this->sendSignedRequest(
            '/risk/v1/authentications',
            $jsonString,
            config('services.cybersource.key_id'),
            config('services.cybersource.secret_key'),
            config('services.cybersource.merchant_id')
        );

        return response()->json($response['body'] ?? ['raw' => $response], $response['status'] ?? 200);
    }

    public function validAuthDynamic(string $authTransactionId, array $paymentData, Booking $booking): JsonResponse
    {
        $payload = [
            'clientReferenceInformation' => [
                'code' => $booking->booking_code,
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'currency' => 'BOB',
                    'totalAmount' => number_format((float) $booking->total_amount, 2, '.', ''),
                ],
                'billTo' => [
                    'address1' => $paymentData['billing_address1'],
                    'administrativeArea' => 'L',
                    'country' => 'BO',
                    'locality' => $paymentData['billing_locality'],
                    'firstName' => $paymentData['billing_first_name'],
                    'lastName' => $paymentData['billing_last_name'],
                    'phoneNumber' => $paymentData['billing_phone'],
                    'email' => $paymentData['billing_email'],
                    'postalCode' => '000000',
                ],
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $paymentData['card_number'],
                    'expirationMonth' => $paymentData['expiry_month'],
                    'expirationYear' => $paymentData['expiry_year'],
                    'type' => $this->resolveCardType($paymentData['card_number']),
                ],
            ],
            'consumerAuthenticationInformation' => [
                'authenticationTransactionId' => $authTransactionId,
            ],
        ];

        $jsonString = $this->jsonDumps($payload);
        $response = $this->sendSignedRequest(
            '/risk/v1/authentication-results',
            $jsonString,
            config('services.cybersource.key_id'),
            config('services.cybersource.secret_key'),
            config('services.cybersource.merchant_id')
        );

        return response()->json($response['body'] ?? ['raw' => $response], $response['status'] ?? 200);
    }

    public function paymentWith3dsDynamic(array $paymentData, Booking $booking, array $authData, string $type, ?array $context = null): JsonResponse
    {
        $consumerAuth = $authData['consumerAuthenticationInformation'] ?? [];
        $commerceIndicator = ($type === '3DS')
            ? ($consumerAuth['indicator'] ?? 'internet')
            : ($consumerAuth['ecommerceIndicator'] ?? 'internet');
        $context = is_array($context) ? $context : ($paymentData['context'] ?? []);
        $realSeq = explode('redenlace_400037', trim((string) ($context['seqNum'] ?? '')))[1] ?? '';

        $passengers = $booking->passengers ?? [];
        if ($passengers instanceof \Illuminate\Support\Collection) {
            $firstPassenger = $passengers->first();
        } elseif (is_array($passengers)) {
            $firstPassenger = $passengers[0] ?? null;
        } else {
            $firstPassenger = null;
        }

        $destination = $booking->schedule?->busRoute?->destinationCity?->name ?? '';

        $payload = [
            'clientReferenceInformation' => [
                'code' => $booking->booking_code,
            ],
            'processingInformation' => [
                'capture' => true,
                'commerceIndicator' => $commerceIndicator,
            ],
            'paymentInformation' => [
                'card' => [
                    'number' => $paymentData['card_number'],
                    'expirationMonth' => $paymentData['expiry_month'],
                    'expirationYear' => $paymentData['expiry_year'],
                    'type' => $this->resolveCardType($paymentData['card_number']),
                    'securityCode' => $paymentData['cvv'],
                ],
            ],
            'orderInformation' => [
                'amountDetails' => [
                    'totalAmount' => number_format((float) $booking->total_amount, 2, '.', ''),
                    'currency' => 'BOB',
                ],
                'billTo' => [
                    'address1' => $paymentData['billing_address1'],
                    'administrativeArea' => 'L',
                    'country' => 'BO',
                    'locality' => $paymentData['billing_locality'],
                    'firstName' => $paymentData['billing_first_name'],
                    'lastName' => $paymentData['billing_last_name'],
                    'phoneNumber' => $paymentData['billing_phone'],
                    'email' => $paymentData['billing_email'],
                ],
            ],
            'merchantDefinedInformation' => [
                ['key' => '4', 'value' => '050220'],
                ['key' => '6', 'value' => 'false'],
                ['key' => '11', 'value' => $paymentData['billing_email'] ?? ''],
                ['key' => '17', 'value' => 'false'],
                ['key' => '18', 'value' => ''],
                ['key' => '51', 'value' => 'false'],
                ['key' => '52', 'value' => $firstPassenger ? ($firstPassenger->first_name . ' ' . $firstPassenger->last_name) : ''],
                ['key' => '54', 'value' => $firstPassenger ? (string) $firstPassenger->document_number : ''],
                ['key' => '59', 'value' => '2'],
                ['key' => '60', 'value' => $destination],
                ['key' => '61', 'value' => 'BOLIVIA'],
            ],
            'deviceInformation' => [
                'fingerprintSessionId' => $realSeq !== '' ? $realSeq : 'redenlace_400037',
            ],
            'consumerAuthenticationInformation' => [
                'cavv' => $consumerAuth['cavv'] ?? '',
                'xid' => $consumerAuth['xid'] ?? '',
                'ucafCollectionIndicator' => $consumerAuth['ucafCollectionIndicator'] ?? '',
                'ucafAuthenticationData' => $consumerAuth['ucafAuthenticationData'] ?? '',
                'directoryServerTransactionId' => $consumerAuth['directoryServerTransactionId'] ?? '',
                'paSpecificationVersion' => $consumerAuth['specificationVersion'] ?? '2.2.0',
            ],
        ];

        $jsonString = $this->jsonDumps($payload);
        $response = $this->sendSignedRequest(
            '/pts/v2/payments/',
            $jsonString,
            config('services.cybersource.key_id'),
            config('services.cybersource.secret_key'),
            config('services.cybersource.merchant_id')
        );

        return response()->json($response['body'] ?? ['raw' => $response], $response['status'] ?? 200);
    }

    protected function sendSignedRequest(string $endpoint, string $jsonString, ?string $keyId, ?string $secretKey, ?string $merchantId): array
    {
        if (blank($keyId) || blank($secretKey) || blank($merchantId)) {
            throw new RuntimeException('CyberSource credentials are not configured.');
        }

        $dateTransaction = $this->getDate();
        $bluePrint = $this->getBlueprint($jsonString);
        $headersString = $this->getHeadersString($dateTransaction, $bluePrint, $endpoint, $merchantId);
        $signatureHash = $this->getSignatureHash($headersString, $secretKey);
        $signature = $this->getSignature($signatureHash, $keyId);

        $response = Http::withHeaders([
            'Host' => 'api.cybersource.com',
            'Date' => $dateTransaction,
            'Digest' => 'SHA-256=' . $bluePrint,
            'v-c-merchant-id' => $merchantId,
            'Signature' => $signature,
            'Content-Type' => 'application/json',
            'Accept' => 'application/hal+json;charset=utf-8',
        ])->withBody($jsonString, 'application/json')->post(config('services.cybersource.api_url') . $endpoint);

        $body = $response->json();

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : ['raw' => $response->body()],
        ];
    }

    protected function getDate(): string
    {
        return gmdate('D, d M Y H:i:s') . ' GMT';
    }

    protected function getBlueprint(string $jsonString): string
    {
        return base64_encode(hash('sha256', $jsonString, true));
    }

    protected function getHeadersString(string $dateTransaction, string $bluePrint, string $endpoint, string $merchantId): string
    {
        $headers = "host: api.cybersource.com\n";
        $headers .= 'date: ' . $dateTransaction . "\n";
        $headers .= '(request-target): post ' . $endpoint . "\n";
        $headers .= 'digest: SHA-256=' . $bluePrint . "\n";
        $headers .= 'v-c-merchant-id: ' . $merchantId;

        return $headers;
    }

    protected function getSignatureHash(string $headers, string $secretKey): string
    {
        $secret = base64_decode($secretKey, true);
        if ($secret === false) {
            throw new RuntimeException('Invalid CyberSource secret key.');
        }

        return base64_encode(hash_hmac('sha256', $headers, $secret, true));
    }

    protected function getSignature(string $signatureHash, string $keyId): string
    {
        return 'keyid="' . $keyId . '", algorithm="HmacSHA256", headers="host date (request-target) digest v-c-merchant-id", signature="' . $signatureHash . '"';
    }

    protected function jsonDumps(array $data): string
    {
        $data = $this->ksortRecursive($data);

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    protected function ksortRecursive(array $data): array
    {
        if ($this->isAssoc($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->ksortRecursive($value);
            }
        }

        return $data;
    }

    protected function isAssoc(array $data): bool
    {
        return array_keys($data) !== range(0, count($data) - 1);
    }

    protected function resolveCardType(string $cardNumber): string
    {
        $normalized = preg_replace('/\D+/', '', $cardNumber) ?? '';

        if ($normalized === '') {
            return '001';
        }

        if (preg_match('/^3[47][0-9]{13}$/', $normalized)) {
            return '003';
        }

        if (preg_match('/^5[1-5][0-9]{14}$/', $normalized)
            || preg_match('/^2(2[2-9][0-9]{12}|[3-6][0-9]{13}|7[01][0-9]{12}|720[0-9]{12})$/', $normalized)) {
            return '002';
        }

        return '001';
    }
}
