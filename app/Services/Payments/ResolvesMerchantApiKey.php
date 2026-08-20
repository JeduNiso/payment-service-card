<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Looks up the auth_user that owns a given merchant_notification_api_key.
 *
 * This is the same static, per-merchant secret already used to authenticate outbound
 * webhook calls (see CommerceNotificationService usage in CybersourceController). Reusing
 * it here — sent as `Authorization: Bearer <key>` — means machine-to-machine integrations
 * authenticate with one dedicated, rotatable secret per merchant instead of a real user
 * password, which was never meant to be sent on every request.
 */
trait ResolvesMerchantApiKey
{
    protected function resolveUserByApiKey(string $apiKey): object
    {
        $apiKey = trim($apiKey);

        if ($apiKey === '') {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        $table = Schema::hasTable('auth_user') ? 'auth_user' : 'users';

        $user = DB::table($table)
            ->where('merchant_notification_api_key', $apiKey)
            ->first();

        if ($user === null) {
            throw new InvalidArgumentException('Credenciales inválidas.');
        }

        return $user;
    }
}
