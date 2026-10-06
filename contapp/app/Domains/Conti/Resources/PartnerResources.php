<?php

namespace App\Domains\Conti\Resources;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Models\BankReconciliation;
use App\Domains\BusinessPartners\Models\BpCategory;
use App\Domains\BusinessPartners\Models\BpOpenItem;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Conti\Support\ContiFormat as F;
use App\Domains\Conti\Support\ContiRedactor;
use Illuminate\Database\Eloquent\Builder;

/** Socios de negocio y bancos. */
final class PartnerResources
{
    private const PARTNER_TYPES = ['client' => 'Cliente', 'supplier' => 'Proveedor', 'both' => 'Ambos'];

    private const OPEN_ITEM_STATUSES = ['open' => 'Abierta', 'partial' => 'Parcial', 'closed' => 'Cerrada'];

    /** @return list<ContiResource> */
    public static function all(): array
    {
        return [
            self::partners(),
            self::openItems(),
            self::categories(),
            self::bankAccounts(),
            self::bankReconciliations(),
        ];
    }

    private static function partners(): ContiResource
    {
        // Correo, teléfono y dirección no: son datos de contacto que Conti
        // no necesita (ContiRedactor).
        $row = fn (BusinessPartner $p) => [
            'id' => $p->id,
            'codigo' => $p->code,
            'nombre' => $p->name,
            'tipo' => self::PARTNER_TYPES[$p->type] ?? $p->type,
            'cedula' => $p->tax_id,
            'encargado' => $p->contact_name,
            'actividad_economica' => $p->economic_activity_code,
            'socio_desde' => F::date($p->partner_since),
            'estado' => F::status($p->status),
            'categoria' => F::codeName($p->category),
            'centro_costo' => F::codeName($p->costCenter),
            'cuenta_control' => $p->glAccount ? "{$p->glAccount->code} {$p->glAccount->description_es}" : null,
            'moneda' => $p->currency?->code,
            'lista_precios' => $p->priceList ? F::codeName($p->priceList) : 'predeterminada de la compañía',
            'limite_credito' => F::money($p->credit_limit),
            'plazo_pago_dias' => $p->payment_terms_days,
        ];

        return new ContiResource(
            key: 'socios',
            label: 'Socios de negocio',
            description: 'Clientes y proveedores, con su cuenta de control, condiciones de crédito y lista de precios. El detalle suma su saldo pendiente.',
            screens: ['business_partners.partners', 'billing.sales_orders', 'billing.new_invoice', 'inventory.purchase_orders'],
            query: fn () => BusinessPartner::query()->with([
                'category:id,code,name', 'costCenter:id,code,name', 'glAccount:id,code,description_es',
                'currency:id,code', 'priceList:id,code,name',
            ]),
            row: $row,
            detail: function (BusinessPartner $p) use ($row) {
                $open = $p->openItems()->where('status', '!=', 'closed')->get(['balance', 'due_date']);

                return $row($p) + [
                    'partidas_pendientes' => $open->count(),
                    'saldo_pendiente' => F::money($open->reduce(fn ($carry, $i) => bcadd($carry, (string) $i->balance, 2), '0.00')),
                    'saldo_vencido' => F::money($open->filter(fn ($i) => $i->due_date && $i->due_date->isPast())
                        ->reduce(fn ($carry, $i) => bcadd($carry, (string) $i->balance, 2), '0.00')),
                ];
            },
            search: ['code', 'name', 'tax_id', 'contact_name'],
            filters: [
                'tipo' => [ContiFilters::options(self::PARTNER_TYPES), function (Builder $q, string $v) {
                    $type = ContiFilters::keyFor($v, self::PARTNER_TYPES);
                    // Un socio «Ambos» es cliente y proveedor a la vez.
                    $q->whereIn('type', $type === 'both' ? ['both'] : [$type, 'both']);
                }],
                'estado' => ['active | inactive', ContiFilters::equals('status')],
                'categoria' => ['código de la categoría', ContiFilters::relatedCode('category')],
            ],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function openItems(): ContiResource
    {
        return new ContiResource(
            key: 'partidas-abiertas',
            label: 'Partidas abiertas (cuentas por cobrar y por pagar)',
            description: 'Facturas, saldos iniciales y notas pendientes de cobro o pago de cada socio, con su vencimiento y saldo. Por defecto solo las pendientes.',
            screens: ['business_partners.partners'],
            query: fn () => BpOpenItem::query()
                ->whereHas('businessPartner')
                ->with(['businessPartner:id,code,name,type', 'currency:id,code']),
            row: fn (BpOpenItem $i) => [
                'id' => $i->id,
                'socio' => F::codeName($i->businessPartner),
                'tipo_socio' => self::PARTNER_TYPES[$i->businessPartner?->type] ?? null,
                'documento' => trim(($i->document_type_code ?? '').'-'.($i->document_number ?? ''), '-'),
                'vencimiento' => F::date($i->due_date),
                'dias_vencida' => $i->due_date && $i->status !== 'closed' && $i->due_date->isPast() ? (int) $i->due_date->diffInDays(now()) : 0,
                'moneda' => $i->currency?->code,
                'monto_original' => F::money($i->original_amount),
                'aplicado' => F::money($i->applied_amount),
                'saldo' => F::money($i->balance),
                'estado' => self::OPEN_ITEM_STATUSES[$i->status] ?? $i->status,
            ],
            search: fn (Builder $q, string $term) => $q->where(fn (Builder $w) => $w->where('document_number', 'like', "%{$term}%")
                ->orWhereHas('businessPartner', fn (Builder $p) => $p->where('name', 'like', "%{$term}%")->orWhere('code', $term))),
            filters: [
                'socio' => ['código del socio', ContiFilters::relatedCode('businessPartner')],
                'estado' => [ContiFilters::options(self::OPEN_ITEM_STATUSES).'. Sin este filtro, solo las pendientes.', ContiFilters::oneOf('status', self::OPEN_ITEM_STATUSES)],
                'tipo_socio' => ['cliente | proveedor', function (Builder $q, string $v) {
                    $type = ContiFilters::keyFor($v, self::PARTNER_TYPES);
                    $q->whereHas('businessPartner', fn (Builder $p) => $p->whereIn('type', [$type, 'both']));
                }],
                'vencidas' => ['sí: solo las que ya vencieron', fn (Builder $q, string $v) => ContiFilters::truthy($v) ? $q->whereDate('due_date', '<', now()) : $q],
            ],
            dateColumn: 'due_date',
            orderBy: ['due_date' => 'asc', 'id' => 'asc'],
            codeColumn: null,
            defaultFilter: fn (Builder $q, array $params) => isset($params['estado']) ? $q : $q->where('status', '!=', 'closed'),
        );
    }

    private static function categories(): ContiResource
    {
        return new ContiResource(
            key: 'categorias-socios',
            label: 'Categorías de socios',
            description: 'Las categorías que agrupan clientes y proveedores, y la lista de precios que heredan.',
            screens: ['business_partners.categories', 'business_partners.partners'],
            query: fn () => BpCategory::query()->with('priceList:id,code,name')->withCount('businessPartners'),
            row: fn (BpCategory $c) => [
                'id' => $c->id,
                'codigo' => $c->code,
                'nombre' => $c->name,
                'lista_precios' => F::codeName($c->priceList),
                'socios' => $c->business_partners_count,
            ],
            search: ['code', 'name'],
            orderBy: ['code' => 'asc'],
        );
    }

    private static function bankAccounts(): ContiResource
    {
        return new ContiResource(
            key: 'cuentas-bancarias',
            label: 'Cuentas bancarias',
            description: 'Las cuentas bancarias y la cuenta contable de cada una. El número se muestra con sus últimos cuatro dígitos.',
            screens: ['banking.accounts', 'banking.reconciliations'],
            query: fn () => BankAccount::query()->with(['glAccount:id,code,description_es', 'currency:id,code']),
            row: fn (BankAccount $b) => [
                'id' => $b->id,
                'banco' => $b->bank_name,
                'numero' => ContiRedactor::mask($b->account_number),
                'cuenta_contable' => $b->glAccount ? "{$b->glAccount->code} {$b->glAccount->description_es}" : null,
                'moneda' => $b->currency?->code,
            ],
            search: ['bank_name'],
            orderBy: ['bank_name' => 'asc'],
            codeColumn: null,
        );
    }

    private static function bankReconciliations(): ContiResource
    {
        return new ContiResource(
            key: 'conciliaciones-bancarias',
            label: 'Conciliaciones bancarias',
            description: 'Cada conciliación: fecha de corte, saldo según el banco, saldo según libros, partidas en tránsito y estado.',
            screens: ['banking.reconciliations', 'banking.reconciliation_report'],
            query: fn () => BankReconciliation::query()->whereHas('bankAccount')->with(['bankAccount:id,bank_name,account_number', 'createdBy:id,name']),
            row: fn (BankReconciliation $r) => [
                'id' => $r->id,
                'cuenta_bancaria' => $r->bankAccount ? $r->bankAccount->bank_name.' '.ContiRedactor::mask($r->bankAccount->account_number) : null,
                'corte' => F::date($r->cutoff_date),
                'saldo_banco' => F::money($r->bank_balance),
                'saldo_libros' => F::money($r->book_balance),
                'depositos_no_registrados_en_banco' => F::money($r->unrecorded_deposits),
                'cheques_no_cobrados' => F::money($r->unpaid_checks),
                'creditos_del_banco_sin_registrar' => F::money($r->unrecorded_bank_credits),
                'debitos_del_banco_sin_registrar' => F::money($r->unrecorded_bank_debits),
                'estado' => F::status($r->status),
                'hecha_por' => $r->createdBy?->name,
            ],
            filters: [
                'banco' => ['nombre del banco', fn (Builder $q, string $v) => $q->whereHas('bankAccount', fn (Builder $b) => $b->where('bank_name', 'like', "%{$v}%"))],
                'estado' => ['open | closed', ContiFilters::equals('status')],
            ],
            dateColumn: 'cutoff_date',
            orderBy: ['cutoff_date' => 'desc'],
            codeColumn: null,
        );
    }
}
