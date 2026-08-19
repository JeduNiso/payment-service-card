<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MockLegacyUserPermissionProvider implements LegacyUserPermissionProvider
{
    public function getUserPermissions(string $username): ?array
    {
        $username = trim((string) $username);
        if ($username === '') {
            return null;
        }

        $user = $this->resolveUserRecord($username);

        if (is_array($user) && $user !== []) {
            return $this->normalizePermissionsRecord($user);
        }

        $mock = config('services.payment_permissions', []);
        $lookup = strtolower($username);

        if (! is_array($mock) || ! isset($mock[$lookup])) {
            return null;
        }

        $userPermissions = $mock[$lookup];

        if (! is_array($userPermissions)) {
            return null;
        }

        return [
            'provider' => strtolower((string) ($userPermissions['provider'] ?? 'cybersource')),
            'roles' => is_array($userPermissions['roles'] ?? null) ? $userPermissions['roles'] : [],
            'status' => strtolower((string) ($userPermissions['status'] ?? 'inactive')),
            'legacy_user_id' => $userPermissions['legacy_user_id'] ?? null,
            'customer_url' => $userPermissions['customer_url'] ?? null,
        ];
    }

    protected function resolveUserRecord(string $username): ?array
    {
        $table = $this->resolveUserTable();

        if (! Schema::hasTable($table)) {
            return null;
        }

        $record = DB::table($table)
            ->where(function ($query) use ($username) {
                $candidate = strtolower($username);
                $query->whereRaw('LOWER(username) = ?', [$candidate])
                    ->orWhereRaw('LOWER(email) = ?', [$candidate])
                    ->orWhereRaw('LOWER(first_name) = ?', [$candidate])
                    ->orWhereRaw('LOWER(last_name) = ?', [$candidate]);
            })
            ->first();

        if (! is_object($record)) {
            return null;
        }

        return (array) $record;
    }

    protected function normalizePermissionsRecord(array $record): array
    {
        $roles = $record['roles'] ?? $record['permissions'] ?? [];
        if (is_string($roles)) {
            $roles = json_decode($roles, true);
        }

        $status = strtolower((string) ($record['status'] ?? 'active'));
        $provider = strtolower((string) ($record['provider'] ?? 'cybersource'));

        return [
            'provider' => $provider === '' ? 'cybersource' : $provider,
            'roles' => is_array($roles) ? $roles : [],
            'status' => $status === '' ? 'active' : $status,
            'legacy_user_id' => $record['legacy_user_id'] ?? $record['id'] ?? null,
            'customer_url' => $record['customer_url'] ?? $record['redirect_url'] ?? null,
        ];
    }

    protected function resolveUserTable(): string
    {
        if (Schema::hasTable('auth_user')) {
            return 'auth_user';
        }

        return 'users';
    }
}
