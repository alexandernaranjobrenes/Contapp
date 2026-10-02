<?php

namespace App\Domains\Reporting\Support;

use App\Domains\Core\Models\Company;
use App\Domains\Reporting\DataTransferObjects\ReportHeader;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Arma el encabezado de identidad de empresa + trazabilidad (CLAUDE.md secc.
 * 6) que reutiliza cualquier reporte nuevo, sin lógica adicional por reporte.
 */
class ReportHeaderFactory
{
    public function make(Company $company, User $user, string $title, string $paramsSummary): ReportHeader
    {
        return new ReportHeader(
            companyName: $company->trade_name ?: $company->legal_name,
            taxId: $company->tax_id,
            address: $company->address,
            // La ruta tal cual está guardada, sin tocar el disco: este
            // encabezado también lo arman el XLSX y la pantalla, que no usan
            // el logo. Lo baja el PDF al dibujarse (partials/header).
            logoPath: $company->logo_path,
            title: $title,
            paramsSummary: $paramsSummary,
            generatedByName: $user->name,
            generatedAt: Date::now(),
        );
    }
}
