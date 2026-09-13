<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use App\Domains\Tax\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    use BelongsToCompany, HasFactory;

    protected $table = 'chart_of_accounts';

    protected $fillable = [
        'company_id', 'code', 'parent_id', 'level', 'description_es', 'description_en',
        'account_type', 'normal_balance', 'currency_mode', 'accepts_posting',
        'requires_business_partner', 'is_cash_account', 'requires_cost_center',
        'is_financial_report', 'section', 'list_order', 'tax_classification', 'tax_rate_id', 'is_active',
    ];

    /**
     * Las 8 clases del gabinete de cuentas (docs/decisiones.md 2026-08-14),
     * en el orden en que se presentan como cajones en la UI.
     */
    public const ACCOUNT_TYPES = [
        'asset' => 'Activos',
        'liability' => 'Pasivos',
        'equity' => 'Patrimonio',
        'income' => 'Ingresos',
        'cost_of_sales' => 'Costo de Ventas',
        'expense' => 'Gastos',
        'other_income' => 'Otros Ingresos',
        'other_expense' => 'Otros Gastos',
    ];

    public const DEBIT_TYPES = ['asset', 'cost_of_sales', 'expense', 'other_expense'];

    /**
     * Usados tanto en la validación del formulario manual como en la
     * plantilla de carga masiva XLSX (docs/decisiones.md 2026-08-13),
     * para que ambos caminos acepten exactamente los mismos valores.
     */
    public const CURRENCY_MODES = [
        'local' => 'Local',
        'foreign' => 'Extranjera',
        'both' => 'Ambas',
    ];

    public const TAX_CLASSIFICATIONS = [
        'none' => 'Ninguno',
        'sales' => 'Ventas',
        'purchases' => 'Compras',
        'iva_general' => 'IVA General',
        'iva_devengado' => 'IVA Devengado',
        'iva_soportado' => 'IVA Soportado',
    ];

    protected function casts(): array
    {
        return [
            'accepts_posting' => 'boolean',
            'requires_business_partner' => 'boolean',
            'is_cash_account' => 'boolean',
            'requires_cost_center' => 'boolean',
            'is_financial_report' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function normalBalanceFor(string $accountType): string
    {
        return in_array($accountType, self::DEBIT_TYPES, true) ? 'debit' : 'credit';
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Cuenta a la que se deriva el impuesto de esta tarifa (ej. "IVA
     * Soportado" o "IVA Devengado" vinculada a IVA 13%) — ver
     * docs/decisiones.md 2026-08-19.
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }
}
