<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Support\FiscalCatalogs;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Inventory\Models\Item;
use App\Domains\Inventory\Models\ItemGroup;
use App\Domains\Inventory\Models\ItemWarehouse;
use App\Domains\Inventory\Models\UnitOfMeasure;
use App\Domains\Inventory\Services\ItemBulkImporter;
use App\Domains\Inventory\Services\ItemTemplateExporter;
use App\Domains\Inventory\Support\ItemFiscalConsistency;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemController extends Controller
{
    /**
     * Paginado y con filtros: un catálogo de artículos crece sin techo y
     * traerlo entero deja la pantalla inservible al primer cliente con
     * inventario de verdad. Los filtros van de la mano — una lista paginada
     * sin buscador obliga a pasar páginas para encontrar un código.
     */
    public function index(Request $request): Response
    {
        $filters = $this->validateListFilters($request);

        $items = Item::with([
            'itemGroup:id,code,name',
            'unitOfMeasure:id,code,name',
        ])
            // El select() va ANTES de withSum(): withSum agrega su subconsulta
            // al select ya armado, así que declararlo después le pisaba la
            // columna on_hand y la fila llegaba sin existencia.
            ->select([
                'id', 'code', 'name', 'item_group_id', 'uom_id', 'barcode',
                'is_inventory_item', 'is_sales_item', 'is_purchase_item', 'tracks_lots', 'tracks_serials', 'minimum_stock', 'maximum_stock',
                'tax_rate_id', 'cabys_code', 'fiscal_unit_code', 'iva_rate_code',
                'avg_cost_local', 'avg_cost_foreign', 'status',
            ])
            ->withSum('stockLevels as on_hand', 'on_hand')
            ->when($filters['search'] !== null, function ($query) use ($filters) {
                $term = '%'.$filters['search'].'%';

                $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->when($filters['item_group_id'] !== null, fn ($q) => $q->where('item_group_id', $filters['item_group_id']))
            ->when($filters['status'] !== null, fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Items/Index', [
            'items' => $items,
            'filters' => $filters,
            'itemGroups' => ItemGroup::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'unitsOfMeasure' => UnitOfMeasure::where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']),
            'taxRates' => TaxRate::orderBy('code')->get(['id', 'code', 'name', 'percentage']),
            'fiscalUnits' => FiscalCatalogs::UNITS,
            // Los dos catálogos de Hacienda que la ficha necesita ofrecer,
            // como código => etiqueta legible.
            'fiscalIvaRates' => collect(FiscalCatalogs::IVA_RATES)
                ->map(fn (array $rate, string $code) => $code.' — '.$rate['label'])
                ->all(),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $companyId = $currentCompany->id();

        $validated = $request->validate($this->rules($companyId));

        if (Item::where('company_id', $companyId)->where('code', $validated['code'])->exists()) {
            return back()->withErrors(['code' => "Ya existe un artículo con el código {$validated['code']}."])->withInput();
        }

        if ($error = $this->reorderLevelsError($validated)) {
            return back()->withErrors(['maximum_stock' => $error])->withInput();
        }

        if ($error = $this->taxConsistencyError($validated)) {
            return back()->withErrors(['iva_rate_code' => $error])->withInput();
        }

        Item::create([
            ...$this->normalize($validated),
            'code' => $validated['code'],
            'company_id' => $companyId,
        ]);

        return back()->with('success', "Artículo {$validated['code']} creado.");
    }

    /**
     * El costo promedio NO se edita desde acá: lo mantiene exclusivamente el
     * motor de movimientos de stock (Fase 2), porque cada cambio de costo
     * tiene que generar su asiento. Un artículo con existencia tampoco puede
     * dejar de ser de inventario — ver abajo.
     */
    public function update(Request $request, int $item, CurrentCompany $currentCompany): RedirectResponse
    {
        $item = Item::findOrFail($item);

        $validated = $request->validate(
            collect($this->rules($currentCompany->id()))->except('code')->all()
        );

        // Con ?? false igual que en normalize(): la regla es 'boolean' y no
        // 'required', así que un checkbox ausente no llega al array validado
        // y leerlo directo reventaba. Ausente significa lo mismo que
        // desmarcado: es un servicio.
        if (! ($validated['is_inventory_item'] ?? false) && $item->is_inventory_item) {
            if (bccomp($item->onHand(), '0.000000', 6) !== 0) {
                return back()->withErrors([
                    'is_inventory_item' => "El artículo {$item->code} todavía tiene existencias; no se puede convertir en servicio hasta dejarlo en cero.",
                ]);
            }
        }

        if ($error = $this->reorderLevelsError($validated)) {
            return back()->withErrors(['maximum_stock' => $error])->withInput();
        }

        if ($error = $this->taxConsistencyError($validated)) {
            return back()->withErrors(['iva_rate_code' => $error])->withInput();
        }

        $item->update($this->normalize($validated));

        return back()->with('success', "Artículo {$item->code} actualizado.");
    }

    public function destroy(int $item): RedirectResponse
    {
        $item = Item::findOrFail($item);

        if (bccomp($item->onHand(), '0.000000', 6) !== 0) {
            return back()->withErrors([
                'item' => "El artículo {$item->code} todavía tiene existencias; no se puede eliminar. Podés inactivarlo en su lugar.",
            ]);
        }

        // Igual que en almacenes: las filas en cero son rastro de existencia
        // pasada, no movimientos. El kardex inviolable llega en Fase 2 y va a
        // tener que consultarse acá también.
        ItemWarehouse::where('item_id', $item->id)->delete();

        $item->delete();

        return back()->with('success', "Artículo {$item->code} eliminado.");
    }

    /**
     * Plantilla de carga masiva. Si la compañía ya tiene catálogo lo exporta
     * tal cual: editarlo y resubirlo es como se corrigen 800 artículos, y es
     * el uso más frecuente de esta plantilla después del alta inicial.
     */
    public function template(CurrentCompany $currentCompany, ItemTemplateExporter $exporter): StreamedResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        return response()->streamDownload(
            fn () => $exporter->writeTo('php://output', $company),
            'articulos.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        );
    }

    public function import(Request $request, CurrentCompany $currentCompany, ItemBulkImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx'],
        ]);

        $result = $importer->import(
            $request->file('file')->getRealPath(),
            Company::findOrFail($currentCompany->id()),
        );

        if ($result->hasErrors()) {
            return back()->with('importErrors', $result->errors);
        }

        // Creados y actualizados se informan por separado: subir un archivo
        // esperando dar de alta 200 artículos y leer "200 actualizados" es la
        // señal de que se reutilizaron códigos existentes sin querer.
        return back()->with('success', sprintf(
            'Se importaron %d artículo(s): %d nuevo(s) y %d actualizado(s).',
            $result->totalCount(), $result->createdCount, $result->updatedCount,
        ));
    }

    /**
     * @return array{search: ?string, item_group_id: ?int, status: ?string}
     */
    private function validateListFilters(Request $request): array
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'item_group_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $search = isset($validated['search']) ? trim($validated['search']) : '';

        return [
            'search' => $search === '' ? null : $search,
            'item_group_id' => isset($validated['item_group_id']) ? (int) $validated['item_group_id'] : null,
            'status' => $validated['status'] ?? null,
        ];
    }

    private function rules(int $companyId): array
    {
        return [
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'item_group_id' => [
                'nullable',
                Rule::exists('item_groups', 'id')->where('company_id', $companyId),
            ],
            'uom_id' => [
                'required',
                Rule::exists('units_of_measure', 'id')->where('company_id', $companyId),
            ],
            'barcode' => ['nullable', 'string', 'max:255'],
            'is_inventory_item' => ['boolean'],
            'is_sales_item' => ['boolean'],
            'is_purchase_item' => ['boolean'],
            'tracks_lots' => ['boolean'],
            'tracks_serials' => ['boolean'],
            // Niveles por defecto del artículo. Cada almacén puede
            // sobrescribirlos desde su propia pantalla; acá se fija lo que
            // aplica cuando no lo hace.
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            // Datos fiscales de Hacienda. Nullable a propósito: obligar el
            // CAByS acá bloquearía dar de alta el catálogo antes de haber
            // investigado los códigos, que es como se trabaja en la práctica.
            // La factura sí lo exige por línea, y la ficha avisa.
            'cabys_code' => ['nullable', 'string', 'size:13', 'regex:/^\d{13}$/'],
            'fiscal_unit_code' => ['nullable', Rule::in(array_keys(FiscalCatalogs::UNITS))],
            'iva_rate_code' => ['nullable', Rule::in(array_keys(FiscalCatalogs::IVA_RATES))],
            // company_id NULL en tax_rates es el catálogo nacional compartido
            // (ver GlobalOrOwnCompanyScope): exigir company_id = la compañía
            // rechazaría el IVA nacional, que es el caso normal.
            'tax_rate_id' => [
                'nullable',
                Rule::exists('tax_rates', 'id')->where(
                    fn ($query) => $query->where(
                        fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $companyId)
                    )
                ),
            ],
            'status' => ['required', 'in:active,inactive'],
        ];
    }

    /**
     * Misma guarda que en los niveles por almacén: un máximo por debajo del
     * mínimo dejaría la cantidad sugerida en cero y el artículo no se
     * repondría nunca, sin que nada lo avisara.
     */
    /**
     * La regla vive en ItemFiscalConsistency porque la carga masiva tiene que
     * aplicar exactamente la misma: una guarda que solo corre en la ficha
     * daría la impresión de estar protegido mientras el otro camino la evade.
     */
    private function taxConsistencyError(array $validated): ?string
    {
        return ItemFiscalConsistency::error(
            isset($validated['tax_rate_id']) ? (int) $validated['tax_rate_id'] : null,
            $validated['iva_rate_code'] ?? null,
        );
    }

    private function reorderLevelsError(array $validated): ?string
    {
        $minimum = (string) ($validated['minimum_stock'] ?? 0);
        $maximum = $validated['maximum_stock'] ?? null;

        if ($maximum !== null && bccomp((string) $maximum, $minimum, 6) < 0) {
            return 'El máximo no puede ser menor que el mínimo: la sugerencia de compra quedaría en cero y el artículo no se repondría nunca.';
        }

        return null;
    }

    private function normalize(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'item_group_id' => $validated['item_group_id'] ?? null,
            'uom_id' => $validated['uom_id'],
            'barcode' => $validated['barcode'] ?? null,
            'is_inventory_item' => $validated['is_inventory_item'] ?? false,
            'is_sales_item' => $validated['is_sales_item'] ?? false,
            'is_purchase_item' => $validated['is_purchase_item'] ?? false,
            // Un servicio no lleva kardex, así que tampoco puede llevar lotes:
            // no hay existencia que rastrear.
            'tracks_lots' => ($validated['is_inventory_item'] ?? false) && ($validated['tracks_lots'] ?? false),
            // Mismo criterio: sin kardex no hay unidad que identificar.
            'tracks_serials' => ($validated['is_inventory_item'] ?? false) && ($validated['tracks_serials'] ?? false),
            // Por la misma razón, un servicio no tiene nivel de reposición:
            // no hay existencia que reponer.
            'minimum_stock' => ($validated['is_inventory_item'] ?? false)
                ? ($validated['minimum_stock'] ?? 0)
                : 0,
            'maximum_stock' => ($validated['is_inventory_item'] ?? false)
                ? ($validated['maximum_stock'] ?? null)
                : null,
            'tax_rate_id' => $validated['tax_rate_id'] ?? null,
            'cabys_code' => $validated['cabys_code'] ?? null,
            'fiscal_unit_code' => $validated['fiscal_unit_code'] ?? null,
            'iva_rate_code' => $validated['iva_rate_code'] ?? null,
            'status' => $validated['status'],
        ];
    }
}
