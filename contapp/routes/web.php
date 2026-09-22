<?php

use App\Http\Controllers\AccountReconciliationController;
use App\Http\Controllers\AgingController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\LicenseActivationController;
use App\Http\Controllers\Backoffice\AuthenticatedSessionController as BackofficeAuthenticatedSessionController;
use App\Http\Controllers\BalanceSheetController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BankReconciliationReportController;
use App\Http\Controllers\BillOfMaterialController;
use App\Http\Controllers\BillingSettingsController;
use App\Http\Controllers\BpCategoryController;
use App\Http\Controllers\BusinessPartnerController;
use App\Http\Controllers\CashFlowProjectionController;
use App\Http\Controllers\CatalogExportController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\CommercialFollowUpController;
use App\Http\Controllers\CommercialInteractionController;
use App\Http\Controllers\CommercialProfileController;
use App\Http\Controllers\CompanyProvisioningController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\CostAllocationRuleController;
use App\Http\Controllers\CostAllocationRuleReportController;
use App\Http\Controllers\CostCenterController;
use App\Http\Controllers\CostCenterReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentTypeController;
use App\Http\Controllers\DocumentTypeNumberSeriesController;
use App\Http\Controllers\DocumentTypeRegisterController;
use App\Http\Controllers\ExchangeRateController;
use App\Http\Controllers\FxRevaluationController;
use App\Http\Controllers\GlDeterminationController;
use App\Http\Controllers\ImportCostController;
use App\Http\Controllers\IncomeStatementController;
use App\Http\Controllers\InventoryAgingController;
use App\Http\Controllers\InventoryDocumentController;
use App\Http\Controllers\InventoryValuationController;
use App\Http\Controllers\InventoryWriteDownController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ItemGroupController;
use App\Http\Controllers\ItemLotController;
use App\Http\Controllers\ItemSerialController;
use App\Http\Controllers\JournalEntryController;
use App\Http\Controllers\JournalEntryScheduleController;
use App\Http\Controllers\LandedCostController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\LotExpiryController;
use App\Http\Controllers\LicenseCategoryController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\MultiCompanyComparisonController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\OpenItemController;
use App\Http\Controllers\PeriodCloseController;
use App\Http\Controllers\PeriodComparisonController;
use App\Http\Controllers\PriceListController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReorderController;
use App\Http\Controllers\SalesDocumentController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SavedReportController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierCreditNoteController;
use App\Http\Controllers\SupplierInvoiceController;
use App\Http\Controllers\TaxRateController;
use App\Http\Controllers\TaxReportController;
use App\Http\Controllers\TrialBalanceController;
use App\Http\Controllers\UnitOfMeasureController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\WarehouseBinController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('activate', [LicenseActivationController::class, 'create'])->name('license-activation.create');
    Route::post('activate', [LicenseActivationController::class, 'store'])->name('license-activation.store');
});

Route::middleware('auth')->group(function () {
    Route::delete('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::put('company-switch', [CompanySwitchController::class, 'update'])->name('company-switch');

    Route::get('companies/create', [CompanyProvisioningController::class, 'create'])->name('companies.create');
    Route::post('companies', [CompanyProvisioningController::class, 'store'])->name('companies.store');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Quinto y último módulo del rollout de enforcement (ver "reports"/
    // "tax"/"banking"/"business_partners" más arriba) — el más grande:
    // catálogo de cuentas, reconciliación de cuentas, saldos iniciales,
    // tipos de documento, centros de costo, normas de reparto, asientos y
    // sus programaciones. Mismo criterio ya probado: 'read' para consultar,
    // 'read_write' para crear/modificar/borrar/importar.
    Route::middleware('module-access:accounting,read')->group(function () {
        Route::get('chart-of-accounts', [ChartOfAccountController::class, 'index'])->name('chart-of-accounts.index');
        Route::get('chart-of-accounts-template', [ChartOfAccountController::class, 'template'])->name('chart-of-accounts.template');
        Route::get('chart-of-accounts/{account}/reconciliation', [AccountReconciliationController::class, 'index'])->name('account-reconciliation.index');
        Route::get('opening-balance', [OpeningBalanceController::class, 'create'])->name('opening-balance.create');
        Route::get('opening-balance-template', [OpeningBalanceController::class, 'template'])->name('opening-balance.template');
        Route::get('document-types', [DocumentTypeController::class, 'index'])->name('document-types.index');
        Route::get('document-types/create', [DocumentTypeController::class, 'create'])->name('document-types.create');
        Route::get('document-types/{documentType}/edit', [DocumentTypeController::class, 'edit'])->name('document-types.edit');
        Route::get('cost-centers', [CostCenterController::class, 'index'])->name('cost-centers.index');
        Route::get('cost-allocation-rules', [CostAllocationRuleController::class, 'index'])->name('cost-allocation-rules.index');
        Route::get('journal-entries', [JournalEntryController::class, 'index'])->name('journal-entries.index');
        Route::get('journal-entries/create', [JournalEntryController::class, 'create'])->name('journal-entries.create');
        Route::get('journal-entries-template', [JournalEntryController::class, 'template'])->name('journal-entries.template');
        Route::get('journal-entries-search', [JournalEntryController::class, 'search'])->name('journal-entries.search');
        Route::get('journal-entries-list-export', [JournalEntryController::class, 'listExport'])->name('journal-entries.list-export');
        Route::get('journal-entries-list-export-pdf', [JournalEntryController::class, 'listExportPdf'])->name('journal-entries.list-export-pdf');
        Route::get('journal-entries/{journalEntry}/edit', [JournalEntryController::class, 'edit'])->name('journal-entries.edit');
        Route::get('journal-entries/{journalEntry}', [JournalEntryController::class, 'show'])->name('journal-entries.show');
        Route::get('journal-entries/{journalEntry}/duplicate', [JournalEntryController::class, 'duplicate'])->name('journal-entries.duplicate');
        Route::get('journal-entries/{journalEntry}/export', [JournalEntryController::class, 'export'])->name('journal-entries.export');
        Route::get('journal-entries/{journalEntry}/export-pdf', [JournalEntryController::class, 'exportPdf'])->name('journal-entries.export-pdf');
        Route::get('journal-entries/{journalEntry}/presentation', [JournalEntryController::class, 'presentation'])->name('journal-entries.presentation');
        Route::get('journal-entry-schedules', [JournalEntryScheduleController::class, 'index'])->name('journal-entry-schedules.index');
    });

    Route::middleware('module-access:accounting,read_write')->group(function () {
        Route::post('chart-of-accounts', [ChartOfAccountController::class, 'store'])->name('chart-of-accounts.store');
        Route::put('chart-of-accounts/{account}', [ChartOfAccountController::class, 'update'])->name('chart-of-accounts.update');
        Route::delete('chart-of-accounts/{account}', [ChartOfAccountController::class, 'destroy'])->name('chart-of-accounts.destroy');
        Route::post('chart-of-accounts-import', [ChartOfAccountController::class, 'import'])->name('chart-of-accounts.import');

        Route::post('chart-of-accounts/{account}/reconciliation', [AccountReconciliationController::class, 'store'])->name('account-reconciliation.store');
        Route::post('chart-of-accounts/{account}/reconciliation/transfer', [AccountReconciliationController::class, 'transfer'])->name('account-reconciliation.transfer');
        Route::post('chart-of-accounts/{account}/reconciliation/reclassify', [AccountReconciliationController::class, 'reclassify'])->name('account-reconciliation.reclassify');
        Route::delete('account-reconciliations/{reconciliation}', [AccountReconciliationController::class, 'destroy'])->name('account-reconciliation.destroy');

        Route::post('opening-balance-import', [OpeningBalanceController::class, 'import'])->name('opening-balance.import');

        Route::post('document-types', [DocumentTypeController::class, 'store'])->name('document-types.store');
        Route::put('document-types/{documentType}', [DocumentTypeController::class, 'update'])->name('document-types.update');

        Route::post('document-types/{documentType}/number-series', [DocumentTypeNumberSeriesController::class, 'store'])
            ->name('document-type-number-series.store');
        Route::put('document-type-number-series/{series}', [DocumentTypeNumberSeriesController::class, 'update'])
            ->name('document-type-number-series.update');
        Route::delete('document-type-number-series/{series}', [DocumentTypeNumberSeriesController::class, 'destroy'])
            ->name('document-type-number-series.destroy');

        Route::post('cost-centers', [CostCenterController::class, 'store'])->name('cost-centers.store');
        Route::put('cost-centers/{costCenter}', [CostCenterController::class, 'update'])->name('cost-centers.update');
        Route::delete('cost-centers/{costCenter}', [CostCenterController::class, 'destroy'])->name('cost-centers.destroy');

        Route::post('cost-allocation-rules', [CostAllocationRuleController::class, 'store'])->name('cost-allocation-rules.store');
        Route::put('cost-allocation-rules/{costAllocationRule}', [CostAllocationRuleController::class, 'update'])->name('cost-allocation-rules.update');
        Route::delete('cost-allocation-rules/{costAllocationRule}', [CostAllocationRuleController::class, 'destroy'])->name('cost-allocation-rules.destroy');

        Route::post('journal-entries', [JournalEntryController::class, 'store'])->name('journal-entries.store');
        Route::post('journal-entries-import', [JournalEntryController::class, 'import'])->name('journal-entries.import');
        Route::put('journal-entries/{journalEntry}', [JournalEntryController::class, 'update'])->name('journal-entries.update');
        Route::delete('journal-entries/{journalEntry}', [JournalEntryController::class, 'destroy'])->name('journal-entries.destroy');
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])->name('journal-entries.reverse');
        Route::put('journal-entries/{journalEntry}/lines/{line}/due-date', [JournalEntryController::class, 'updateLineDueDate'])
            ->name('journal-entries.lines.update-due-date');
        Route::put('journal-entries/{journalEntry}/lines/{line}/business-partner', [JournalEntryController::class, 'linkLineBusinessPartner'])
            ->name('journal-entries.lines.link-business-partner');

        Route::post('journal-entry-schedules', [JournalEntryScheduleController::class, 'store'])->name('journal-entry-schedules.store');
        Route::post('journal-entry-schedules/process-now', [JournalEntryScheduleController::class, 'processNow'])->name('journal-entry-schedules.process-now');
        Route::post('journal-entry-schedules/{journalEntrySchedule}/cancel', [JournalEntryScheduleController::class, 'cancel'])->name('journal-entry-schedules.cancel');
    });

    // Sexto módulo del rollout de enforcement, primero agregado después de
    // cerrarlo (ver los cinco de arriba): catálogos de inventario. Mismo
    // criterio de siempre — 'read' para consultar, 'read_write' para
    // crear/modificar/borrar. Los movimientos de stock llegan en Fase 2
    // (docs/decisiones.md 2026-09-13).
    Route::middleware('module-access:inventory,read')->group(function () {
        Route::get('units-of-measure', [UnitOfMeasureController::class, 'index'])->name('units-of-measure.index');
        Route::get('item-groups', [ItemGroupController::class, 'index'])->name('item-groups.index');
        Route::get('warehouses', [WarehouseController::class, 'index'])->name('warehouses.index');
        Route::get('items', [ItemController::class, 'index'])->name('items.index');
        Route::get('items-template', [ItemController::class, 'template'])->name('items.template');

        // Listas de precios. Viven en inventario porque cuelgan del artículo,
        // aunque las use facturación: es el mismo criterio que los lotes.
        Route::get('price-lists', [PriceListController::class, 'index'])->name('price-lists.index');
        Route::get('price-lists/{priceList}/prices', [PriceListController::class, 'prices'])->name('price-lists.prices');
        Route::get('price-lists-for-customer', [PriceListController::class, 'forCustomer'])->name('price-lists.for-customer');
        Route::get('items/{item}/kardex', [InventoryDocumentController::class, 'kardex'])->name('items.kardex');
        Route::get('gl-determinations', [GlDeterminationController::class, 'index'])->name('gl-determinations.index');
        Route::get('inventory-movements', [InventoryDocumentController::class, 'index'])->name('inventory-movements.index');
        Route::get('inventory-movements/create', [InventoryDocumentController::class, 'create'])->name('inventory-movements.create');
        Route::get('inventory-movements/{inventoryDocument}', [InventoryDocumentController::class, 'show'])->name('inventory-movements.show');
        Route::get('supplier-invoices', [SupplierInvoiceController::class, 'index'])->name('supplier-invoices.index');
        Route::get('supplier-credit-notes/{inventoryDocument}/create', [SupplierCreditNoteController::class, 'create'])->name('supplier-credit-notes.create');
        Route::get('landed-costs', [LandedCostController::class, 'index'])->name('landed-costs.index');
        Route::get('import-costs', [ImportCostController::class, 'index'])->name('import-costs.index');
        Route::get('import-costs/allocate', [ImportCostController::class, 'allocation'])->name('import-costs.allocation');
        Route::get('production-orders', [ProductionOrderController::class, 'index'])->name('production-orders.index');

        // Listas de materiales. La receta dice QUÉ y CUÁNTO lleva un
        // producto, nunca a qué costo: eso lo pone el motor de movimientos
        // al contabilizar la emisión.
        Route::get('bills-of-materials', [BillOfMaterialController::class, 'index'])->name('bills-of-materials.index');
        Route::get('bills-of-materials/{billOfMaterial}/lines', [BillOfMaterialController::class, 'lines'])->name('bills-of-materials.lines');
        Route::get('bills-of-materials/{billOfMaterial}/explode', [BillOfMaterialController::class, 'explode'])->name('bills-of-materials.explode');
        Route::get('warehouses/{warehouse}/bins', [WarehouseBinController::class, 'index'])->name('warehouse-bins.index');

        // Lotes (Fase 8). Cuelgan del artículo igual que las ubicaciones del
        // almacén: fuera de él no significan nada.
        Route::get('items/{item}/lots', [ItemLotController::class, 'index'])->name('item-lots.index');
        Route::get('items/{item}/lots/options', [ItemLotController::class, 'options'])->name('item-lots.options');
        Route::get('items/{item}/lots/{lot}/trace', [ItemLotController::class, 'trace'])->name('item-lots.trace');
        Route::get('lot-expiry', [LotExpiryController::class, 'index'])->name('lot-expiry.index');

        // Series (Fase 9). Cuelgan del artículo igual que los lotes. A
        // diferencia de un lote, que es un balde con cantidad, una serie es
        // una unidad: no se dan de alta acá, nacen con la entrada que las
        // trajo.
        Route::get('items/{item}/serials', [ItemSerialController::class, 'index'])->name('item-serials.index');

        // Reorden. Vive en el módulo de inventario y no en reportería: no es
        // una consulta sino el arranque de una acción — de acá sale la orden
        // de compra. Se exporta porque casi nunca se compra desde la
        // pantalla: el archivo se manda a cotizar o a autorizar primero.
        Route::get('reorder', [ReorderController::class, 'index'])->name('reorder.index');
        Route::get('reorder/export', [ReorderController::class, 'export'])->name('reorder.export');
        Route::get('reorder/export-pdf', [ReorderController::class, 'exportPdf'])->name('reorder.export-pdf');
        Route::get('items/{item}/reorder-levels', [ReorderController::class, 'levels'])->name('reorder.levels');

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
        Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');

        // Deterioro NIC 2. Vive en el módulo de inventario y no en reportería
        // porque contabiliza: es un proceso, no una consulta.
        Route::get('inventory-write-downs', [InventoryWriteDownController::class, 'index'])->name('inventory-write-downs.index');
        Route::get('inventory-write-downs/create', [InventoryWriteDownController::class, 'create'])->name('inventory-write-downs.create');
        Route::get('inventory-write-downs/{inventoryWriteDown}', [InventoryWriteDownController::class, 'show'])->name('inventory-write-downs.show');

        Route::get('stock-transfers', [StockTransferController::class, 'index'])->name('stock-transfers.index');
        Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
        Route::get('stock-counts/create', [StockCountController::class, 'create'])->name('stock-counts.create');
        Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');
        Route::get('stock-counts/{stockCount}/print', [StockCountController::class, 'print'])->name('stock-counts.print');
    });

    Route::middleware('module-access:inventory,read_write')->group(function () {
        Route::post('units-of-measure', [UnitOfMeasureController::class, 'store'])->name('units-of-measure.store');
        Route::put('units-of-measure/{unitOfMeasure}', [UnitOfMeasureController::class, 'update'])->name('units-of-measure.update');
        Route::delete('units-of-measure/{unitOfMeasure}', [UnitOfMeasureController::class, 'destroy'])->name('units-of-measure.destroy');

        Route::post('item-groups', [ItemGroupController::class, 'store'])->name('item-groups.store');
        Route::put('item-groups/{itemGroup}', [ItemGroupController::class, 'update'])->name('item-groups.update');
        Route::delete('item-groups/{itemGroup}', [ItemGroupController::class, 'destroy'])->name('item-groups.destroy');

        Route::post('warehouses', [WarehouseController::class, 'store'])->name('warehouses.store');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->name('warehouses.destroy');

        Route::post('items', [ItemController::class, 'store'])->name('items.store');
        Route::post('items-import', [ItemController::class, 'import'])->name('items.import');

        Route::post('bills-of-materials', [BillOfMaterialController::class, 'store'])->name('bills-of-materials.store');
        Route::put('bills-of-materials/{billOfMaterial}', [BillOfMaterialController::class, 'update'])->name('bills-of-materials.update');
        Route::delete('bills-of-materials/{billOfMaterial}', [BillOfMaterialController::class, 'destroy'])->name('bills-of-materials.destroy');
        Route::put('bills-of-materials/{billOfMaterial}/lines', [BillOfMaterialController::class, 'updateLines'])->name('bills-of-materials.lines.update');

        Route::post('price-lists', [PriceListController::class, 'store'])->name('price-lists.store');
        Route::put('price-lists/{priceList}', [PriceListController::class, 'update'])->name('price-lists.update');
        Route::delete('price-lists/{priceList}', [PriceListController::class, 'destroy'])->name('price-lists.destroy');
        Route::put('price-lists/{priceList}/prices', [PriceListController::class, 'updatePrices'])->name('price-lists.prices.update');
        Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update');
        Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');

        Route::post('gl-determinations', [GlDeterminationController::class, 'store'])->name('gl-determinations.store');
        Route::put('gl-determinations/{glDetermination}', [GlDeterminationController::class, 'update'])->name('gl-determinations.update');
        Route::delete('gl-determinations/{glDetermination}', [GlDeterminationController::class, 'destroy'])->name('gl-determinations.destroy');

        Route::post('inventory-movements', [InventoryDocumentController::class, 'store'])->name('inventory-movements.store');
        Route::post('inventory-movements/{inventoryDocument}/void', [InventoryDocumentController::class, 'void'])->name('inventory-movements.void');
        Route::post('supplier-invoices', [SupplierInvoiceController::class, 'store'])->name('supplier-invoices.store');
        Route::post('supplier-credit-notes/{inventoryDocument}', [SupplierCreditNoteController::class, 'store'])->name('supplier-credit-notes.store');
        Route::post('landed-costs', [LandedCostController::class, 'store'])->name('landed-costs.store');
        Route::post('import-costs', [ImportCostController::class, 'store'])->name('import-costs.store');
        Route::post('import-costs/allocate', [ImportCostController::class, 'allocate'])->name('import-costs.allocate');
        Route::post('import-costs/{importCost}/cancel', [ImportCostController::class, 'cancel'])->name('import-costs.cancel');

        Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
        Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
        Route::post('stock-counts/{stockCount}/capture', [StockCountController::class, 'capture'])->name('stock-counts.capture');
        Route::post('stock-counts/{stockCount}/post', [StockCountController::class, 'post'])->name('stock-counts.post');
        Route::post('stock-counts/{stockCount}/cancel', [StockCountController::class, 'cancel'])->name('stock-counts.cancel');

        Route::post('production-orders', [ProductionOrderController::class, 'store'])->name('production-orders.store');
        Route::post('production-orders/{productionOrder}/issue', [ProductionOrderController::class, 'issue'])->name('production-orders.issue');
        Route::post('production-orders/{productionOrder}/receive', [ProductionOrderController::class, 'receive'])->name('production-orders.receive');
        Route::post('production-orders/{productionOrder}/close', [ProductionOrderController::class, 'close'])->name('production-orders.close');

        Route::post('warehouses/{warehouse}/bins', [WarehouseBinController::class, 'store'])->name('warehouse-bins.store');
        Route::put('warehouses/{warehouse}/bins/{bin}', [WarehouseBinController::class, 'update'])->name('warehouse-bins.update');
        Route::delete('warehouses/{warehouse}/bins/{bin}', [WarehouseBinController::class, 'destroy'])->name('warehouse-bins.destroy');

        Route::post('reorder/order', [ReorderController::class, 'order'])->name('reorder.order');
        Route::put('items/{item}/reorder-levels', [ReorderController::class, 'updateLevels'])->name('reorder.levels.update');

        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
        Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
        Route::post('purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');

        Route::post('inventory-write-downs', [InventoryWriteDownController::class, 'store'])->name('inventory-write-downs.store');

        Route::put('items/{item}/serials/{serial}', [ItemSerialController::class, 'update'])->name('item-serials.update');
        Route::post('items/{item}/serials/{serial}/scrap', [ItemSerialController::class, 'scrap'])->name('item-serials.scrap');

        // Las series no se crean acá: nacen con la entrada que las trajo.
        // Solo se edita lo que el movimiento no sabe (garantía, notas) y la
        // baja de una unidad que ya salió.
        Route::put('items/{item}/serials/{serial}', [ItemSerialController::class, 'update'])->name('item-serials.update');
        Route::post('items/{item}/serials/{serial}/scrap', [ItemSerialController::class, 'scrap'])->name('item-serials.scrap');

        Route::post('items/{item}/lots', [ItemLotController::class, 'store'])->name('item-lots.store');
        Route::put('items/{item}/lots/{lot}', [ItemLotController::class, 'update'])->name('item-lots.update');
        Route::delete('items/{item}/lots/{lot}', [ItemLotController::class, 'destroy'])->name('item-lots.destroy');
    });

    // Séptimo módulo del rollout de enforcement: facturación electrónica.
    Route::middleware('module-access:billing,read')->group(function () {
        Route::get('sales-documents', [SalesDocumentController::class, 'index'])->name('sales-documents.index');
        Route::get('sales-documents/create', [SalesDocumentController::class, 'create'])->name('sales-documents.create');
        Route::get('sales-documents/{salesDocument}', [SalesDocumentController::class, 'show'])->name('sales-documents.show');
        Route::get('sales-documents/{salesDocument}/xml', [SalesDocumentController::class, 'xml'])->name('sales-documents.xml');
        Route::get('sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
        Route::get('sales-orders/create', [SalesOrderController::class, 'create'])->name('sales-orders.create');
        Route::get('sales-orders/{salesOrder}', [SalesOrderController::class, 'show'])->name('sales-orders.show');
        Route::get('billing-settings', [BillingSettingsController::class, 'index'])->name('billing-settings.index');
    });

    Route::middleware('module-access:billing,read_write')->group(function () {
        Route::post('sales-documents', [SalesDocumentController::class, 'store'])->name('sales-documents.store');
        Route::post('sales-orders', [SalesOrderController::class, 'store'])->name('sales-orders.store');
        Route::post('sales-orders/{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('sales-orders.cancel');

        Route::post('billing-settings/activities', [BillingSettingsController::class, 'storeActivity'])->name('billing-settings.activities.store');
        Route::delete('billing-settings/activities/{activity}', [BillingSettingsController::class, 'destroyActivity'])->name('billing-settings.activities.destroy');
        Route::post('billing-settings/tax-accounts', [BillingSettingsController::class, 'storeTaxAccount'])->name('billing-settings.tax-accounts.store');
        Route::delete('billing-settings/tax-accounts/{taxAccount}', [BillingSettingsController::class, 'destroyTaxAccount'])->name('billing-settings.tax-accounts.destroy');
        Route::post('billing-settings/payment-accounts', [BillingSettingsController::class, 'storePaymentAccount'])->name('billing-settings.payment-accounts.store');
        Route::delete('billing-settings/payment-accounts/{paymentAccount}', [BillingSettingsController::class, 'destroyPaymentAccount'])->name('billing-settings.payment-accounts.destroy');
    });

    // Cuarto módulo del rollout de enforcement (ver "reports"/"tax"/"banking"
    // más arriba): mismo criterio ya probado en "banking" — lectura exige
    // 'read', escritura exige 'read_write'.
    Route::middleware('module-access:business_partners,read')->group(function () {
        Route::get('bp-categories', [BpCategoryController::class, 'index'])->name('bp-categories.index');
        Route::get('business-partners', [BusinessPartnerController::class, 'index'])->name('business-partners.index');
        Route::get('business-partners/create', [BusinessPartnerController::class, 'create'])->name('business-partners.create');
        Route::get('business-partners/{businessPartner}/edit', [BusinessPartnerController::class, 'edit'])->name('business-partners.edit');
        Route::get('business-partners/{businessPartner}/open-items', [OpenItemController::class, 'index'])
            ->name('business-partners.open-items');
    });

    Route::middleware('module-access:business_partners,read_write')->group(function () {
        Route::post('bp-categories', [BpCategoryController::class, 'store'])->name('bp-categories.store');
        Route::put('bp-categories/{bpCategory}', [BpCategoryController::class, 'update'])->name('bp-categories.update');
        Route::delete('bp-categories/{bpCategory}', [BpCategoryController::class, 'destroy'])->name('bp-categories.destroy');

        Route::post('business-partners', [BusinessPartnerController::class, 'store'])->name('business-partners.store');
        Route::put('business-partners/{businessPartner}', [BusinessPartnerController::class, 'update'])->name('business-partners.update');

        Route::post('business-partners/{businessPartner}/open-items/{openItem}/apply', [OpenItemController::class, 'applyPayment'])
            ->name('business-partners.open-items.apply');

        Route::put('business-partners/{businessPartner}/open-items/{openItem}', [OpenItemController::class, 'updateDueDate'])
            ->name('business-partners.open-items.update-due-date');

        Route::post('business-partners/{businessPartner}/open-items/reconcile', [OpenItemController::class, 'reconcile'])
            ->name('business-partners.open-items.reconcile');
    });

    // Tercer módulo del rollout de enforcement (ver "reports"/"tax" más
    // arriba): primero acá donde de verdad importa la diferencia entre
    // 'read' y 'read_write' — consultar cuentas/conciliaciones exige
    // 'read', pero crear/confirmar/cerrar exige 'read_write'.
    Route::middleware('module-access:banking,read')->group(function () {
        Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::get('bank-accounts/create', [BankAccountController::class, 'create'])->name('bank-accounts.create');

        Route::get('bank-reconciliations', [BankReconciliationController::class, 'hub'])->name('bank-reconciliations.hub');

        Route::get('bank-accounts/{bankAccount}/reconciliations', [BankReconciliationController::class, 'index'])
            ->name('bank-reconciliations.index');
        Route::get('bank-accounts/{bankAccount}/reconciliations/create', [BankReconciliationController::class, 'create'])
            ->name('bank-reconciliations.create');

        Route::get('bank-reconciliations/{reconciliation}', [BankReconciliationController::class, 'show'])
            ->name('bank-reconciliations.show');

        Route::get('bank-reconciliation-report', [BankReconciliationReportController::class, 'index'])
            ->name('bank-reconciliation-report.index');
        Route::get('bank-reconciliation-report/export', [BankReconciliationReportController::class, 'export'])
            ->name('bank-reconciliation-report.export');
    });

    Route::middleware('module-access:banking,read_write')->group(function () {
        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
        Route::put('bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->name('bank-accounts.update');

        Route::post('bank-accounts/{bankAccount}/reconciliations', [BankReconciliationController::class, 'store'])
            ->name('bank-reconciliations.store');

        Route::post('bank-reconciliations/{reconciliation}/lines/{line}/confirm', [BankReconciliationController::class, 'confirmLine'])
            ->name('bank-reconciliations.confirm-line');
        Route::post('bank-reconciliations/{reconciliation}/close', [BankReconciliationController::class, 'close'])
            ->name('bank-reconciliations.close');
        Route::post('bank-reconciliations/{reconciliation}/reopen', [BankReconciliationController::class, 'reopen'])
            ->name('bank-reconciliations.reopen');
        Route::delete('bank-reconciliations/{reconciliation}', [BankReconciliationController::class, 'destroy'])
            ->name('bank-reconciliations.destroy');
    });

    Route::middleware('module-access:accounting,read')->group(function () {
        Route::get('ledger/{dimension}/{id}', [LedgerController::class, 'show'])
            ->where('dimension', 'account|business-partner|cost-center')
            ->name('ledger.show');
        Route::get('ledger/{dimension}/{id}/export', [LedgerController::class, 'export'])
            ->where('dimension', 'account|business-partner|cost-center')
            ->name('ledger.export');

        Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');

        Route::get('fx-revaluation', [FxRevaluationController::class, 'create'])->name('fx-revaluation.create');
        Route::get('fx-revaluation-runs', [FxRevaluationController::class, 'index'])->name('fx-revaluation.index');
        Route::get('fx-revaluation-runs/{run}', [FxRevaluationController::class, 'show'])->name('fx-revaluation.show');
    });

    Route::middleware('module-access:accounting,read_write')->group(function () {
        Route::post('exchange-rates', [ExchangeRateController::class, 'store'])->name('exchange-rates.store');
        Route::post('exchange-rates/sync', [ExchangeRateController::class, 'sync'])->name('exchange-rates.sync');
        Route::delete('exchange-rates/{exchangeRate}', [ExchangeRateController::class, 'destroy'])->name('exchange-rates.destroy');

        Route::post('fx-revaluation/preview', [FxRevaluationController::class, 'preview'])->name('fx-revaluation.preview');
        Route::post('fx-revaluation', [FxRevaluationController::class, 'store'])->name('fx-revaluation.store');
    });

    // Piloto de enforcement de module_permissions (docs/decisiones.md):
    // estas 6 pantallas de reportería exigen al menos 'read' en el módulo
    // "reports" (Superusuario siempre pasa). tax-report queda fuera a
    // propósito — es del módulo "tax", no de esta iniciativa de reportería.
    Route::middleware('module-access:reports,read')->group(function () {
        // Existencias valorizadas: vive en reportería y no en el módulo de
        // inventario porque su razón de ser es contable — amarrar el kardex
        // con la cuenta de inventario del balance — y porque el permiso que
        // corresponde es el de ver reportes, no el de mover mercancía.
        Route::get('reports/inventory-aging', [InventoryAgingController::class, 'index'])->name('reports.inventory-aging.index');
        Route::get('reports/inventory-aging/export', [InventoryAgingController::class, 'export'])->name('reports.inventory-aging.export');
        Route::get('reports/inventory-aging/export-pdf', [InventoryAgingController::class, 'exportPdf'])->name('reports.inventory-aging.export-pdf');

        Route::get('reports/inventory-valuation', [InventoryValuationController::class, 'index'])->name('reports.inventory-valuation.index');
        Route::get('reports/inventory-valuation/export', [InventoryValuationController::class, 'export'])->name('reports.inventory-valuation.export');
        Route::get('reports/inventory-valuation/export-pdf', [InventoryValuationController::class, 'exportPdf'])->name('reports.inventory-valuation.export-pdf');

        Route::get('reports/trial-balance', [TrialBalanceController::class, 'index'])->name('reports.trial-balance.index');
        Route::get('reports/trial-balance/export', [TrialBalanceController::class, 'export'])->name('reports.trial-balance.export');
        Route::get('reports/trial-balance/export-pdf', [TrialBalanceController::class, 'exportPdf'])->name('reports.trial-balance.export-pdf');

        Route::get('reports/income-statement', [IncomeStatementController::class, 'index'])->name('reports.income-statement.index');
        Route::get('reports/income-statement/export', [IncomeStatementController::class, 'export'])->name('reports.income-statement.export');
        Route::get('reports/income-statement/export-pdf', [IncomeStatementController::class, 'exportPdf'])->name('reports.income-statement.export-pdf');

        Route::get('reports/balance-sheet', [BalanceSheetController::class, 'index'])->name('reports.balance-sheet.index');
        Route::get('reports/balance-sheet/export', [BalanceSheetController::class, 'export'])->name('reports.balance-sheet.export');
        Route::get('reports/balance-sheet/export-pdf', [BalanceSheetController::class, 'exportPdf'])->name('reports.balance-sheet.export-pdf');

        Route::get('reports/aging', [AgingController::class, 'index'])->name('reports.aging.index');
        Route::get('reports/aging/export', [AgingController::class, 'export'])->name('reports.aging.export');
        Route::get('reports/aging/export-pdf', [AgingController::class, 'exportPdf'])->name('reports.aging.export-pdf');

        Route::get('reports/cash-flow-projection', [CashFlowProjectionController::class, 'index'])->name('reports.cash-flow-projection.index');
        Route::get('reports/cash-flow-projection/export', [CashFlowProjectionController::class, 'export'])->name('reports.cash-flow-projection.export');
        Route::get('reports/cash-flow-projection/export-pdf', [CashFlowProjectionController::class, 'exportPdf'])->name('reports.cash-flow-projection.export-pdf');

        Route::get('reports/multi-company-comparison', [MultiCompanyComparisonController::class, 'index'])->name('reports.multi-company-comparison.index');
        Route::get('reports/multi-company-comparison/export', [MultiCompanyComparisonController::class, 'export'])->name('reports.multi-company-comparison.export');
        Route::get('reports/multi-company-comparison/export-pdf', [MultiCompanyComparisonController::class, 'exportPdf'])->name('reports.multi-company-comparison.export-pdf');

        Route::get('reports/cost-center', [CostCenterReportController::class, 'index'])->name('reports.cost-center.index');
        Route::get('reports/cost-center/export', [CostCenterReportController::class, 'export'])->name('reports.cost-center.export');
        Route::get('reports/cost-center/export-pdf', [CostCenterReportController::class, 'exportPdf'])->name('reports.cost-center.export-pdf');

        Route::get('reports/cost-allocation-rule', [CostAllocationRuleReportController::class, 'index'])->name('reports.cost-allocation-rule.index');
        Route::get('reports/cost-allocation-rule/export', [CostAllocationRuleReportController::class, 'export'])->name('reports.cost-allocation-rule.export');
        Route::get('reports/cost-allocation-rule/export-pdf', [CostAllocationRuleReportController::class, 'exportPdf'])->name('reports.cost-allocation-rule.export-pdf');

        Route::get('reports/period-comparison', [PeriodComparisonController::class, 'index'])->name('reports.period-comparison.index');
        Route::get('reports/period-comparison/export', [PeriodComparisonController::class, 'export'])->name('reports.period-comparison.export');
        Route::get('reports/period-comparison/export-pdf', [PeriodComparisonController::class, 'exportPdf'])->name('reports.period-comparison.export-pdf');

        Route::get('reports/catalog-export', [CatalogExportController::class, 'index'])->name('reports.catalog-export.index');
        Route::get('reports/catalog-export/export', [CatalogExportController::class, 'export'])->name('reports.catalog-export.export');

        Route::get('reports/document-type-register', [DocumentTypeRegisterController::class, 'index'])->name('reports.document-type-register.index');
        Route::get('reports/document-type-register/export', [DocumentTypeRegisterController::class, 'export'])->name('reports.document-type-register.export');

        Route::get('saved-reports', [SavedReportController::class, 'index'])->name('saved-reports.index');
        Route::post('saved-reports/{savedReport}/invoke', [SavedReportController::class, 'invoke'])->name('saved-reports.invoke');
    });

    // Reportes guardados (ver App\Domains\Reporting\Support\ReportCatalog):
    // primera vez que el módulo "reports" usa 'read_write' — hasta ahora
    // todo era 'read' por ser puramente de lectura. Guardar/editar/borrar
    // una combinación de parámetros sí es una escritura real.
    Route::middleware('module-access:reports,read_write')->group(function () {
        Route::post('saved-reports', [SavedReportController::class, 'store'])->name('saved-reports.store');
        Route::put('saved-reports/{savedReport}', [SavedReportController::class, 'update'])->name('saved-reports.update');
        Route::delete('saved-reports/{savedReport}', [SavedReportController::class, 'destroy'])->name('saved-reports.destroy');
    });

    // Segundo módulo del rollout de enforcement (ver "reports" más arriba):
    // el reporte de IVA en sí exige 'read' de "tax". El catálogo de
    // indicadores (tax-rates.index, más abajo) queda deliberadamente FUERA
    // del gate: otras pantallas (catálogo de cuentas) necesitan que
    // cualquier usuario autenticado pueda verlo para vincular sus cuentas,
    // sin importar su permiso sobre el módulo "tax" en sí.
    Route::middleware('module-access:tax,read')->group(function () {
        Route::get('tax-report', [TaxReportController::class, 'index'])->name('tax-report.index');
    });

    // Catálogo de indicadores de impuesto: el nacional (company_id NULL, ver
    // App\Domains\Tax\Models\TaxType) solo se lee acá — crear/editar/
    // eliminarlo vive en el panel del Propietario (grupo 'backoffice' más
    // abajo) para no dejar que una compañía altere el catálogo de todas.
    // store/update/destroy (sin sufijo "Global") SÍ son de escritura acá:
    // operan solo sobre los indicadores PROPIOS de la compañía activa (ver
    // GlobalOrOwnCompanyScope) — nunca sobre el catálogo nacional.
    Route::get('tax-rates', [TaxRateController::class, 'index'])->name('tax-rates.index');
    Route::post('tax-rates', [TaxRateController::class, 'store'])->name('tax-rates.store');
    Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'update'])->name('tax-rates.update');
    Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroy'])->name('tax-rates.destroy');

    Route::middleware('module-access:accounting,read')->group(function () {
        Route::get('period-close', [PeriodCloseController::class, 'index'])->name('period-close.index');
    });

    // period-close.reopen exige ADEMÁS FiscalPeriodPolicy::reopen() (solo
    // super usuario, ver el propio controlador) — dos capas independientes,
    // no se reemplazan entre sí: el gate de módulo no delega esa regla.
    Route::middleware('module-access:accounting,read_write')->group(function () {
        Route::post('period-close/years', [PeriodCloseController::class, 'createYear'])->name('period-close.create-year');
        Route::post('period-close/periods/{period}/close', [PeriodCloseController::class, 'close'])->name('period-close.close');
        Route::post('period-close/periods/{period}/reopen', [PeriodCloseController::class, 'reopen'])->name('period-close.reopen');
        Route::post('period-close/years/{fiscalYear}/close', [PeriodCloseController::class, 'closeYear'])->name('period-close.close-year');
    });

    Route::middleware('can-manage-users')->group(function () {
        Route::get('users', [UserManagementController::class, 'index'])->name('users.index');
        Route::get('users/create', [UserManagementController::class, 'create'])->name('users.create');
        Route::post('users', [UserManagementController::class, 'store'])->name('users.store');
        Route::get('users/lookup', [UserManagementController::class, 'lookup'])->name('users.lookup');
        Route::post('users/invite', [UserManagementController::class, 'storeInvite'])->name('users.invite');
        Route::get('users/{user}/permissions', [UserManagementController::class, 'editPermissions'])->name('users.permissions.edit');
        Route::put('users/{user}/permissions', [UserManagementController::class, 'updatePermissions'])->name('users.permissions.update');
        Route::post('users/{user}/suspend', [UserManagementController::class, 'suspend'])->name('users.suspend');
        Route::post('users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->name('users.reactivate');
        Route::post('users/{user}/deactivate', [UserManagementController::class, 'deactivate'])->name('users.deactivate');
    });
});

// Plano del Propietario (CLAUDE.md secc. 11/14): guard 'propietario'
// completamente separado del guard 'web' de arriba — nunca comparte sesión
// ni modelo con Superusuario/Administrador/Usuario. Prefijo /backoffice en
// vez de un subdominio: este proyecto no tiene infraestructura de
// subdominio/hosts local, y CLAUDE.md ofrece ambas formas como válidas.
Route::middleware('guest:propietario')->prefix('backoffice')->name('backoffice.')->group(function () {
    Route::get('login', [BackofficeAuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [BackofficeAuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth:propietario')->prefix('backoffice')->name('backoffice.')->group(function () {
    Route::delete('logout', [BackofficeAuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('licenses', [LicenseController::class, 'index'])->name('licenses.index');
    Route::post('licenses', [LicenseController::class, 'store'])->name('licenses.store');
    Route::put('licenses/{license}', [LicenseController::class, 'update'])->name('licenses.update');
    Route::post('licenses/{license}/renew', [LicenseController::class, 'renew'])->name('licenses.renew');
    Route::post('licenses/{license}/revoke', [LicenseController::class, 'revoke'])->name('licenses.revoke');
    Route::post('licenses/{license}/suspend', [LicenseController::class, 'suspend'])->name('licenses.suspend');
    Route::post('licenses/{license}/reactivate', [LicenseController::class, 'reactivate'])->name('licenses.reactivate');

    Route::get('license-categories', [LicenseCategoryController::class, 'index'])->name('license-categories.index');
    Route::post('license-categories', [LicenseCategoryController::class, 'store'])->name('license-categories.store');
    Route::put('license-categories/{licenseCategory}', [LicenseCategoryController::class, 'update'])->name('license-categories.update');
    Route::delete('license-categories/{licenseCategory}', [LicenseCategoryController::class, 'destroy'])->name('license-categories.destroy');

    Route::get('licenses/{license}/commercial-profile', [CommercialProfileController::class, 'show'])->name('licenses.commercial-profile.show');
    Route::post('licenses/{license}/commercial-profile', [CommercialProfileController::class, 'store'])->name('licenses.commercial-profile.store');
    Route::post('licenses/{license}/commercial-interactions', [CommercialInteractionController::class, 'store'])->name('licenses.commercial-interactions.store');
    Route::post('licenses/{license}/commercial-follow-ups', [CommercialFollowUpController::class, 'store'])->name('licenses.commercial-follow-ups.store');
    Route::post('commercial-follow-ups/{commercialFollowUp}/complete', [CommercialFollowUpController::class, 'complete'])->name('commercial-follow-ups.complete');

    // Catálogo de IVA (ver nota más arriba, junto a tax-rates.index): mudado
    // acá desde el grupo de compañía — es administración del catálogo
    // NACIONAL (company_id NULL), no de los indicadores propios de ninguna
    // compañía en particular (esos se manejan con store/update/destroy sin
    // sufijo, desde la compañía misma).
    Route::get('tax-rates', [TaxRateController::class, 'backofficeIndex'])->name('tax-rates.index');
    Route::post('tax-rates', [TaxRateController::class, 'storeGlobal'])->name('tax-rates.store');
    Route::put('tax-rates/{taxRate}', [TaxRateController::class, 'updateGlobal'])->name('tax-rates.update');
    Route::delete('tax-rates/{taxRate}', [TaxRateController::class, 'destroyGlobal'])->name('tax-rates.destroy');
});
