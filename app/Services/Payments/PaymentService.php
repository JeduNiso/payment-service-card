<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PaymentService
{
    public function buildPaymentContext(string $code): array
    {
        $merchantPrefix = config('services.cybersource.merchant_id', 'redenlace_400037');
        $orgId = config('services.cybersource.device_org_id', '1snn5n9w');

        $seqnum = $this->resolveSeqNum();

        $context = [
            'seqNum' => $merchantPrefix . (string) $seqnum,
            'id' => $orgId,
        ];

        return [
            'code' => $code,
            'seqnum' => $seqnum,
            'context' => $context,
        ];
    }

    public function normalizeCardNumber(string $cardNumber): string
    {
        return preg_replace('/\D/', '', $cardNumber) ?? '';
    }

    protected function resolveSeqNum(): int
    {
        try {
            if (Schema::hasTable('seguence')) {
                $seqnum = DB::table('seguence')->orderByDesc('seqNumber')->value('seqNumber');

                if (is_numeric($seqnum)) {
                    return (int) $seqnum;
                }
            }
        } catch (\Throwable $e) {
            // Legacy table is not available in the current environment; fall back to cache.
        }

        $seqnum = Cache::get('payment.seqnum', 0);

        if (! is_numeric($seqnum)) {
            $seqnum = 0;
        }

        $nextSeq = (int) $seqnum + 1;
        Cache::put('payment.seqnum', $nextSeq, now()->addDays(7));

        return $nextSeq;
    }
}
