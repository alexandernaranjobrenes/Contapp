<?php

namespace App\Domains\Accounting\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'company_id', 'code', 'name', 'start_date', 'end_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    /**
     * Sin esto, Carbon serializa un cast 'date' a JSON con hora y zona
     * (ej. "2026-08-16T00:00:00.000000Z") en vez de "2026-08-16" — se nota
     * apenas se muestra en una tabla. No se seleccionan created_at/updated_at
     * en las consultas que exponen este modelo a Inertia, así que forzar
     * Y-m-d acá no les afecta.
     */
    protected function serializeDate(\DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    /**
     * Solo un centro de costo activo y vigente en $date puede recibir
     * movimientos — mismo criterio que accepts_posting en ChartOfAccount,
     * aplicado a la vigencia por fecha en vez de una bandera. Se valida por
     * cada centro dentro de la norma de reparto elegida en la línea (ver
     * PostJournalService::post()), ya no directo por línea.
     */
    public function isPostableOn(\DateTimeInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->start_date->format('Y-m-d') > $date->format('Y-m-d')) {
            return false;
        }

        if ($this->end_date && $this->end_date->format('Y-m-d') < $date->format('Y-m-d')) {
            return false;
        }

        return true;
    }
}
