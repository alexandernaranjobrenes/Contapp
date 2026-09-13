<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Services\SyncBccrExchangeRatesService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ExchangeRateController extends Controller
{
    public function index(CurrentCompany $currentCompany): Response
    {
        $company = Company::findOrFail($currentCompany->id());

        $rates = ExchangeRate::with('createdBy:id,name')
            ->where('currency_id', $company->foreign_currency_id)
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->limit(90)
            ->get(['id', 'rate_date', 'rate_type', 'rate', 'source', 'is_locked', 'created_by']);

        return Inertia::render('ExchangeRates/Index', [
            'rates' => $rates,
            'foreignCurrency' => $company->foreignCurrency()->first(['id', 'code', 'symbol']),
            'bccrConfigured' => filled(config('services.bccr.email')) && filled(config('services.bccr.token')),
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        if (! $company->foreign_currency_id) {
            return back()->withErrors(['rate' => 'La compañía no tiene configurada una moneda extranjera.']);
        }

        $validated = $request->validate([
            'rate_date' => ['required', 'date'],
            'rate_type' => ['required', 'in:buy,sell,reference'],
            'rate' => ['required', 'numeric', 'gt:0'],
            'is_locked' => ['boolean'],
        ]);

        // No se usa updateOrCreate: el cast 'date' de rate_date normaliza el
        // valor guardado, así que comparar por igualdad contra el string
        // plano 'Y-m-d' del form nunca calza con lo ya almacenado (mismo
        // hallazgo que docs/decisiones.md 2026-08-05). whereDate() sí
        // compara solo la parte de fecha.
        $existing = ExchangeRate::where('company_id', $company->id)
            ->where('currency_id', $company->foreign_currency_id)
            ->where('rate_type', $validated['rate_type'])
            ->whereDate('rate_date', $validated['rate_date'])
            ->first();

        $attributes = [
            'rate' => $validated['rate'],
            'source' => 'manual',
            'is_locked' => $validated['is_locked'] ?? false,
            'created_by' => Auth::id(),
        ];

        // Edición manual explícita: a diferencia del sync automático del
        // BCCR, esta SÍ puede pisar una fila que ya estaba bloqueada,
        // porque acá es el usuario quien decide el valor a propósito.
        if ($existing) {
            $existing->update($attributes);
        } else {
            ExchangeRate::create([
                ...$attributes,
                'company_id' => $company->id,
                'currency_id' => $company->foreign_currency_id,
                'rate_date' => $validated['rate_date'],
                'rate_type' => $validated['rate_type'],
            ]);
        }

        return back()->with('success', "Tipo de cambio del {$validated['rate_date']} guardado.");
    }

    public function sync(Request $request, CurrentCompany $currentCompany, SyncBccrExchangeRatesService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        $validated = $request->validate(['date' => ['nullable', 'date']]);
        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::today();

        $result = $service->syncForCompany($company, $date);

        if ($result === null) {
            return back()->withErrors([
                'sync' => 'El BCCR no devolvió un tipo de cambio para esa fecha. Puede ser fin de semana/feriado, que BCCR_EMAIL/BCCR_TOKEN no estén configurados, o que el servicio no esté disponible ahora mismo. Podés cargarlo a mano mientras tanto.',
            ]);
        }

        return back()->with('success', "Tipo de cambio BCCR del {$date->format('Y-m-d')}: {$result->rate}.");
    }

    public function destroy(int $exchangeRate): RedirectResponse
    {
        $rate = ExchangeRate::findOrFail($exchangeRate);
        $rate->delete();

        return back()->with('success', 'Tipo de cambio eliminado.');
    }
}
