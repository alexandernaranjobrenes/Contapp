<?php

namespace App\Domains\Reporting\Services;

use App\Domains\Reporting\Models\SavedReport;
use App\Domains\Reporting\Support\RelativeDate;
use App\Domains\Reporting\Support\ReportCatalog;
use App\Models\User;

class SavedReportService
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function create(User $user, string $reportCode, string $name, array $parameters, bool $isShared): SavedReport
    {
        $report = new SavedReport([
            'report_code' => $reportCode,
            'name' => $name,
            'parameters' => $parameters,
            'is_shared' => $isShared,
        ]);
        $report->user_id = $user->id;
        $report->save();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function update(SavedReport $report, string $name, array $parameters, bool $isShared): SavedReport
    {
        $report->update([
            'name' => $name,
            'parameters' => $parameters,
            'is_shared' => $isShared,
        ]);

        return $report;
    }

    public function delete(SavedReport $report): void
    {
        $report->delete();
    }

    /**
     * Resuelve el payload guardado a un array plano listo para pasar como
     * query params a la ruta index() del reporte — cada clave de fecha
     * relativa se resuelve AHORA, nunca queda congelada en el valor que
     * tenía al guardar. Marca last_run_at de una vez: es el único lugar
     * donde de verdad se invoca el reporte guardado, no amerita un endpoint
     * aparte solo para el timestamp.
     *
     * @return array<string, mixed>
     */
    public function resolveParametersForInvocation(SavedReport $report): array
    {
        $definition = ReportCatalog::for($report->report_code);
        $resolved = [];

        if ($definition !== null) {
            foreach ($definition['parameters'] as $key => $spec) {
                if (! array_key_exists($key, $report->parameters)) {
                    continue;
                }

                $resolved[$key] = $spec['type'] === 'date'
                    ? $this->resolveDateValue($report->parameters[$key])
                    : $report->parameters[$key];
            }
        }

        $report->forceFill(['last_run_at' => now()])->save();

        return $resolved;
    }

    /**
     * Diferencia el payload guardado contra el esquema VIGENTE del reporte
     * base: código de reporte que ya no existe, clave guardada que ya no
     * existe, valor de enum guardado fuera de las opciones vigentes, o un
     * parámetro hoy requerido que el payload guardado no trae. Función
     * pura, segura de llamar por fila al listar.
     */
    public function isStale(SavedReport $report): bool
    {
        $definition = ReportCatalog::for($report->report_code);

        if ($definition === null) {
            return true;
        }

        $currentParameters = $definition['parameters'];

        foreach ($report->parameters as $key => $value) {
            if (! array_key_exists($key, $currentParameters)) {
                return true;
            }

            $spec = $currentParameters[$key];

            if ($spec['type'] === 'enum' && ! in_array($value, $spec['options'], true)) {
                return true;
            }
        }

        foreach ($currentParameters as $key => $spec) {
            if ($spec['required'] && ! array_key_exists($key, $report->parameters)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{type: string, value: string}  $value
     */
    private function resolveDateValue(array $value): string
    {
        return $value['type'] === 'relative'
            ? RelativeDate::resolve($value['value'])
            : $value['value'];
    }
}
