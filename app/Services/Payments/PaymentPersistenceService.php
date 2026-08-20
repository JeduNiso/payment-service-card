<?php

namespace App\Services\Payments;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentPersistenceService
{
    public function store(array $data): Payment
    {
        $payload = $this->normalizePayload($data);

        $payment = DB::transaction(function () use ($payload) {
            $table = (new Payment())->getTable();
            $stateValue = $this->resolveStateValue($payload['status']);
            $provider = strtolower((string) ($payload['provider'] ?? 'cybersource'));
            $email = (string) ($payload['email'] ?? 'no-reply@example.com');
            $name = (string) ($payload['name'] ?? 'Cliente');
            $lastName = (string) ($payload['last_name'] ?? 'Pago');
            $description = (string) ($payload['description'] ?? 'Pago online');
            $transactionId = (string) ($payload['transaction_id'] ?? $payload['reference'] ?? '');
            $reference = (string) ($payload['reference'] ?? $payload['transaction_id'] ?? '');
            $now = now();

            $attributes = [];

            if (Schema::hasColumn($table, 'bookCode')) {
                $attributes['bookCode'] = $payload['code'];
            }
            if (Schema::hasColumn($table, 'code')) {
                $attributes['code'] = $payload['code'];
            }
            if (Schema::hasColumn($table, 'payment_code')) {
                $attributes['payment_code'] = $payload['code'];
            }
            if (Schema::hasColumn($table, 'total')) {
                $attributes['total'] = (string) $payload['amount'];
            }
            if (Schema::hasColumn($table, 'amount')) {
                $attributes['amount'] = (float) $payload['amount'];
            }
            if (Schema::hasColumn($table, 'currency')) {
                $attributes['currency'] = strtoupper((string) ($payload['currency'] ?? 'BOB'));
            }
            if (Schema::hasColumn($table, 'service')) {
                $attributes['service'] = $provider;
            }
            if (Schema::hasColumn($table, 'state')) {
                $attributes['state'] = (string) $stateValue;
            }
            if (Schema::hasColumn($table, 'status')) {
                $attributes['status'] = $this->normalizeStatusLabel($payload['status']);
            }
            if (Schema::hasColumn($table, 'paymentType')) {
                $attributes['paymentType'] = $provider;
            }
            if (Schema::hasColumn($table, 'description')) {
                $attributes['description'] = $description;
            }
            if (Schema::hasColumn($table, 'email')) {
                $attributes['email'] = $email;
            }
            if (Schema::hasColumn($table, 'mail')) {
                $attributes['mail'] = $email;
            }
            if (Schema::hasColumn($table, 'name')) {
                $attributes['name'] = $name;
            }
            if (Schema::hasColumn($table, 'lastName')) {
                $attributes['lastName'] = $lastName;
            }
            if (Schema::hasColumn($table, 'extCode')) {
                $attributes['extCode'] = $transactionId !== '' ? $transactionId : '';
            }
            if (Schema::hasColumn($table, 'extId')) {
                $attributes['extId'] = $reference !== '' ? $reference : '';
            }
            if (Schema::hasColumn($table, 'postdata')) {
                $attributes['postdata'] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
            if (Schema::hasColumn($table, 'response')) {
                $attributes['response'] = json_encode(['status' => $payload['status'], 'code' => $payload['code']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
            if (Schema::hasColumn($table, 'extAuthorization')) {
                $attributes['extAuthorization'] = '';
            }
            if (Schema::hasColumn($table, 'extErrorCode')) {
                $attributes['extErrorCode'] = '';
            }
            if (Schema::hasColumn($table, 'creationDate')) {
                $attributes['creationDate'] = $now->format('Y-m-d H:i:s');
            }
            if (Schema::hasColumn($table, 'paymentDate')) {
                $attributes['paymentDate'] = $now->format('Y-m-d H:i:s');
            }
            if (Schema::hasColumn($table, 'book')) {
                $attributes['book'] = json_encode(['code' => $payload['code'], 'status' => $payload['status']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            }
            if (Schema::hasColumn($table, 'invoiceName')) {
                $attributes['invoiceName'] = trim($name . ' ' . $lastName) ?: 'Cliente';
            }
            if (Schema::hasColumn($table, 'invoiceNit')) {
                $attributes['invoiceNit'] = '';
            }
            if (Schema::hasColumn($table, 'qrId')) {
                $attributes['qrId'] = '';
            }
            if (Schema::hasColumn($table, 'updated_at')) {
                $attributes['updated_at'] = $now;
            }

            $payment = Payment::query()->create($attributes);

            $userId = $payload['user_id'] ?? null;
            if ($userId !== null && $this->canLinkUser($userId)) {
                $payment->users()->syncWithoutDetaching([(int) $userId]);
            }

            $customerUrl = $payload['customer_url'] ?? null;
            if ($userId !== null && is_string($customerUrl) && trim($customerUrl) !== '' && $this->canLinkUser((int) $userId)) {
                DB::table('payment_user')
                    ->where('auth_user_id', (int) $userId)
                    ->where('redirect_payment_id', $payment->getKey())
                    ->update(['customer_url' => trim($customerUrl)]);
            }

            return $payment->fresh();
        });

        return $payment;
    }

    /**
     * Look up an already-persisted payment record for this code, regardless of its
     * outcome (paid or error). Used to decide whether a code has already been used for
     * a payment attempt, so a fresh session token should no longer be issued for it.
     */
    public function findByCode(string $code): ?Payment
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }

        $table = (new Payment())->getTable();

        return Payment::query()->where(function ($query) use ($code, $table) {
            if (Schema::hasColumn($table, 'bookCode')) {
                $query->where('bookCode', $code);
            }
            if (Schema::hasColumn($table, 'code')) {
                $query->orWhere('code', $code);
            }
            if (Schema::hasColumn($table, 'payment_code')) {
                $query->orWhere('payment_code', $code);
            }
        })->first();
    }

    public function updateStatus(string $code, array $data): Payment
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            throw new \InvalidArgumentException('El código del pago es obligatorio.');
        }

        $table = (new Payment())->getTable();
        $resolvedCode = $code;
        $payment = $this->findByCode($code);

        if (! $payment) {
            return $this->store([
                'code' => $code,
                'amount' => $data['amount'] ?? 0,
                'currency' => $data['currency'] ?? 'BOB',
                'status' => $data['status'] ?? ($data['state'] ?? 'pending'),
                'state' => $data['state'] ?? null,
                'provider' => $data['provider'] ?? 'cybersource',
                'user_id' => $data['user_id'] ?? null,
                'customer_url' => $data['customer_url'] ?? $data['redirect_url'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? $data['reference'] ?? null,
                'reference' => $data['reference'] ?? $data['transaction_id'] ?? null,
            ]);
        }

        $stateValue = $this->resolveStateValue($data['state'] ?? $data['status'] ?? $payment->state ?? $payment->status ?? 1);
        $customerUrl = $data['customer_url'] ?? $data['redirect_url'] ?? $payment->customer_url ?? null;
        $transactionId = $data['transaction_id'] ?? $data['reference'] ?? $payment->transaction_id ?? $payment->reference ?? null;
        $userId = $data['user_id'] ?? $payment->users()->first()?->getKey() ?? $payment->user_id ?? null;

        $attributes = [];

        if (Schema::hasColumn($table, 'state')) {
            $attributes['state'] = (string) $stateValue;
        }
        if (Schema::hasColumn($table, 'status')) {
            $attributes['status'] = $this->normalizeStatusLabel($data['status'] ?? $payment->status ?? 'pending');
        }
        if (Schema::hasColumn($table, 'transaction_id')) {
            $attributes['transaction_id'] = $transactionId;
        }
        if (Schema::hasColumn($table, 'reference')) {
            $attributes['reference'] = $transactionId;
        }
        if (Schema::hasColumn($table, 'extCode')) {
            $attributes['extCode'] = $transactionId;
        }
        if (Schema::hasColumn($table, 'extId')) {
            $attributes['extId'] = $transactionId;
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $attributes['updated_at'] = now();
        }

        $payment->fill($attributes);
        $payment->save();

        if ($userId !== null && $this->canLinkUser((int) $userId)) {
            $payment->users()->syncWithoutDetaching([(int) $userId]);
        }

        if ($userId !== null && $this->canLinkUser((int) $userId) && is_string($customerUrl) && trim($customerUrl) !== '') {
            DB::table('payment_user')
                ->where('auth_user_id', (int) $userId)
                ->where('redirect_payment_id', $payment->getKey())
                ->update(['customer_url' => trim($customerUrl)]);
        }

        return $payment->fresh();
    }

    protected function normalizePayload(array $data): array
    {
        $rawCode = (string) ($data['code'] ?? $data['payment_code'] ?? '');
        $state = $data['state'] ?? $data['status'] ?? 'pending';
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : null;

        $userData = null;
        if ($userId !== null) {
            $userTable = DB::table('auth_user')->exists() ? 'auth_user' : 'users';
            $userData = DB::table($userTable)->where('id', $userId)->first();
        }

        $email = $data['email'] ?? $data['mail'] ?? $data['billing_email'] ?? ($userData->email ?? 'no-reply@example.com');
        $name = $data['name'] ?? $data['billing_first_name'] ?? ($userData->first_name ?? 'Cliente');
        $lastName = $data['last_name'] ?? $data['billing_last_name'] ?? ($userData->last_name ?? 'Pago');
        $description = $data['description'] ?? $data['payment_description'] ?? 'Pago online';

        return [
            'code' => strtoupper(trim($rawCode)),
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => strtoupper(trim((string) ($data['currency'] ?? 'BOB'))),
            'status' => $state,
            'provider' => strtolower(trim((string) ($data['provider'] ?? 'cybersource'))),
            'user_id' => $userId,
            'customer_url' => $data['customer_url'] ?? null,
            'description' => $description,
            'transaction_id' => $data['transaction_id'] ?? $data['reference'] ?? null,
            'reference' => $data['reference'] ?? $data['transaction_id'] ?? null,
            'email' => $email,
            'mail' => $email,
            'name' => $name,
            'last_name' => $lastName,
        ];
    }

    protected function resolveStateValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'not_paid', 'unpaid', 'pending', 'new', 'created', 'idle', '0' => 0,
            'processing', 'in_process', 'in-progress', 'pending_auth', 'authorizing', '1' => 1,
            'approved', 'authorized', 'paid', 'success', 'completed', '2' => 2,
            'failed', 'rejected', 'cancelled', 'canceled', 'error', '3' => 3,
            default => 0,
        };
    }

    protected function normalizeStatusLabel(mixed $value): string
    {
        return match ($this->resolveStateValue($value)) {
            0 => 'pending',
            1 => 'processing',
            2 => 'paid',
            3 => 'error',
            default => 'pending',
        };
    }

    protected function canLinkUser(int $userId): bool
    {
        if (! Schema::hasTable('payment_user')) {
            return false;
        }

        return User::query()->where('id', $userId)->exists();
    }
}
