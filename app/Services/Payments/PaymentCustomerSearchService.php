<?php

namespace App\Services\Payments;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class PaymentCustomerSearchService
{
    public function search(string $username, string $password, ?string $fromDate = null, ?string $toDate = null, ?string $bookCode = null): array
    {
        $user = $this->resolveUser($username, $password);

        return $this->searchByUserId((int) $user->id, $fromDate, $toDate, $bookCode);
    }

    public function searchByUserId(int $userId, ?string $fromDate = null, ?string $toDate = null, ?string $bookCode = null): array
    {
        $user = DB::table(Schema::hasTable('auth_user') ? 'auth_user' : 'users')->find($userId);

        if (! $user) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        $query = DB::table('payment_user as pu')
            ->join('redirect_payment as rp', 'rp.id', '=', 'pu.redirect_payment_id')
            ->where('pu.auth_user_id', $userId)
            ->select('rp.*');

        $normalizedBookCode = filled($bookCode) ? trim((string) $bookCode) : null;

        if (filled($normalizedBookCode)) {
            $this->applyBookCodeFilter($query, $normalizedBookCode);
        } elseif (filled($fromDate) || filled($toDate)) {
            $this->applyDateRange($query, $fromDate, $toDate);
        }

        $payments = $query
            ->orderBy('rp.creationDate', 'desc')
            ->orderBy('rp.id', 'desc')
            ->get()
            ->map(fn ($row) => $this->normalizePayment($row))
            ->all();

        return [
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'email' => $user->email,
            ],
            'count' => count($payments),
            'data' => $payments,
        ];
    }

    public function resolveUser(string $username, string $password): object
    {
        $table = Schema::hasTable('auth_user') ? 'auth_user' : 'users';
        $user = DB::table($table)->where('username', trim($username))->first();

        if (! $user) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        $storedPassword = (string) ($user->password ?? '');

        $matches = false;
        if ($storedPassword !== '') {
            try {
                $matches = Hash::check($password, $storedPassword);
            } catch (\RuntimeException $e) {
                $matches = false;
            }

            if (! $matches) {
                $matches = hash_equals($storedPassword, $password);
            }
        }

        if (! $matches) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        return $user;
    }

    protected function applyDateRange($query, ?string $fromDate, ?string $toDate): void
    {
        $from = $this->parseDate($fromDate, 'start');
        $to = $this->parseDate($toDate, 'end');

        if ($from !== null) {
            $query->where(function ($subQuery) use ($from) {
                $subQuery->where('rp.creationDate', '>=', $from->format('Y-m-d H:i:s'))
                    ->orWhere('rp.paymentDate', '>=', $from->format('Y-m-d H:i:s'));
            });
        }

        if ($to !== null) {
            $query->where(function ($subQuery) use ($to) {
                $subQuery->where('rp.creationDate', '<=', $to->format('Y-m-d H:i:s'))
                    ->orWhere('rp.paymentDate', '<=', $to->format('Y-m-d H:i:s'));
            });
        }
    }

    protected function applyBookCodeFilter($query, string $bookCode): void
    {
        $normalizedBookCode = strtolower(trim($bookCode));

        $query->where(function ($subQuery) use ($normalizedBookCode) {
            $hasBookCodeColumn = Schema::hasColumn('redirect_payment', 'bookCode');
            $hasCodeColumn = Schema::hasColumn('redirect_payment', 'code');
            $hasPaymentCodeColumn = Schema::hasColumn('redirect_payment', 'payment_code');

            if ($hasBookCodeColumn) {
                $subQuery->orWhereRaw('LOWER(rp.bookCode) = ?', [$normalizedBookCode]);
            }

            if ($hasCodeColumn) {
                $subQuery->orWhereRaw('LOWER(rp.code) = ?', [$normalizedBookCode]);
            }

            if ($hasPaymentCodeColumn) {
                $subQuery->orWhereRaw('LOWER(rp.payment_code) = ?', [$normalizedBookCode]);
            }

            if (! $hasBookCodeColumn && ! $hasCodeColumn && ! $hasPaymentCodeColumn) {
                $subQuery->orWhereRaw('LOWER(COALESCE(rp.bookCode, "")) = ?', [$normalizedBookCode]);
            }
        });
    }

    protected function parseDate(?string $value, string $boundary): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $date = Carbon::parse($value);

        return $boundary === 'start' ? $date->startOfDay() : $date->endOfDay();
    }

    protected function normalizePayment(object $row): array
    {
        $state = (string) ($row->state ?? '0');

        return [
            'bookCode' => $row->bookCode ?? $row->code ?? $row->payment_code ?? null,
            'amount' => $row->total ?? $row->amount ?? null,
            'currency' => $row->currency ?? null,
            'description' => $row->description ?? null,
            'status' => $state === '2' ? 'Pagado' : 'No Pagado',
            'creationDate' => $row->creationDate ?? null,
            'paymentDate' => $row->paymentDate ?? null,
        ];
    }
}
