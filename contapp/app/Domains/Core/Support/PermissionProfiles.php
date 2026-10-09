<?php

namespace App\Domains\Core\Support;

use App\Domains\Core\Services\ScreenAccessService;

/**
 * Los perfiles de permisos de config/permission_profiles.php (Contador,
 * Vendedor…), ya pasados a un nivel por cada pantalla de ScreenCatalog, para
 * precargar el editor de permisos al invitar o al editar. No se guardan: lo
 * que se guarda son los permisos que quedan en el editor.
 *
 * Cada nivel queda dentro de lo que admite su pantalla (un reporte, hasta
 * Lectura). Lo que puede dar quien invita lo recorta el editor, con el
 * max_level de cada pantalla.
 */
final class PermissionProfiles
{
    /**
     * Los perfiles de un rol, en el orden de la configuración.
     *
     * @return list<array{key: string, label: string, description: string, levels: array<string, string>}>
     */
    public static function for(string $roleType): array
    {
        return collect(config("permission_profiles.{$roleType}", []))
            ->map(fn (array $profile, string $key) => [
                'key' => $key,
                'label' => $profile['label'],
                'description' => $profile['description'],
                'levels' => self::levels($profile),
            ])
            ->values()
            ->all();
    }

    /**
     * Los perfiles de varios roles, por rol.
     *
     * @param  list<string>  $roleTypes
     * @return array<string, list<array>>
     */
    public static function forRoles(array $roleTypes): array
    {
        return collect($roleTypes)->mapWithKeys(fn (string $type) => [$type => self::for($type)])->all();
    }

    /**
     * Un nivel por cada pantalla: el de la pantalla si el perfil lo nombra; si
     * no, el de su sección; si no, el de '*'; si no, sin acceso.
     *
     * @return array<string, string>
     */
    public static function levels(array $profile): array
    {
        $sections = $profile['sections'] ?? [];
        $screens = $profile['screens'] ?? [];
        $levels = [];

        foreach (ScreenCatalog::sections() as $section) {
            $sectionLevel = $sections[$section['key']] ?? $sections['*'] ?? 'none';

            foreach ($section['screens'] as $screen) {
                $levels[$screen['key']] = ScreenAccessService::min(
                    $screens[$screen['key']] ?? $sectionLevel,
                    ScreenCatalog::maxLevel($screen['key']),
                );
            }
        }

        return $levels;
    }
}
