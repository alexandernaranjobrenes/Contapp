<?php

namespace App\Domains\Inventory\Reports;

use App\Domains\Core\Models\Company;
use App\Domains\Inventory\Models\InventoryDocument;
use Illuminate\Support\Facades\DB;

/**
 * Todos los movimientos de uno o varios artículos en un período.
 *
 * Es el kardex, pero transversal: el kardex de la ficha responde "qué pasó
 * con ESTE artículo"; este responde "qué pasó en el almacén", "qué salió por
 * ajuste este mes", "quién registró este movimiento". Son preguntas de
 * auditoría, y la respuesta se busca filtrando, no navegando artículo por
 * artículo.
 *
 * ── Los anulados se muestran, no se esconden ─────────────────────────────
 *
 * Un documento anulado sigue en el kardex junto a su reverso: los dos se
 * cancelan y el saldo queda igual. Ocultarlos haría que el reporte no
 * cuadrara con la cuenta de inventario del mayor, donde ambos están. Se
 * marcan para que se entiendan.
 */
class ItemMovementsReport implements InventoryReport
{
    use Concerns\FiltersDateRange;

    public function code(): string
    {
        return 'item-movements';
    }

    public function label(): string
    {
        return 'Movimientos por artículo';
    }

    public function description(): string
    {
        return 'Cada entrada y salida del período con su documento, su costo unitario y el asiento que generó.';
    }

    public function decision(): string
    {
        return 'Por qué un artículo terminó con la existencia que tiene, y qué documento explica cada diferencia.';
    }

    public function group(): string
    {
        return 'Movimiento';
    }

    public function filters(): array
    {
        return [
            new ReportFilter('from', 'Desde', ReportFilter::DATE, default: 'first_day_of_month'),
            new ReportFilter('to', 'Hasta', ReportFilter::DATE, default: 'today'),
            new ReportFilter('item_id', 'Artículo', ReportFilter::SELECT, optionSource: 'items',
                hint: 'Sin elegir uno se listan todos los del período.'),
            new ReportFilter('warehouse_id', 'Almacén', ReportFilter::SELECT, optionSource: 'warehouses'),
            new ReportFilter('item_group_id', 'Grupo', ReportFilter::SELECT, optionSource: 'item_groups'),
            new ReportFilter('operation', 'Operación', ReportFilter::SELECT, options: InventoryDocument::OPERATIONS),
            new ReportFilter('direction', 'Dirección', ReportFilter::SELECT, options: [
                'in' => 'Solo entradas', 'out' => 'Solo salidas',
            ]),
        ];
    }

    public function build(Company $company, array $filters): ReportResult
    {
        $query = DB::table('stock_journals')
            ->join('inventory_document_lines', 'inventory_document_lines.id', '=', 'stock_journals.inventory_document_line_id')
            ->join('inventory_documents', 'inventory_documents.id', '=', 'inventory_document_lines.inventory_document_id')
            ->join('items', 'items.id', '=', 'stock_journals.item_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_journals.warehouse_id')
            ->leftJoin('document_types', 'document_types.id', '=', 'inventory_documents.document_type_id')
            ->leftJoin('business_partners', 'business_partners.id', '=', 'inventory_documents.business_partner_id')
            ->leftJoin('users', 'users.id', '=', 'stock_journals.created_by')
            ->where('stock_journals.company_id', $company->id);

        $rows = $this->inDateRange($query, 'stock_journals.posting_date', $filters['from'], $filters['to'])
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('items.id', $v))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouses.id', $v))
            ->when($filters['item_group_id'] ?? null, fn ($q, $v) => $q->where('items.item_group_id', $v))
            ->when($filters['operation'] ?? null, fn ($q, $v) => $q->where('inventory_documents.operation', $v))
            ->when($filters['direction'] ?? null, fn ($q, $v) => $q->where('stock_journals.direction', $v))
            ->orderBy('stock_journals.posting_date')
            ->orderBy('stock_journals.id')
            ->limit(5000)
            ->get([
                'stock_journals.posting_date', 'stock_journals.direction',
                'stock_journals.quantity', 'stock_journals.unit_cost_local', 'stock_journals.total_cost_local',
                'stock_journals.balance_quantity', 'stock_journals.journal_entry_id',
                'items.code', 'items.name',
                'warehouses.code as warehouse_code',
                'inventory_documents.id as document_id', 'inventory_documents.operation', 'inventory_documents.status',
                'document_types.code as document_type',
                'business_partners.name as partner',
                'users.name as user_name',
            ])
            ->map(fn ($row) => [
                'posting_date' => $row->posting_date,
                'document_type' => $row->document_type,
                'document_id' => $row->document_id,
                'operation' => InventoryDocument::OPERATIONS[$row->operation] ?? $row->operation,
                'code' => $row->code,
                'name' => $row->name,
                'warehouse_code' => $row->warehouse_code,
                'partner' => $row->partner,
                'direction' => $row->direction === 'in' ? 'Entrada' : 'Salida',
                // El signo lo lleva la cantidad presentada, no una columna
                // aparte: una lista donde entradas y salidas se ven iguales
                // obliga a leer dos columnas para entender una fila.
                'quantity' => $row->direction === 'in' ? (float) $row->quantity : -(float) $row->quantity,
                'unit_cost_local' => (float) $row->unit_cost_local,
                'total_cost_local' => $row->direction === 'in'
                    ? (float) $row->total_cost_local
                    : -(float) $row->total_cost_local,
                'balance_quantity' => (float) $row->balance_quantity,
                'status' => $row->status === 'voided' ? 'ANULADO' : '',
                'user_name' => $row->user_name,
            ])
            ->all();

        return new ReportResult(
            columns: [
                new ReportColumn('posting_date', 'Fecha', ReportColumn::DATE),
                new ReportColumn('document_type', 'Tipo'),
                new ReportColumn('document_id', 'Documento'),
                new ReportColumn('operation', 'Operación', width: 24),
                new ReportColumn('code', 'Código'),
                new ReportColumn('name', 'Artículo', width: 24),
                new ReportColumn('warehouse_code', 'Almacén'),
                new ReportColumn('partner', 'Socio', width: 20),
                new ReportColumn('quantity', 'Cantidad', ReportColumn::NUMBER, totalizable: true),
                new ReportColumn('unit_cost_local', 'Costo unit.', ReportColumn::MONEY),
                new ReportColumn('total_cost_local', 'Costo total', ReportColumn::MONEY, totalizable: true),
                new ReportColumn('balance_quantity', 'Saldo del almacén', ReportColumn::NUMBER),
                new ReportColumn('status', 'Estado'),
                new ReportColumn('user_name', 'Registró'),
            ],
            rows: $rows,
            notes: [
                'Las salidas van con signo negativo, así que el total de la columna es el movimiento neto del período.',
                'El saldo es el del ALMACÉN en ese momento, no el global del artículo: con varios almacenes no es una columna acumulativa.',
                'Los documentos anulados aparecen junto a su reverso y se cancelan entre sí; esconderlos haría que el reporte no cuadre con el mayor.',
                'La consulta se corta en 5.000 movimientos: si se alcanza ese tope, acotá el período o filtrá por artículo.',
            ],
        );
    }
}
