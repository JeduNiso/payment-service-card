<?php

namespace App\Services\Payments;

interface LegacyUserPermissionProvider
{
    /**
     * Devuelve la configuración real o mockeada de permisos del usuario.
     * Debe devolver un arreglo con al menos:
     * [
     *   'provider' => 'cybersource',
     *   'status' => 'active',
     *   'roles' => ['payments:charge']
     * ]
     */
    public function getUserPermissions(string $username): ?array;
}
