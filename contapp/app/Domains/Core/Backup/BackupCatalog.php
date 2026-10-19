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
 *
 * Al completar una compañía que ya existe (BackupMerger), cada fila del
 * respaldo se busca en ella:
 * - Por su dueño (OWNERS): las líneas de un asiento, de una factura, de un
 *   movimiento, van con su documento. Si el documento ya está, no se le
 *   agrega ni se le cambia nada; si falta, entra entero.
 * - Por su llave: las llaves únicas de la base (el código de una cuenta, el
 *   número de un asiento) y, si la tabla no tiene, la de KEYS.
 * - 'own' en una hija: es un registro propio que cuelga de un catálogo (los
 *   lotes de un artículo, los precios de una lista): se busca por su llave y,
 *   si falta, se agrega aunque su catálogo ya estuviera.
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
        'fiscal_periods' => ['accounting', 'child', ['parent' => 'fiscal_year_id', 'own' => true]],
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
        'bp_payment_applications' => ['accounting', 'child', ['parent' => 'payment_journal_entry_id', 'date' => 'applied_date', 'own' => true]],
        'bp_open_item_reconciliations' => ['accounting', 'child', ['parent' => 'business_partner_id', 'date' => 'reconciled_at', 'own' => true]],
        'bp_open_item_reconciliation_lines' => ['accounting', 'child', ['parent' => 'bp_open_item_reconciliation_id']],

        // ── Bancos ───────────────────────────────────────────────────────
        'bank_accounts' => ['banking', 'catalog'],
        'bank_statement_lines' => ['banking', 'child', ['parent' => 'bank_account_id', 'date' => 'statement_date', 'own' => true]],
        'bank_reconciliations' => ['banking', 'child', ['parent' => 'bank_account_id', 'date' => 'cutoff_date', 'own' => true]],
        'bank_reconciliation_lines' => ['banking', 'child', ['parent' => 'bank_reconciliation_id']],

        // ── Inventario ───────────────────────────────────────────────────
        'units_of_measure' => ['inventory', 'catalog'],
        'item_groups' => ['inventory', 'catalog'],
        'items' => ['inventory', 'catalog'],
        'item_lots' => ['inventory', 'child', ['parent' => 'item_id', 'own' => true]],
        'warehouses' => ['inventory', 'catalog'],
        'warehouse_bins' => ['inventory', 'child', ['parent' => 'warehouse_id', 'own' => true]],
        'price_lists' => ['inventory', 'catalog'],
        'price_list_items' => ['inventory', 'child', ['parent' => 'price_list_id', 'own' => true]],
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
        'employee_deduction_applications' => ['payroll', 'child', ['parent' => 'employee_deduction_id', 'date' => 'applied_on', 'own' => true]],
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
        // Facturación electrónica (CLAUDE.md secc. 38).
        'hacienda_settings' => 'La facturación electrónica se vuelve a configurar en la compañía: encendida sin sus credenciales, no dejaría facturar.',
        'hacienda_credentials' => 'Las credenciales del ATV y la llave criptográfica no salen de la compañía: las vuelve a cargar su Superusuario.',
        'fiscal_sequences' => 'Se recalcula de los consecutivos de los comprobantes la primera vez que se usa.',
        'electronic_documents' => 'El XML firmado y la respuesta de Hacienda son de la compañía que los emitió: una copia podría volver a enviarlos. Se descargan desde cada comprobante.',
        'electronic_document_events' => 'La historia del comprobante electrónico: va con él.',
        'electronic_document_exchanges' => 'Los envíos y consultas a Hacienda: van con el comprobante electrónico.',
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

    /**
     * Columnas que esta versión exige y un respaldo de una versión anterior
     * no trae: con qué se completan al importarlo. tabla => [columna => valor].
     *
     * Una migración que agrega una columna obligatoria sin valor por defecto
     * a una tabla del respaldo la suma acá; si no, esos respaldos se rechazan
     * al revisarlos, diciendo qué falta (BackupImporter::checkColumns).
     *
     * @var array<string, array<string, mixed>>
     */
    public const FILL = [];

    /**
     * El documento del que es parte una fila que no es hija: si ese documento
     * ya está en la compañía, ella también. En orden: vale la primera que
     * tenga valor. Las hijas (sin 'own') van con su 'parent'.
     *
     * @var array<string, list<string>>
     */
    public const OWNERS = [
        'bp_open_items' => ['origin_journal_detail_id'],
        'inventory_documents' => ['journal_entry_id'],
        'inventory_write_downs' => ['journal_entry_id'],
        'landed_cost_documents' => ['journal_entry_id'],
        'fx_revaluation_runs' => ['journal_entry_id'],
        'period_closing_processes' => ['journal_entry_id'],
        'labor_settlements' => ['journal_entry_id'],
        'stock_journals' => ['inventory_document_line_id', 'landed_cost_allocation_id', 'journal_entry_id'],
        'price_override_authorizations' => ['sales_document_line_id', 'sales_order_line_id'],
        'employee_deduction_applications' => ['payroll_entry_id'],
        'vacation_movements' => ['payroll_entry_id', 'labor_settlement_id'],
    ];

    /**
     * La llave de las tablas sin una llave única en la base, o con una que
     * admite vacío (un borrador todavía sin número). Un vacío se compara como
     * vacío. Siempre lleva la compañía o la fila de la que cuelga.
     *
     * @var array<string, list<string>>
     */
    public const KEYS = [
        'exchange_rates' => ['company_id', 'currency_id', 'rate_date', 'rate_type'],
        'tax_types' => ['company_id', 'code'],
        'tax_rates' => ['company_id', 'tax_type_id', 'code', 'effective_from'],
        'opening_balances' => ['company_id', 'fiscal_year_id', 'account_id', 'business_partner_id', 'currency_id'],
        'period_closing_processes' => ['company_id', 'type', 'fiscal_period_id', 'fiscal_year_id'],
        'journal_entry_schedules' => ['company_id', 'document_type_id', 'description', 'frequency_type', 'created_at'],
        'journal_entries' => ['company_id', 'document_type_id', 'document_date', 'created_at'],
        'account_reconciliations' => ['account_id', 'reconciled_at'],
        'fx_revaluation_runs' => ['company_id', 'cutoff_date', 'document_type_id'],
        'gl_determinations' => ['company_id', 'scope_level', 'scope_id', 'category'],
        'bp_open_items' => ['business_partner_id', 'document_type_code', 'document_number', 'currency_id'],
        'bp_payment_applications' => ['payment_journal_entry_id', 'open_item_id', 'applied_date', 'applied_amount'],
        'bp_open_item_reconciliations' => ['business_partner_id', 'reconciled_at'],
        'bank_statement_lines' => ['bank_account_id', 'statement_date', 'amount', 'description'],
        'bank_reconciliations' => ['bank_account_id', 'cutoff_date'],
        'item_lot_stock' => ['item_lot_id', 'warehouse_id', 'warehouse_bin_id'],
        'production_orders' => ['company_id', 'item_id', 'warehouse_id', 'order_date', 'created_at'],
        'inventory_documents' => ['company_id', 'document_type_id', 'operation', 'posting_date', 'created_at'],
        'sales_documents' => ['company_id', 'document_type_id', 'document_date', 'created_at'],
        'price_override_authorizations' => ['company_id', 'item_id', 'created_at'],
        'employee_deductions' => ['company_id', 'employee_id', 'type', 'start_date', 'description'],
        'employee_deduction_applications' => ['employee_deduction_id', 'applied_on', 'amount'],
        'employee_notes' => ['company_id', 'employee_id', 'happened_on', 'title', 'created_at'],
        'personnel_actions' => ['company_id', 'employee_id', 'action_type', 'effective_date', 'created_at'],
        'vacation_movements' => ['company_id', 'employee_id', 'type', 'movement_date', 'days', 'created_at'],
        'labor_settlements' => ['company_id', 'employee_id', 'termination_date'],
        'roles' => ['company_id', 'name', 'type'],
        'audit_logs' => ['company_id', 'action', 'auditable_type', 'auditable_id', 'user_id', 'created_at'],
    ];

    /**
     * Lo que el sistema calcula a partir de los movimientos (BackupBalances
     * lo recalcula): no cuenta como una diferencia al comparar.
     *
     * @var array<string, list<string>>
     */
    public const DERIVED = [
        'items' => ['avg_cost_local', 'avg_cost_foreign'],
        'document_types' => ['next_consecutive'],
        'document_type_number_series' => ['next_number'],
        'employee_deductions' => ['balance', 'status'],
        'journal_entry_schedules' => ['next_run_date', 'status'],
        'bank_statement_lines' => ['matched'],
        'journal_details' => ['bank_reconciled'],
    ];

    /**
     * Además de los movimientos y sus líneas, lo que se trata como un
     * documento: si ya está con otros datos, no se puede completar.
     */
    public const DOCUMENTS = ['bank_reconciliations', 'bp_open_item_reconciliations'];

    /** Cómo se nombra un documento en lo que impide completar una compañía. */
    public const LABELS = [
        'journal_entries' => 'El asiento',
        'account_reconciliations' => 'La conciliación de cuenta',
        'fx_revaluation_runs' => 'El diferencial cambiario',
        'bp_open_item_reconciliations' => 'La reconciliación de partidas',
        'bank_reconciliations' => 'La conciliación bancaria',
        'purchase_orders' => 'La orden de compra',
        'production_orders' => 'La orden de producción',
        'inventory_documents' => 'El movimiento de inventario',
        'stock_journals' => 'El kardex',
        'stock_counts' => 'La toma física',
        'inventory_write_downs' => 'El avalúo',
        'landed_cost_documents' => 'El costeo',
        'import_cost_documents' => 'El costo de importación',
        'sales_orders' => 'La orden de pedido',
        'sales_documents' => 'El comprobante',
        'price_override_authorizations' => 'La autorización de precio',
        'employee_notes' => 'La nota del expediente',
        'personnel_actions' => 'La acción de personal',
        'payroll_periods' => 'El período de planilla',
        'vacation_movements' => 'El movimiento de vacaciones',
        'labor_settlements' => 'La liquidación',
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
     * Las columnas hacia el documento del que es parte la fila (OWNERS, o el
     * 'parent' de una hija que no es 'own').
     *
     * @return list<string>
     */
    public static function owners(string $table): array
    {
        if (isset(self::OWNERS[$table])) {
            return self::OWNERS[$table];
        }

        $parent = self::option($table, 'parent');

        return self::form($table) === 'child' && $parent !== null && ! self::option($table, 'own') ? [$parent] : [];
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
