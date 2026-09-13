<?php

namespace App\Http\Controllers;

use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Reporting\Models\SavedReport;
use App\Domains\Reporting\Services\SavedReportService;
use App\Domains\Reporting\Support\RelativeDate;
use App\Domains\Reporting\Support\ReportCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guardar/reinvocar una combinación de parámetros de uno de los 6 reportes
 * existentes (ver App\Domains\Reporting\Support\ReportCatalog) — nunca
 * ejecuta ningún reporte en sí, solo redirige a su ruta index() real con
 * los parámetros ya resueltos.
 */
class SavedReportController extends Controller
{
    public function index(Request $request, SavedReportService $service): Response
    {
        $reports = SavedReport::visibleTo($request->user())
            ->with('user:id,name')
            ->latest()
            ->get()
            ->map(function (SavedReport $report) use ($service, $request) {
                $definition = ReportCatalog::for($report->report_code);

                return [
                    'id' => $report->id,
                    'name' => $report->name,
                    'report_code' => $report->report_code,
                    'report_label' => $definition['label'] ?? $report->report_code,
                    'is_shared' => $report->is_shared,
                    'is_mine' => $report->user_id === $request->user()->id,
                    'is_stale' => $service->isStale($report),
                    'created_by' => $report->user?->name,
                    'last_run_at' => $report->last_run_at?->format('Y-m-d H:i'),
                ];
            });

        return Inertia::render('Reports/Saved/Index', ['savedReports' => $reports]);
    }

    public function store(Request $request, SavedReportService $service): RedirectResponse
    {
        $validated = $this->validatePayload($request);

        $service->create(
            $request->user(),
            $validated['report_code'],
            $validated['name'],
            $validated['parameters'],
            $validated['is_shared'],
        );

        return back()->with('success', "Reporte guardado \"{$validated['name']}\".");
    }

    public function update(Request $request, SavedReport $savedReport, SavedReportService $service): RedirectResponse
    {
        abort_unless($savedReport->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'is_shared' => ['boolean'],
        ]);

        $service->update($savedReport, $validated['name'], $savedReport->parameters, (bool) ($validated['is_shared'] ?? false));

        return back()->with('success', "Reporte \"{$validated['name']}\" actualizado.");
    }

    public function destroy(Request $request, SavedReport $savedReport, SavedReportService $service): RedirectResponse
    {
        $user = $request->user();
        $companyId = app(CurrentCompany::class)->id();

        $canDelete = $savedReport->user_id === $user->id
            || $user->isSuperAdmin()
            || ($companyId && app(PermissionGrantService::class)->hasAtLeast($user, $companyId, 'reports', 'read_write'));

        abort_unless($canDelete, 403);

        $service->delete($savedReport);

        return back()->with('success', 'Reporte guardado eliminado.');
    }

    public function invoke(SavedReport $savedReport, SavedReportService $service): RedirectResponse
    {
        $definition = ReportCatalog::for($savedReport->report_code);

        abort_if($definition === null, 404);

        $resolved = $service->resolveParametersForInvocation($savedReport);

        return redirect()->route($definition['route_index'], $resolved);
    }

    /**
     * @return array{report_code: string, name: string, parameters: array<string, mixed>, is_shared: bool}
     */
    private function validatePayload(Request $request): array
    {
        $validated = $request->validate([
            'report_code' => ['required', 'string', Rule::in(ReportCatalog::codes())],
            'name' => ['required', 'string', 'max:150'],
            'is_shared' => ['boolean'],
            'parameters' => ['required', 'array'],
        ]);

        $definition = ReportCatalog::for($validated['report_code']);
        $parameters = $validated['parameters'];

        foreach ($definition['parameters'] as $key => $spec) {
            if ($spec['required'] && ! array_key_exists($key, $parameters)) {
                throw ValidationException::withMessages([
                    "parameters.{$key}" => "El reporte \"{$definition['label']}\" requiere el parámetro \"{$key}\".",
                ]);
            }

            if (! array_key_exists($key, $parameters)) {
                continue;
            }

            $value = $parameters[$key];

            match ($spec['type']) {
                'date' => $this->assertValidDateValue($key, $value),
                'boolean' => null,
                'enum' => $this->assertValidEnumValue($key, $value, $spec['options']),
                default => null,
            };
        }

        return [
            'report_code' => $validated['report_code'],
            'name' => $validated['name'],
            'parameters' => $parameters,
            'is_shared' => (bool) ($validated['is_shared'] ?? false),
        ];
    }

    private function assertValidDateValue(string $key, mixed $value): void
    {
        if (! is_array($value) || ! isset($value['type'], $value['value'])) {
            throw ValidationException::withMessages([
                "parameters.{$key}" => "El parámetro \"{$key}\" debe tener la forma {type, value}.",
            ]);
        }

        if ($value['type'] === 'relative') {
            if (! in_array($value['value'], RelativeDate::OPTIONS, true)) {
                throw ValidationException::withMessages([
                    "parameters.{$key}" => "\"{$value['value']}\" no es una fecha relativa válida.",
                ]);
            }

            return;
        }

        if ($value['type'] !== 'fixed' || ! strtotime($value['value'])) {
            throw ValidationException::withMessages([
                "parameters.{$key}" => "El parámetro \"{$key}\" tiene una fecha inválida.",
            ]);
        }
    }

    /**
     * @param  array<int, string>  $options
     */
    private function assertValidEnumValue(string $key, mixed $value, array $options): void
    {
        if (! in_array($value, $options, true)) {
            throw ValidationException::withMessages([
                "parameters.{$key}" => "\"{$value}\" no es un valor válido para \"{$key}\".",
            ]);
        }
    }
}
