<?php

namespace App\Domains\Core\Services;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\Accounting\Models\Currency;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\DocumentType;
use App\Domains\Core\Support\AuditCatalog;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\PriceList;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Payroll\Models\Department;
use App\Domains\Payroll\Models\Employee;
use App\Domains\Payroll\Models\JobPosition;
use App\Domains\Payroll\Models\PayrollConcept;
use App\Domains\Payroll\Models\PayrollPeriod;
use App\Domains\Tax\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * La Bitácora de la compañía, para leerla (Administración → Bitácora,
 * CLAUDE.md secc. 35): de a PAGE filas, de la más nueva a la más vieja, en
 * palabras («creó el asiento AS-0005») y con lo que cambió.
 *
 * El Superusuario ve todo. Un Administrador, lo de las pantallas a las que
 * tiene acceso (una fila sin pantalla —usuarios, la compañía, sesiones— la
 * ven los dos), y no lo que es solo del Superusuario (Conti, la licencia).
 */
class ActivityLogService
{
    public const PAGE = 15;

    /** Lo que decide solo el Superusuario: un Administrador no lo ve. */
    private const SUPERUSER_ONLY = ['conti_access_updated', 'license_activated', 'license_invitation_accepted'];

    /** La acción de la ruta, en palabras. Si no está, la del registro (creó, editó, eliminó). */
    private const VERBS = [
        'store' => 'creó', 'update' => 'editó', 'destroy' => 'eliminó',
        'post' => 'contabilizó', 'void' => 'anuló', 'cancel' => 'anuló', 'reverse' => 'reversó',
        'close' => 'cerró', 'close-year' => 'cerró', 'reopen' => 'reabrió', 'create-year' => 'creó',
        'approve' => 'aprobó', 'confirm' => 'confirmó', 'complete' => 'completó', 'calculate' => 'calculó',
        'import' => 'importó', 'inputs-import' => 'importó movimientos a', 'apply' => 'aplicó',
        'reconcile' => 'concilió', 'confirm-line' => 'confirmó una línea de', 'reclassify' => 'reclasificó',
        'transfer' => 'trasladó', 'issue' => 'emitió', 'receive' => 'recibió', 'scrap' => 'dio de baja',
        'allocate' => 'distribuyó', 'process-now' => 'procesó', 'update-due-date' => 'cambió el vencimiento de',
        'email-payslips' => 'envió los comprobantes de', 'load-defaults' => 'cargó los valores de',
        'photo' => 'cambió la foto de', 'sync' => 'sincronizó', 'link-business-partner' => 'vinculó un socio a',
        'suspend' => 'suspendió', 'reactivate' => 'reactivó', 'deactivate' => 'desactivó',
        'remove' => 'quitó', 'renew' => 'renovó', 'revoke' => 'revocó', 'revise' => 'corrigió',
    ];

    private const RECORD_VERBS = ['created' => 'creó', 'updated' => 'editó', 'deleted' => 'eliminó'];

    private const BADGES = ['created' => 'Alta', 'updated' => 'Cambio', 'deleted' => 'Baja'];

    /** Lo que ya se anotaba antes de la Bitácora, cada acción con su frase. */
    private const LEGACY = [
        'auth.login' => 'inició sesión',
        'auth.logout' => 'cerró sesión',
        'user_created' => 'creó la cuenta de :user',
        'user_invited' => 'invitó a :user',
        'user_invitation_sent' => 'invitó a :email',
        'user_invitation_accepted' => 'aceptó la invitación a la compañía',
        'user_role_changed' => 'cambió el rol de :user',
        'permission_change' => 'cambió los permisos de :user',
        'permission_escalation_attempt' => 'intentó dar más permisos de los que tiene a :user',
        'user_suspended' => 'suspendió a :user',
        'user_reactivated' => 'reactivó a :user',
        'user_deactivated' => 'desactivó a :user',
        'conti_access_updated' => 'cambió el acceso a Conti de :user',
        'company_created' => 'creó la compañía',
        'company.details_updated' => 'editó los datos de la compañía',
        'company.theme_updated' => 'cambió el tema de la compañía',
        'company.logo_updated' => 'cambió el logo de la compañía',
        'company.logo_removed' => 'quitó el logo de la compañía',
        'license_activated' => 'activó la licencia',
        'license_invitation_accepted' => 'aceptó la licencia',
        'user.profile_updated' => 'editó su perfil',
        'user.photo_updated' => 'cambió su foto de perfil',
        'user.photo_removed' => 'quitó su foto de perfil',
        'user.password_changed' => 'cambió su contraseña',
        'user.email_change_requested' => 'pidió cambiar su correo',
        'user.email_changed' => 'cambió su correo',
        'user.password_reset_requested' => 'pidió restablecer su contraseña',
        'user.password_reset' => 'restableció su contraseña',
    ];

    private const LEVELS = ['none' => 'Sin acceso', 'read' => 'Lectura', 'read_write' => 'Lectura y escritura'];

    /** Los estados, en palabras. */
    private const STATUSES = [
        'active' => 'Activo', 'inactive' => 'Inactivo', 'draft' => 'Borrador', 'posted' => 'Contabilizado',
        'void' => 'Anulado', 'voided' => 'Anulado', 'cancelled' => 'Anulado', 'reversed' => 'Reversado',
        'open' => 'Abierto', 'closed' => 'Cerrado', 'pending' => 'Pendiente', 'approved' => 'Aprobado',
        'applied' => 'Aplicado', 'rejected' => 'Rechazado', 'suspended' => 'Suspendido', 'terminated' => 'Terminado',
        'paid' => 'Pagado', 'partial' => 'Parcial', 'completed' => 'Completado', 'in_progress' => 'En proceso',
        'calculated' => 'Calculado', 'expired' => 'Vencido', 'deactivated' => 'Desactivado',
    ];

    /**
     * Las referencias a otro registro: en vez del número interno, su nombre
     * («CAJ — Caja»), si todavía existe.
     *
     * @var array<string, class-string>
     */
    private const REFERENCES = [
        'account_id' => ChartOfAccount::class,
        'gl_account_id' => ChartOfAccount::class,
        'gain_account_id' => ChartOfAccount::class,
        'loss_account_id' => ChartOfAccount::class,
        'salary_expense_account_id' => ChartOfAccount::class,
        'cost_center_id' => CostCenter::class,
        'currency_id' => Currency::class,
        'journal_entry_id' => JournalEntry::class,
        'bank_account_id' => BankAccount::class,
        'business_partner_id' => BusinessPartner::class,
        'document_type_id' => DocumentType::class,
        'item_id' => Item::class,
        'item_group_id' => ItemGroup::class,
        'uom_id' => UnitOfMeasure::class,
        'warehouse_id' => Warehouse::class,
        'price_list_id' => PriceList::class,
        'employee_id' => Employee::class,
        'department_id' => Department::class,
        'job_position_id' => JobPosition::class,
        'payroll_period_id' => PayrollPeriod::class,
        'payroll_concept_id' => PayrollConcept::class,
        'tax_rate_id' => TaxRate::class,
    ];

    /** @var array<class-string, array<int, string>> Los nombres de las referencias de la página. */
    private array $names = [];

    public function __construct(
        private readonly ScreenAccessService $screens,
        private readonly MediaStorage $media,
    ) {}

    /**
     * Las filas que ve esta persona, antes de $before (la última que ya
     * tiene en pantalla).
     *
     * @return array{entries: list<array>, has_more: bool}
     */
    public function page(User $viewer, int $companyId, ?int $before = null): array
    {
        $query = AuditLog::query()
            ->where('company_id', $companyId)
            ->whereNull('propietario_id')
            ->when($before, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id');

        if (! $viewer->isSuperAdmin($companyId)) {
            $allowed = array_keys(array_filter($this->screens->levelsFor($viewer, $companyId), fn (string $level) => $level !== 'none'));

            $query->where(fn ($q) => $q->whereNull('screen')->orWhereIn('screen', $allowed))
                ->whereNotIn('action', self::SUPERUSER_ONLY);
        }

        $rows = $query->limit(self::PAGE + 1)->get();
        $hasMore = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        $users = User::whereIn('id', $rows->pluck('user_id')->merge($this->subjectUserIds($rows))->filter()->unique())
            ->get(['id', 'name', 'email', 'photo_path', 'photo_updated_at'])
            ->keyBy('id');
        $this->names = $this->referenceNames($rows);

        return [
            'entries' => $rows->map(fn (AuditLog $row) => $this->present($row, $users))->values()->all(),
            'has_more' => $hasMore,
        ];
    }

    /** @param  Collection<int, User>  $users */
    private function present(AuditLog $row, Collection $users): array
    {
        $actor = $users[$row->user_id] ?? null;
        $activity = $row->action === AuditLog::ACTIVITY;

        return [
            'id' => $row->id,
            'at' => $row->created_at?->toIso8601String(),
            'actor' => [
                'name' => $actor?->name ?? ($row->user_id ? 'Usuario eliminado' : 'Sistema'),
                'photo_url' => $actor?->photo_path ? $this->media->versionedUrl($actor->photo_path, $actor->photo_updated_at) : null,
                'system' => $row->user_id === null,
            ],
            'sentence' => $activity ? $this->activitySentence($row) : $this->legacySentence($row, $users),
            'section' => $activity ? $this->section($row->screen) : $this->legacySection($row->action),
            'ip' => $row->ip_address,
            'groups' => $activity ? $this->groups($row) : $this->legacyGroups($row),
            'omitted' => $activity ? $this->omitted($row) : null,
        ];
    }

    private function activitySentence(AuditLog $row): string
    {
        $records = $row->changes['records'] ?? [];
        $primary = collect($records)->first(fn (array $r) => $r['t'] === $row->auditable_type && (int) $r['id'] === (int) $row->auditable_id) ?? ($records[0] ?? null);
        $suffix = Str::afterLast((string) $row->route, '.');
        $conti = str_starts_with((string) $row->route, 'conti.');

        $verb = (! $conti ? (self::VERBS[$suffix] ?? null) : null)
            ?? self::RECORD_VERBS[$primary['a'] ?? 'updated'];

        // Varios del mismo tipo con la misma acción (una importación, los
        // precios de una lista): «importó 25 artículos».
        $same = collect($records)->filter(fn (array $r) => $r['t'] === $row->auditable_type && $r['a'] === ($primary['a'] ?? null))->count()
            + array_sum($row->changes['omitted'][$row->auditable_type] ?? []);

        $object = $same > 1
            ? $same.' '.AuditCatalog::plural($row->auditable_type)
            : trim(AuditCatalog::withArticle($row->auditable_type).' '.$row->subject);

        return $verb.' '.$object.($conti ? ' con Conti' : '');
    }

    /** Los registros que tocó: los documentos uno por uno, sus líneas juntas. */
    private function groups(AuditLog $row): array
    {
        $groups = [];

        foreach ($row->changes['records'] ?? [] as $record) {
            $item = [
                'title' => AuditCatalog::noun($record['t']).' '.$record['s'],
                'badge' => self::BADGES[$record['a']] ?? $record['a'],
                'action' => $record['a'],
                'fields' => $this->fields($record['f'] ?? []),
            ];

            if (! AuditCatalog::isLine($record['t'])) {
                $groups[] = $item + ['count' => 1, 'items' => []];

                continue;
            }

            $key = $record['t'].'|'.$record['a'];
            $groups[$key] ??= [
                'title' => Str::ucfirst(AuditCatalog::plural($record['t'])),
                'badge' => self::BADGES[$record['a']] ?? $record['a'],
                'action' => $record['a'],
                'fields' => [],
                'count' => 0,
                'items' => [],
            ];
            $groups[$key]['count']++;
            $groups[$key]['items'][] = $item;
        }

        return array_values($groups);
    }

    private function omitted(AuditLog $row): ?string
    {
        $count = collect($row->changes['omitted'] ?? [])->flatten()->sum();

        return $count > 0 ? "Y {$count} registros más, que no se detallan." : null;
    }

    /** @param  Collection<int, User>  $users */
    private function legacySentence(AuditLog $row, Collection $users): string
    {
        $template = self::LEGACY[$row->action] ?? Str::of($row->action)->replace(['_', '.'], ' ')->lower()->toString();
        $subject = $row->auditable_type === User::class ? ($users[$row->auditable_id]->name ?? 'una persona') : '';

        return strtr($template, [
            ':user' => $subject,
            ':email' => (string) ($row->new_values['email'] ?? 'una persona'),
        ]);
    }

    private function legacySection(string $action): ?string
    {
        return match (true) {
            str_starts_with($action, 'auth.') => 'Sesión',
            str_starts_with($action, 'user.') => 'Mi cuenta',
            str_starts_with($action, 'company'), str_starts_with($action, 'license') => 'Administración · Compañía',
            default => 'Administración · Usuarios',
        };
    }

    private function legacyGroups(AuditLog $row): array
    {
        $old = $row->old_values ?? [];
        $new = $row->new_values ?? [];
        $fields = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            $fields[(string) $key] = [$old[$key] ?? null, $new[$key] ?? null];
        }

        if ($fields === []) {
            return [];
        }

        return [['title' => 'Detalle', 'badge' => null, 'action' => 'updated', 'fields' => $this->fields($fields), 'count' => 1, 'items' => []]];
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $fields
     * @return list<array{label: string, old: ?string, new: ?string}>
     */
    private function fields(array $fields): array
    {
        $out = [];

        foreach ($fields as $key => [$old, $new]) {
            $key = (string) $key;
            $screen = ScreenCatalog::find($key);

            $out[] = [
                'label' => $screen['label'] ?? self::fieldLabel($key),
                'old' => $this->display($key, $old, $screen !== null),
                'new' => $this->display($key, $new, $screen !== null),
            ];
        }

        return $out;
    }

    private function display(string $key, mixed $value, bool $level = false): ?string
    {
        $reference = self::REFERENCES[$key] ?? null;

        return match (true) {
            $value === null || $value === '' => null,
            $level && is_string($value) => self::LEVELS[$value] ?? $value,
            $reference !== null && is_numeric($value) => $this->names[$reference][(int) $value] ?? "#{$value}",
            $key === 'status' && is_string($value) => self::STATUSES[$value] ?? $value,
            is_bool($value) => $value ? 'Sí' : 'No',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };
    }

    /**
     * Los nombres de los registros a los que apuntan los campos de la página,
     * en una consulta por tipo (no una por campo).
     *
     * @return array<class-string, array<int, string>>
     */
    private function referenceNames(Collection $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach ($row->changes['records'] ?? [] as $record) {
                foreach ($record['f'] ?? [] as $key => $values) {
                    if (isset(self::REFERENCES[$key])) {
                        foreach ($values as $value) {
                            if (is_numeric($value)) {
                                $ids[self::REFERENCES[$key]][(int) $value] = true;
                            }
                        }
                    }
                }
            }
        }

        $names = [];
        foreach ($ids as $class => $keys) {
            $names[$class] = $class::query()->whereKey(array_keys($keys))->get()
                ->mapWithKeys(fn ($model) => [$model->getKey() => AuditCatalog::subject($model)])
                ->all();
        }

        return $names;
    }

    private function section(?string $screen): ?string
    {
        $found = $screen ? ScreenCatalog::find($screen) : null;

        if ($found === null) {
            return null;
        }

        $section = collect(ScreenCatalog::sections())->firstWhere('key', $found['section']);

        return ($section['label'] ?? '').' · '.$found['label'];
    }

    /** @return Collection<int, int> */
    private function subjectUserIds(Collection $rows): Collection
    {
        return $rows->where('auditable_type', User::class)->pluck('auditable_id');
    }

    /** El nombre de un campo, en español. Uno que no está, con su nombre técnico legible. */
    public static function fieldLabel(string $key): string
    {
        return self::FIELDS[$key] ?? Str::of($key)->replace('_id', '')->replace('_', ' ')->ucfirst()->toString();
    }

    private const FIELDS = [
        'code' => 'Código', 'name' => 'Nombre', 'description' => 'Descripción', 'description_es' => 'Descripción',
        'status' => 'Estado', 'notes' => 'Notas', 'title' => 'Título', 'body' => 'Texto', 'reference' => 'Referencia',
        'email' => 'Correo', 'phone' => 'Teléfono', 'address' => 'Dirección', 'type' => 'Tipo', 'category' => 'Categoría',
        'document_number' => 'Número de documento', 'number' => 'Número', 'consecutive' => 'Consecutivo',
        'document_date' => 'Fecha del documento', 'posting_date' => 'Fecha de contabilización', 'due_date' => 'Vencimiento',
        'date' => 'Fecha', 'start_date' => 'Desde', 'end_date' => 'Hasta', 'from_date' => 'Desde', 'to_date' => 'Hasta',
        'amount' => 'Monto', 'quantity' => 'Cantidad', 'unit_price' => 'Precio unitario', 'unit_cost' => 'Costo unitario',
        'total' => 'Total', 'subtotal' => 'Subtotal', 'discount' => 'Descuento', 'rate' => 'Tasa', 'percentage' => 'Porcentaje',
        'debit' => 'Débito', 'credit' => 'Crédito', 'debit_local' => 'Débito (LC)', 'credit_local' => 'Crédito (LC)',
        'debit_foreign' => 'Débito (FC)', 'credit_foreign' => 'Crédito (FC)', 'exchange_rate' => 'Tipo de cambio',
        'account_id' => 'Cuenta contable', 'gl_account_id' => 'Cuenta contable', 'business_partner_id' => 'Socio de negocio',
        'item_id' => 'Artículo', 'warehouse_id' => 'Almacén', 'cost_center_id' => 'Centro de costo', 'currency_id' => 'Moneda',
        'document_type_id' => 'Tipo de documento', 'tax_rate_id' => 'Indicador de impuesto', 'employee_id' => 'Empleado',
        'department_id' => 'Departamento', 'job_position_id' => 'Puesto', 'item_group_id' => 'Grupo', 'uom_id' => 'Unidad de medida',
        'price_list_id' => 'Lista de precios', 'payroll_period_id' => 'Período de planilla', 'payroll_concept_id' => 'Concepto',
        'journal_entry_id' => 'Asiento', 'fiscal_period_id' => 'Período contable',
        'first_name' => 'Nombre', 'last_name1' => 'Primer apellido', 'last_name2' => 'Segundo apellido',
        'identification_type' => 'Tipo de identificación', 'identification_number' => 'Identificación', 'tax_id' => 'Cédula',
        'ccss_number' => 'Asegurado CCSS', 'birth_date' => 'Fecha de nacimiento', 'hire_date' => 'Fecha de ingreso',
        'termination_date' => 'Fecha de salida', 'termination_reason' => 'Motivo de salida', 'base_salary' => 'Salario base',
        'salary_type' => 'Tipo de salario', 'journey_type' => 'Jornada', 'weekly_hours' => 'Horas semanales',
        'contract_type' => 'Tipo de contrato', 'payment_method' => 'Forma de pago', 'bank_name' => 'Banco',
        'bank_account' => 'Cuenta bancaria', 'account_number' => 'Número de cuenta', 'photo_path' => 'Foto',
        'barcode' => 'Código de barras', 'cabys_code' => 'Código CAByS', 'is_inventory_item' => 'Lleva inventario',
        'is_sales_item' => 'Se vende', 'is_purchase_item' => 'Se compra', 'tracks_lots' => 'Maneja lotes',
        'tracks_serials' => 'Maneja series', 'minimum_stock' => 'Mínimo', 'maximum_stock' => 'Máximo',
        'avg_cost_local' => 'Costo promedio (LC)', 'avg_cost_foreign' => 'Costo promedio (FC)', 'on_hand' => 'Existencia',
        'is_active' => 'Activo', 'accepts_posting' => 'Acepta movimientos', 'account_type' => 'Tipo de cuenta',
        'legal_name' => 'Razón social', 'trade_name' => 'Nombre comercial', 'theme' => 'Tema', 'logo_path' => 'Logo',
        'role_type' => 'Rol', 'year' => 'Año', 'period_number' => 'Período', 'rate_date' => 'Fecha', 'rate_type' => 'Tipo de tasa',
        'credit_limit' => 'Límite de crédito', 'payment_terms_days' => 'Plazo (días)', 'operation' => 'Operación',
        'direction' => 'Dirección', 'days' => 'Días', 'happened_on' => 'Fecha', 'is_confidential' => 'Confidencial',
        'action_type' => 'Tipo de acción', 'effective_date' => 'Vigente desde', 'previous_value' => 'Valor anterior',
        'new_value' => 'Valor nuevo', 'reason' => 'Motivo', 'field' => 'Campo', 'net_pay' => 'Neto a pagar',
        'cutoff_date' => 'Fecha de corte', 'bank_balance' => 'Saldo del banco', 'book_balance' => 'Saldo en libros',
        'frequency_type' => 'Frecuencia', 'next_run_date' => 'Próxima fecha', 'expires_at' => 'Vence',
        'valid_from' => 'Vigente desde', 'valid_to' => 'Vigente hasta', 'prices_include_tax' => 'Precios con IVA',
        'is_default' => 'Predeterminado', 'manages_bins' => 'Maneja ubicaciones', 'decimals' => 'Decimales',
        'module_permissions' => 'Permisos', 'screens' => 'Permisos', 'scope_company_ids' => 'Compañías',
        'requested_access_level' => 'Nivel pedido', 'screen' => 'Pantalla',
    ];
}
