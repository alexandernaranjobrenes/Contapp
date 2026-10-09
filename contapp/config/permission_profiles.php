<?php

/*
|--------------------------------------------------------------------------
| Perfiles de permisos (CLAUDE.md secc. 12)
|--------------------------------------------------------------------------
|
| Al invitar a alguien o al editar sus permisos, elegir un perfil precarga
| el editor de permisos; después se ajusta a mano. El perfil no se guarda en
| ningún lado: solo los permisos, como siempre. Es una ayuda para no marcar
| pantalla por pantalla.
|
| Por rol (Administrador, Usuario), cada perfil da un nivel por sección del
| menú ('*' = todas) y, si hace falta, por pantalla, que le gana a la de su
| sección. Lo que no se nombra queda sin acceso. Las claves son las de
| ScreenCatalog; una prueba revisa que existan (PermissionProfiles).
|
| Al aplicarlo, nunca da más de lo que admite cada pantalla (un reporte,
| hasta Lectura) ni de lo que puede dar quien invita o edita.
|
*/

$contador = [
    'label' => 'Contador',
    'description' => 'La contabilidad completa: registros, cierres, bancos, impuestos y socios. El resto, solo para consultar.',
    'sections' => [
        'accounting' => 'read_write',
        'cost_fx' => 'read_write',
        'banking' => 'read_write',
        'tax' => 'read_write',
        'business_partners' => 'read_write',
        'inventory' => 'read',
        'billing' => 'read',
        'payroll' => 'read',
    ],
];

return [
    'admin' => [
        'administrador_general' => [
            'label' => 'Administrador general',
            'description' => 'Todo el menú, con Lectura y escritura.',
            'sections' => ['*' => 'read_write'],
        ],
        'contador_general' => ['label' => 'Contador general'] + $contador,
        'gerente' => [
            'label' => 'Gerente',
            'description' => 'Consulta todo y saca los reportes, sin modificar nada.',
            'sections' => ['*' => 'read'],
        ],
    ],

    'user' => [
        'contador' => $contador,
        'asistente_contable' => [
            'label' => 'Asistente de contabilidad',
            'description' => 'Hace los registros contables. Cierres, catálogos y reportes, solo para consultar.',
            'sections' => [
                'accounting' => 'read',
                'cost_fx' => 'read',
                'business_partners' => 'read',
            ],
            'screens' => [
                'accounting.journal_entries' => 'read_write',
            ],
        ],
        'vendedor' => [
            'label' => 'Vendedor',
            'description' => 'Facturación completa. Inventario y socios de negocio, solo para consultar.',
            'sections' => [
                'billing' => 'read_write',
                'inventory' => 'read',
                'business_partners' => 'read',
            ],
        ],
        'comprador' => [
            'label' => 'Comprador',
            'description' => 'Órdenes de compra, facturas de proveedor, costos de importación y socios de negocio. Los artículos, solo para consultar.',
            'sections' => [
                'inventory' => 'read',
                'business_partners' => 'read_write',
            ],
            'screens' => [
                'inventory.purchase_orders' => 'read_write',
                'inventory.reorder' => 'read_write',
                'inventory.supplier_invoices' => 'read_write',
                'inventory.landed_costs' => 'read_write',
                'inventory.import_costs' => 'read_write',
            ],
        ],
        'bodeguero' => [
            'label' => 'Bodeguero',
            'description' => 'Movimientos, traslados y tomas físicas. El resto del inventario, solo para consultar.',
            'sections' => [
                'inventory' => 'read',
            ],
            'screens' => [
                'inventory.movements' => 'read_write',
                'inventory.transfers' => 'read_write',
                'inventory.stock_counts' => 'read_write',
            ],
        ],
        'planillas' => [
            'label' => 'Encargado de planillas',
            'description' => 'Planillas completa: empleados, vacaciones, períodos y sus reportes.',
            'sections' => [
                'payroll' => 'read_write',
            ],
        ],
        'tesoreria' => [
            'label' => 'Tesorería (cobros y pagos)',
            'description' => 'Bancos y socios de negocio: conciliaciones, cobros y pagos.',
            'sections' => [
                'banking' => 'read_write',
                'business_partners' => 'read_write',
            ],
        ],
        'auditor' => [
            'label' => 'Auditor',
            'description' => 'Consulta todo y saca los reportes, sin modificar nada.',
            'sections' => ['*' => 'read'],
        ],
    ],
];
