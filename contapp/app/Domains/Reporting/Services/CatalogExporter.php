<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Accounting\Models\ChartOfAccount;
use App\Domains\Accounting\Models\CostAllocationRuleLine;
use App\Domains\Accounting\Models\CostCenter;
use App\Domains\BusinessPartners\Models\BusinessPartner;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exporta uno o más catálogos maestros (no transaccionales, sin filtro de
 * fecha) a un solo XLSX, una hoja por catálogo — "programable" en el sentido
 * de que el usuario elige en el formulario cuáles de los 5 catálogos quiere,
 * en vez de recibir siempre los 5 juntos.
 *
 * Todos los catálogos EXCEPTO indicadores de IVA están scopeados por
 * compañía vía el CompanyScope global ya activo en la request autenticada
 * (mismo criterio que CostCenterController::index() — un Model::query() ya
 * viene filtrado por la compañía actual, sin necesitar where('company_id')
 * manual). Los indicadores de IVA son catálogo NACIONAL sin company_id (ver
 * TaxType::class, "el IVA es ley nacional, no varía por compañía").
 */
class CatalogExporter
{
    public const CATALOGS = [
        'chart-of-accounts' => 'Cuentas contables',
        'business-partners' => 'Socios de negocio',
        'cost-centers' => 'Centros de costo',
        'cost-allocation-rules' => 'Normas de reparto',
        'tax-rates' => 'Indicadores de IVA',
    ];

    /**
     * @param  string[]  $catalogs  subconjunto de array_keys(self::CATALOGS)
     */
    public function writeTo(string $outputPath, array $catalogs, bool $includeInactive): void
    {
        $writer = new Writer();
        $writer->openToFile($outputPath);

        $isFirstSheet = true;

        foreach ($catalogs as $catalog) {
            if (! array_key_exists($catalog, self::CATALOGS)) {
                continue;
            }

            if ($isFirstSheet) {
                $writer->getCurrentSheet()->setName(self::CATALOGS[$catalog]);
                $isFirstSheet = false;
            } else {
                $writer->addNewSheetAndMakeItCurrent()->setName(self::CATALOGS[$catalog]);
            }

            match ($catalog) {
                'chart-of-accounts' => $this->writeChartOfAccounts($writer, $includeInactive),
                'business-partners' => $this->writeBusinessPartners($writer, $includeInactive),
                'cost-centers' => $this->writeCostCenters($writer, $includeInactive),
                'cost-allocation-rules' => $this->writeCostAllocationRules($writer, $includeInactive),
                'tax-rates' => $this->writeTaxRates($writer, $includeInactive),
            };
        }

        if ($isFirstSheet) {
            // Ningún catálogo válido seleccionado: OpenSpout ya abrió una
            // hoja por defecto al openToFile() — se deja con un aviso en vez
            // de un XLSX totalmente en blanco sin ninguna pista de qué pasó.
            $writer->addRow(Row::fromValues(['Ningún catálogo seleccionado.']));
        }

        $writer->close();
    }

    private function tableHeaderStyle(): Style
    {
        return (new Style())->setFontBold()->setBackgroundColor('0B1F3A')->setFontColor('FFFFFF');
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }

    private function writeChartOfAccounts(Writer $writer, bool $includeInactive): void
    {
        $accounts = ChartOfAccount::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->with('taxRate')
            ->orderBy('code')
            ->get();

        $writer->addRow(Row::fromValues([
            'Código', 'Descripción (ES)', 'Descripción (EN)', 'Tipo de cuenta', 'Naturaleza',
            'Modo de moneda', 'Acepta asientos', 'Requiere socio de negocio', 'Es cuenta de caja',
            'Requiere centro de costo', 'Clasificación de impuesto', 'Indicador de IVA vinculado', 'Activa',
        ], $this->tableHeaderStyle()));

        foreach ($accounts as $account) {
            $writer->addRow(Row::fromValues([
                $account->code,
                $account->description_es,
                $account->description_en,
                ChartOfAccount::ACCOUNT_TYPES[$account->account_type] ?? $account->account_type,
                $account->normal_balance === 'debit' ? 'Débito' : 'Crédito',
                ChartOfAccount::CURRENCY_MODES[$account->currency_mode] ?? $account->currency_mode,
                $this->boolLabel($account->accepts_posting),
                $this->boolLabel($account->requires_business_partner),
                $this->boolLabel($account->is_cash_account),
                $this->boolLabel($account->requires_cost_center),
                ChartOfAccount::TAX_CLASSIFICATIONS[$account->tax_classification] ?? ($account->tax_classification ?? '—'),
                $account->taxRate ? "{$account->taxRate->code} — {$account->taxRate->name}" : '—',
                $this->boolLabel($account->is_active),
            ]));
        }
    }

    private function writeBusinessPartners(Writer $writer, bool $includeInactive): void
    {
        $typeLabels = ['client' => 'Cliente', 'supplier' => 'Proveedor', 'both' => 'Ambos'];

        $partners = BusinessPartner::query()
            ->when(! $includeInactive, fn ($q) => $q->where('status', 'active'))
            ->with(['category', 'family', 'costCenter', 'glAccount', 'currency'])
            ->orderBy('code')
            ->get();

        $writer->addRow(Row::fromValues([
            'Código', 'Nombre', 'Tipo', 'Cédula/Tax ID', 'Categoría', 'Familia', 'Centro de costo',
            'Cuenta contable', 'Moneda', 'Límite de crédito', 'Plazo de pago (días)', 'Estado',
            'Correo', 'Actividad económica', 'Teléfono', 'Contacto', 'Cliente/proveedor desde',
        ], $this->tableHeaderStyle()));

        foreach ($partners as $partner) {
            $writer->addRow(Row::fromValues([
                $partner->code,
                $partner->name,
                $typeLabels[$partner->type] ?? $partner->type,
                $partner->tax_id ?? '—',
                $partner->category?->name ?? '—',
                $partner->family?->name ?? '—',
                $partner->costCenter ? "{$partner->costCenter->code} — {$partner->costCenter->name}" : '—',
                $partner->glAccount ? "{$partner->glAccount->code} — {$partner->glAccount->description_es}" : '—',
                $partner->currency?->code ?? '—',
                $partner->credit_limit !== null ? (float) $partner->credit_limit : '—',
                $partner->payment_terms_days ?? '—',
                $partner->status === 'active' ? 'Activo' : 'Inactivo',
                $partner->email ?? '—',
                $partner->economic_activity_code ?? '—',
                $partner->phone ?? '—',
                $partner->contact_name ?? '—',
                $partner->partner_since ?? '—',
            ]));
        }
    }

    private function writeCostCenters(Writer $writer, bool $includeInactive): void
    {
        $costCenters = CostCenter::query()
            ->when(! $includeInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get();

        $writer->addRow(Row::fromValues(
            ['Código', 'Nombre', 'Vigente desde', 'Vigente hasta', 'Activo'],
            $this->tableHeaderStyle()
        ));

        foreach ($costCenters as $costCenter) {
            $writer->addRow(Row::fromValues([
                $costCenter->code,
                $costCenter->name,
                (string) $costCenter->start_date,
                $costCenter->end_date ? (string) $costCenter->end_date : '—',
                $this->boolLabel($costCenter->is_active),
            ]));
        }
    }

    private function writeCostAllocationRules(Writer $writer, bool $includeInactive): void
    {
        $rules = CostAllocationRuleLine::query()
            ->join('cost_allocation_rules', 'cost_allocation_rules.id', '=', 'cost_allocation_rule_lines.cost_allocation_rule_id')
            ->join('cost_centers', 'cost_centers.id', '=', 'cost_allocation_rule_lines.cost_center_id')
            ->when(! $includeInactive, fn ($q) => $q->where('cost_allocation_rules.is_active', true))
            ->orderBy('cost_allocation_rules.code')
            ->orderBy('cost_allocation_rule_lines.position')
            ->get([
                'cost_allocation_rules.code as rule_code',
                'cost_allocation_rules.name as rule_name',
                'cost_allocation_rules.valid_from',
                'cost_allocation_rules.valid_until',
                'cost_allocation_rules.is_active',
                'cost_centers.code as cost_center_code',
                'cost_centers.name as cost_center_name',
                'cost_allocation_rule_lines.percentage',
            ]);

        $writer->addRow(Row::fromValues(
            ['Código de norma', 'Nombre de norma', 'Vigente desde', 'Vigente hasta', 'Activa', 'Centro de costo', '% asignado'],
            $this->tableHeaderStyle()
        ));

        foreach ($rules as $line) {
            $writer->addRow(Row::fromValues([
                $line->rule_code,
                $line->rule_name,
                (string) Carbon::parse($line->valid_from)->format('Y-m-d'),
                $line->valid_until ? Carbon::parse($line->valid_until)->format('Y-m-d') : '—',
                $this->boolLabel((bool) $line->is_active),
                "{$line->cost_center_code} — {$line->cost_center_name}",
                (float) $line->percentage,
            ]));
        }
    }

    private function writeTaxRates(Writer $writer, bool $includeInactive): void
    {
        $rates = TaxRate::query()
            ->when(! $includeInactive, fn ($q) => $q->where(function ($q) {
                $today = now()->format('Y-m-d');
                $q->where('effective_from', '<=', $today)
                    ->where(fn ($q2) => $q2->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
            }))
            ->with('taxType')
            ->orderBy('code')
            ->get();

        $writer->addRow(Row::fromValues(
            ['Tipo de impuesto', 'Código', 'Nombre', 'Porcentaje', 'Otorga crédito fiscal', 'Nota de crédito fiscal', 'Vigente desde', 'Vigente hasta'],
            $this->tableHeaderStyle()
        ));

        foreach ($rates as $rate) {
            $writer->addRow(Row::fromValues([
                $rate->taxType?->name ?? '—',
                $rate->code,
                $rate->name,
                (float) $rate->percentage,
                $this->boolLabel($rate->grants_fiscal_credit),
                $rate->fiscal_credit_note ?? '—',
                (string) $rate->effective_from,
                $rate->effective_to ? (string) $rate->effective_to : '—',
            ]));
        }
    }
}
