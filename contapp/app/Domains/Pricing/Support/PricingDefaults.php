<?php

namespace App\Domains\Pricing\Support;

use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Pricing\Models\PricingFeature;
use Illuminate\Support\Facades\DB;

/**
 * Las funciones sugeridas para empezar el catálogo de precios (CLAUDE.md
 * secc. 42), sacadas de los módulos reales de Pivox. Nacen sin precio e
 * inactivas: el backoffice les pone precio y las activa una por una. Las
 * carga «Cargar funciones sugeridas», con el catálogo vacío.
 */
final class PricingDefaults
{
    /**
     * clave => [nombre, grupo, ícono, descripción, tipo, módulo, obligatoria, necesita, unidad singular, plural, incluidas]
     */
    private const FEATURES = [
        'contabilidad' => ['Contabilidad', 'Contabilidad y finanzas', 'book-open', 'Catálogo de cuentas, asientos en tres monedas, cierres y estados financieros.', 'toggle', 'accounting', true, []],
        'centros_costo' => ['Centros de costo y cambiario', 'Contabilidad y finanzas', 'tags', 'Normas de reparto, tipo de cambio del BCCR y diferencial cambiario.', 'toggle', 'cost_fx', false, ['contabilidad']],
        'socios' => ['Socios de negocio, cobros y pagos', 'Contabilidad y finanzas', 'handshake', 'Clientes y proveedores, partidas abiertas y antigüedad de saldos.', 'toggle', 'business_partners', false, ['contabilidad']],
        'bancos' => ['Bancos y conciliaciones', 'Contabilidad y finanzas', 'landmark', 'Conciliaciones bancarias y proyección de cobros y pagos.', 'toggle', 'banking', false, ['contabilidad']],
        'impuestos' => ['Impuestos (IVA)', 'Contabilidad y finanzas', 'percent', 'Indicadores de IVA y reporte para la declaración.', 'toggle', 'tax', false, ['contabilidad']],
        'inventario' => ['Inventario', 'Operación', 'package', 'Existencias, almacenes, lotes, series, tomas físicas y códigos de barras.', 'toggle', 'inventory', false, ['contabilidad']],
        'compras' => ['Compras e importaciones', 'Operación', 'truck', 'Órdenes de compra, facturas de proveedor, DUA y rubros de nacionalización.', 'toggle', 'inventory', false, ['inventario', 'socios']],
        'produccion' => ['Producción', 'Operación', 'factory', 'Listas de materiales y órdenes de fabricación.', 'toggle', 'inventory', false, ['inventario']],
        'facturacion' => ['Facturación', 'Operación', 'receipt', 'Pedidos, facturas y notas de crédito, con listas de precios.', 'toggle', 'billing', false, ['inventario', 'socios']],
        'factura_electronica' => ['Facturación electrónica', 'Operación', 'file-check', 'Comprobantes v4.4 firmados y enviados a Hacienda, con seguimiento hasta la respuesta.', 'toggle', 'electronic_invoicing', false, ['facturacion']],
        'planillas' => ['Planillas', 'Operación', 'users', 'Cargas sociales de la CCSS, impuesto al salario, vacaciones y liquidaciones.', 'toggle', 'payroll', false, ['contabilidad']],
        'conti' => ['Conti, el asistente con IA', 'Conti', 'bot', 'Consulta los datos, corre reportes y prepara registros que se confirman en Pivox.', 'toggle', 'conti', false, []],
        'escaner' => ['Escanear documentos', 'Conti', 'scan-line', 'Una foto de la factura y Conti llena el formulario.', 'toggle', 'conti', false, ['conti']],
        'companias' => ['Compañías', 'Capacidad', 'building-2', 'Cada compañía con su catálogo, su moneda y su tema.', 'quantity', null, true, [], 'compañía', 'compañías', 1],
        'administradores' => ['Administradores', 'Capacidad', 'user-plus', 'Personas que invitan usuarios y les dan permisos.', 'quantity', null, false, [], 'administrador', 'administradores', 0],
        'usuarios' => ['Usuarios', 'Capacidad', 'users-round', 'Personas que trabajan en las compañías con los permisos que se les den.', 'quantity', null, false, [], 'usuario', 'usuarios', 0],
        'soporte' => ['Soporte prioritario', 'Servicios', 'headset', 'Atención preferente del equipo de Pivox.', 'toggle', null, false, []],
    ];

    /** Crea las sugeridas que todavía no existan, sin precio e inactivas. Devuelve cuántas creó. */
    public static function load(): int
    {
        return DB::transaction(function () {
            $created = 0;
            $order = 0;

            foreach (self::FEATURES as $key => $definition) {
                $order += 10;

                if (PricingFeature::query()->where('key', $key)->exists()) {
                    continue;
                }

                [$name, $group, $icon, $description, $kind, $module, $required] = $definition;

                PricingFeature::create([
                    'key' => $key,
                    'name' => $name,
                    'group' => $group,
                    'icon' => $icon,
                    'description' => $description,
                    'kind' => $kind,
                    'module_key' => $module,
                    'is_required' => $required,
                    'is_active' => false,
                    'unit_singular' => $definition[8] ?? null,
                    'unit_plural' => $definition[9] ?? null,
                    'included_quantity' => $definition[10] ?? 0,
                    'sort_order' => $order,
                ]);

                $created++;
            }

            foreach (self::FEATURES as $key => $definition) {
                $feature = PricingFeature::query()->where('key', $key)->first();
                $needed = PricingFeature::query()->whereIn('key', $definition[7])->pluck('id');

                $feature?->requirements()->syncWithoutDetaching($needed);
            }

            return $created;
        });
    }

    /**
     * Los módulos de Pivox a los que se puede ligar una función: las
     * secciones del menú y lo que se activa aparte.
     *
     * @return array<string, string>
     */
    public static function modules(): array
    {
        $modules = [];

        foreach (ScreenCatalog::sections() as $section) {
            $modules[$section['key']] = $section['label'];
        }

        return $modules + [
            'electronic_invoicing' => 'Facturación electrónica',
            'conti' => 'Conti, el asistente',
        ];
    }
}
