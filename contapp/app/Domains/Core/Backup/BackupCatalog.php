<?php

namespace App\Domains\Core\Backup;

/**
 * Qué lleva el respaldo de una compañía y cómo se recorta cada tabla
 * (CLAUDE.md secc. 37). Igual que AuditCatalog: cada tabla de la base está
 * acá o en EXCLUDED con su motivo, y una prueba lo exige.
 *
 * Formas:
 * - catalog: todas las filas de la compañía, como están hoy (cuentas,
 *   socios, artículos, empleados, configuración).
 * - movement: las del período, por su columna de fecha (asientos,
 *   comprobantes, movimientos de inventario, planillas…).
 * - child: las que cuelgan de una fila que va (las líneas de un asiento).
 *   Si además tiene fecha, también se recorta por ella.
 * - state: el estado al cierre del período (existencias, partidas abiertas):
 *   lo arma BackupState.
 *
 * Las secciones son lo que se elige al exportar. Contabilidad (con los
 * socios: las líneas de los asientos los usan) siempre va.
 */
final class BackupCatalog
{
    public const SECTIONS = [
        'accounting' => 'Contabilidad y socios de negocio',
        'banking' => 'Bancos',
        'inventory' => 'Inventario',
        'billing' => 'Facturación',
        'payroll' => 'Planillas',
        'people' => 'Personas y permisos',
        'activity' => 'Bitácora',
    ];

    /** Siempre van: el resto de las secciones se apoya en ellas. */
    public const REQUIRED_SECTIONS = ['accounting'];

    /** Una sección que necesita otra: las líneas de un comprobante son artículos. */
    public const SECTION_REQUIRES = ['billing' => ['inventory']];

    /**
     * tabla => [sección, forma, opciones]. Opciones: 'date' (la columna que
     * ubica la fila en el período), 'parent' (la columna hacia la fila de la
     * que cuelga), 'owned' (solo las de la compañía: hay filas globales de
     * CONTAPP en la misma tabla).
     *
     * @var array<string, array{0: string, 1: string, 2?: array<string, mixed>}>
     */
    public const TABLES = [
        // ── Contabilidad ─────────────────────────────────────────────────
        'account_mask_configs' => ['accounting', 'catalog'],
        'chart_of_accounts' => ['accounting', 'catalog'],
        'cost_centers' => ['accounting', 'catalog'],
        'cost_allocation_rules' => ['accounting', 'catalog'],
        'cost_allocation_rule_lines' => ['accounting', 'child', ['parent' => 'cost_allocation_rule_id']],
        'document_types' => ['accounting', 'catalog'],
        'document_type_number_series' => ['accounting', 'catalog'],
        'document_type_officers' => ['accounting', 'child', ['parent' => 'document_type_id']],
        'document_type_printing' => ['accounting', 'child', ['parent' => 'document_type_id']],
        'document_type_visible_columns' => ['accounting', 'child', ['parent' => 'document_type_id']],
        'fiscal_years' => ['accounting', 'catalog'],
        'fiscal_periods' => ['accounting', 'child', ['parent' => 'fiscal_year_id']],
        'exchange_rates' => ['accounting', 'catalog'],
        'tax_types' => ['accounting', 'catalog', ['owned' => true]],
        'tax_rates' => ['accounting', 'catalog', ['owned' => true]],
        'opening_balances' => ['accounting', 'catalog'],
        'period_closing_processes' => ['accounting', 'catalog'],
        'journal_entry_schedules' => ['accounting', 'catalog'],
        'journal_entries' => ['accounting', 'movement', ['date' => 'posting_date']],
        'journal_details' => ['accounting', 'child', ['parent' => 'journal_entry_id']],
        'journal_detail_taxes' => ['accounting', 'child', ['parent' => 'journal_detail_id']],
        'account_reconciliations' => ['accounting', 'movement', ['date' => 'reconciled_at']],
        'account_reconciliation_lines' => ['accounting', 'child', ['parent' => 'account_reconciliation_id']],
        'fx_revaluation_runs' => ['accounting', 'movement', ['date' => 'cutoff_date']],
        'fx_revaluation_details' => ['accounting', 'child', ['parent' => 'fx_revaluation_run_id']],
        'bp_families' => ['accounting', 'catalog'],
        'bp_categories' => ['accounting', 'catalog'],
        'business_partners' => ['accounting', 'catalog'],
        'bp_open_items' => ['accounting', 'state', ['parent' => 'business_partner_id']],
        'bp_payment_applications' => ['accounting', 'child', ['parent' => 'payment_journal_entry_id', 'date' => 'applied_date']],
        'bp_open_item_reconciliations' => ['accounting', 'child', ['parent' => 'business_partner_id', 'date' => 'reconciled_at']],
        'bp_open_item_reconciliation_lines' => ['accounting', 'child', ['parent' => 'bp_open_item_reconciliation_id']],

        // ── Bancos ───────────────────────────────────────────────────────
        'bank_accounts' => ['banking', 'catalog'],
        'bank_statement_lines' => ['banking', 'child', ['parent' => 'bank_account_id', 'date' => 'statement_date']],
        'bank_reconciliations' => ['banking', 'child', ['parent' => 'bank_account_id', 'date' => 'cutoff_date']],
        'bank_reconciliation_lines' => ['banking', 'child', ['parent' => 'bank_reconciliation_id']],

        // ── Inventario ───────────────────────────────────────────────────
        'units_of_measure' => ['inventory', 'catalog'],
        'item_groups' => ['inventory', 'catalog'],
        'items' => ['inventory', 'catalog'],
        'item_lots' => ['inventory', 'child', ['parent' => 'item_id']],
        'warehouses' => ['inventory', 'catalog'],
        'warehouse_bins' => ['inventory', 'child', ['parent' => 'warehouse_id']],
        'price_lists' => ['inventory', 'catalog'],
        'price_list_items' => ['inventory', 'child', ['parent' => 'price_list_id']],
        'bills_of_materials' => ['inventory', 'catalog'],
        'bill_of_material_lines' => ['inventory', 'child', ['parent' => 'bill_of_material_id']],
        'gl_determinations' => ['inventory', 'catalog'],
        'item_warehouses' => ['inventory', 'state', ['parent' => 'item_id']],
        'item_bins' => ['inventory', 'state', ['parent' => 'item_id']],
        'item_lot_stock' => ['inventory', 'state', ['parent' => 'item_lot_id']],
        'item_serials' => ['inventory', 'state', ['parent' => 'item_id']],
        'purchase_orders' => ['inventory', 'movement', ['date' => 'order_date']],
        'purchase_order_lines' => ['inventory', 'child', ['parent' => 'purchase_order_id']],
        'production_orders' => ['inventory', 'movement', ['date' => 'order_date']],
        'inventory_documents' => ['inventory', 'movement', ['date' => 'posting_date']],
        'inventory_document_lines' => ['inventory', 'child', ['parent' => 'inventory_document_id']],
        'stock_journals' => ['inventory', 'movement', ['date' => 'posting_date']],
        'stock_counts' => ['inventory', 'movement', ['date' => 'cutoff_date']],
        'stock_count_lines' => ['inventory', 'child', ['parent' => 'stock_count_id']],
        'inventory_write_downs' => ['inventory', 'movement', ['date' => 'as_of']],
        'inventory_write_down_lines' => ['inventory', 'child', ['parent' => 'inventory_write_down_id']],
        'landed_cost_documents' => ['inventory', 'movement', ['date' => 'posting_date']],
        'landed_cost_allocations' => ['inventory', 'child', ['parent' => 'landed_cost_document_id']],
        'import_cost_documents' => ['inventory', 'movement', ['date' => 'posting_date']],
        'import_cost_allocations' => ['inventory', 'child', ['parent' => 'import_cost_document_id']],

        // ── Facturación ──────────────────────────────────────────────────
        'company_economic_activities' => ['billing', 'catalog'],
        'billing_payment_accounts' => ['billing', 'catalog'],
        'billing_tax_accounts' => ['billing', 'catalog'],
        'sales_orders' => ['billing', 'movement', ['date' => 'order_date']],
        'sales_order_lines' => ['billing', 'child', ['parent' => 'sales_order_id']],
        'sales_documents' => ['billing', 'movement', ['date' => 'posting_date']],
        'sales_document_lines' => ['billing', 'child', ['parent' => 'sales_document_id']],
        'sales_line_taxes' => ['billing', 'child', ['parent' => 'sales_document_line_id']],
        'sales_payments' => ['billing', 'child', ['parent' => 'sales_document_id']],
        'sales_references' => ['billing', 'child', ['parent' => 'sales_document_id']],
        'price_override_authorizations' => ['billing', 'movement', ['date' => 'created_at']],

        // ── Planillas ────────────────────────────────────────────────────
        'departments' => ['payroll', 'catalog'],
        'job_positions' => ['payroll', 'catalog'],
        'employees' => ['payroll', 'catalog'],
        'payroll_concepts' => ['payroll', 'catalog'],
        'payroll_contributions' => ['payroll', 'catalog'],
        'payroll_provisions' => ['payroll', 'catalog'],
        'payroll_settings' => ['payroll', 'catalog'],
        'payroll_tax_brackets' => ['payroll', 'catalog'],
        'payroll_tax_credits' => ['payroll', 'catalog'],
        'employee_recurring_inputs' => ['payroll', 'catalog'],
        'employee_deductions' => ['payroll', 'catalog'],
        'employee_deduction_applications' => ['payroll', 'child', ['parent' => 'employee_deduction_id', 'date' => 'applied_on']],
        'employee_notes' => ['payroll', 'movement', ['date' => 'happened_on']],
        'personnel_actions' => ['payroll', 'movement', ['date' => 'effective_date']],
        'payroll_periods' => ['payroll', 'movement', ['date' => 'payment_date']],
        'payroll_entries' => ['payroll', 'child', ['parent' => 'payroll_period_id']],
        'payroll_entry_lines' => ['payroll', 'child', ['parent' => 'payroll_entry_id']],
        'payroll_inputs' => ['payroll', 'child', ['parent' => 'payroll_period_id']],
        'payroll_period_events' => ['payroll', 'child', ['parent' => 'payroll_period_id']],
        'vacation_movements' => ['payroll', 'movement', ['date' => 'movement_date']],
        'labor_settlements' => ['payroll', 'movement', ['date' => 'termination_date']],
        'labor_settlement_lines' => ['payroll', 'child', ['parent' => 'labor_settlement_id']],

        // ── Personas y permisos ──────────────────────────────────────────
        'roles' => ['people', 'catalog', ['owned' => true]],
        'company_user' => ['people', 'catalog'],
        'user_roles' => ['people', 'catalog'],
        'screen_permissions' => ['people', 'catalog'],
        'module_permissions' => ['people', 'catalog'],
        'document_type_permissions' => ['people', 'child', ['parent' => 'document_type_id']],

        // ── Bitácora ─────────────────────────────────────────────────────
        'audit_logs' => ['activity', 'movement', ['date' => 'created_at']],
    ];

    /**
     * Lo que no va, y por qué.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        'companies' => 'Es la compañía misma: va en el encabezado, y al importar se crea una nueva.',
        'users' => 'Las cuentas son de las personas, no de la compañía: se reconocen por su correo.',
        'currencies' => 'Catálogo de CONTAPP: se referencia.',
        'modules' => 'Catálogo de CONTAPP: se referencia.',
        'company_modules' => 'Los módulos de una compañía los decide su licencia.',
        'company_invitations' => 'Una invitación lleva un enlace con token: no se respalda.',
        'saved_reports' => 'Son preferencias de cada persona, no datos de la compañía.',
        'conti_actions' => 'Lo que Conti prepara: lo confirmado ya está en sus registros.',
        'conti_usage' => 'El consumo de Conti es de la licencia, no de la compañía.',
        'conti_user_settings' => 'El acceso a Conti es de la licencia.',
        'licenses' => 'Backoffice.',
        'license_categories' => 'Backoffice.',
        'license_invitations' => 'Backoffice.',
        'commercial_profiles' => 'Backoffice.',
        'commercial_interactions' => 'Backoffice.',
        'commercial_follow_ups' => 'Backoffice.',
        'propietarios' => 'Backoffice.',
        'news_posts' => 'Comentarios y noticias son de CONTAPP.',
        'feedback_comments' => 'Comentarios y noticias son de CONTAPP.',
        'feedback_votes' => 'Comentarios y noticias son de CONTAPP.',
        'company_backups' => 'Las importaciones mismas.',
        'sessions' => 'Del sistema.',
        'cache' => 'Del sistema.',
        'cache_locks' => 'Del sistema.',
        'jobs' => 'Del sistema.',
        'failed_jobs' => 'Del sistema.',
        'job_batches' => 'Del sistema.',
        'migrations' => 'Del sistema.',
        'password_reset_tokens' => 'Del sistema.',
    ];

    /**
     * Referencias que no son llaves foráneas (un tipo y un id, ids adentro de
     * un JSON): la tabla va después de estas, para encontrarlas ya
     * importadas. La Bitácora, al final: apunta a cualquier cosa.
     *
     * @var array<string, list<string>>
     */
    public const AFTER = [
        'gl_determinations' => ['items', 'item_groups', 'warehouses'],
        'journal_entry_schedules' => ['chart_of_accounts', 'business_partners', 'cost_allocation_rules'],
        'personnel_actions' => ['cost_centers'],
    ];

    /** Van al final, después de todo lo demás. */
    public const LAST = ['audit_logs'];

    /** Se referencian por su id, que es el mismo en toda la instalación. */
    public const GLOBAL_TABLES = ['currencies', 'modules'];

    /** Tablas con filas de CONTAPP (company_id NULL) y de la compañía: las de CONTAPP se referencian. */
    public const MIXED_TABLES = ['tax_types', 'tax_rates', 'roles'];

    /**
     * Columnas que no viajan: las fotos y el logo están en el bucket, y el
     * respaldo no las lleva (decidido con el usuario).
     *
     * @var array<string, list<string>>
     */
    public const BLANK_COLUMNS = [
        'employees' => ['photo_path'],
    ];

    public static function includes(string $table): bool
    {
        return isset(self::TABLES[$table]);
    }

    public static function section(string $table): ?string
    {
        return self::TABLES[$table][0] ?? null;
    }

    public static function form(string $table): ?string
    {
        return self::TABLES[$table][1] ?? null;
    }

    public static function option(string $table, string $key): mixed
    {
        return self::TABLES[$table][2][$key] ?? null;
    }

    /**
     * Las secciones que se piden, con las obligatorias y las que estas
     * necesitan.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public static function resolveSections(array $requested): array
    {
        $sections = array_values(array_unique([...self::REQUIRED_SECTIONS, ...array_intersect($requested, array_keys(self::SECTIONS))]));

        foreach (self::SECTION_REQUIRES as $section => $needs) {
            if (in_array($section, $sections, true)) {
                $sections = array_values(array_unique([...$sections, ...$needs]));
            }
        }

        // En el orden de SECTIONS, siempre igual.
        return array_values(array_filter(array_keys(self::SECTIONS), fn (string $s) => in_array($s, $sections, true)));
    }

    /**
     * Las tablas de esas secciones.
     *
     * @param  list<string>  $sections
     * @return list<string>
     */
    public static function tablesFor(array $sections): array
    {
        return array_keys(array_filter(self::TABLES, fn (array $def) => in_array($def[0], $sections, true)));
    }
}
