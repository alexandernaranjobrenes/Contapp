<?php

namespace Database\Seeders;

use App\Domains\Core\Models\Module;
use Illuminate\Database\Seeder;

/**
 * Catálogo inicial de módulos funcionales para la matriz de permisos
 * (CLAUDE.md secc. 14). Global al tenant, igual que CurrencySeeder — no se
 * repite por compañía. Pequeño y real a propósito: cubre las áreas ya
 * construidas del sistema; se amplía cuando haga falta, no es una lista cerrada.
 */
class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            ['code' => 'accounting', 'name' => 'Contabilidad (asientos, catálogo, cierres)'],
            ['code' => 'business_partners', 'name' => 'Socios de negocio y cartera'],
            ['code' => 'banking', 'name' => 'Bancos y conciliaciones'],
            ['code' => 'tax', 'name' => 'Impuestos (IVA)'],
            ['code' => 'reports', 'name' => 'Reportería'],
        ];

        foreach ($modules as $module) {
            Module::firstOrCreate(['code' => $module['code']], $module);
        }
    }
}
