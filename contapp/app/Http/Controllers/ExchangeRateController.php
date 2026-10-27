<?php

namespace App\Http\Controllers;

use App\Domains\Accounting\Models\ExchangeRate;
use App\Domains\Banking\Exceptions\BccrUnavailableException;
use App\Domains\Banking\Services\SyncBccrExchangeRatesService;
use App\Domains\Banking\Support\BccrSyncStatus;
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
            'bccrConfigured' => filled(config('services.bccr.token')),
            // La última sincronización automática (pivox:sync-bccr-rates).
            'bccrLastSync' => BccrSyncStatus::last(),
            // Hoy en la compañía, no en UTC: de noche en Costa Rica, en UTC ya es mañana.
            'today' => now($company->timezone ?: config('services.bccr.timezone'))->format('Y-m-d'),
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
        $date = isset($validated['date']) ? Carbon::parse($validated['date']) : Carbon::today($company->timezone ?: config('services.bccr.timezone'));

        // El BCCR publica el del dólar (colones por dólar), nada más.
        $currency = $company->foreignCurrency()->value('code');

        if ($currency !== 'USD') {
            return back()->withErrors([
                'sync' => $currency === null
                    ? 'La compañía no tiene configurada una moneda extranjera.'
                    : "El BCCR publica el tipo de cambio del dólar; el de {$currency} se carga a mano con «Crear nuevo».",
            ]);
        }

        try {
            $result = $service->syncForCompany($company, $date);
        } catch (BccrUnavailableException $e) {
            return back()->withErrors(['sync' => $e->userMessage().' Mientras tanto, se puede cargar a mano con «Crear nuevo».']);
        }

        if ($result === null) {
            return back()->withErrors([
                'sync' => "El BCCR todavía no tiene el tipo de cambio del {$date->format('d/m/Y')}: lo publica la tarde del día anterior. Para una fecha más adelante, cargalo a mano con «Crear nuevo».",
            ]);
        }

        // Una tasa bloqueada es una corrección a mano: el BCCR no la pisa.
        if ($result->is_locked) {
            return back()->with('success', "El tipo de cambio del {$date->format('d/m/Y')} está bloqueado: se dejó el que tenía ({$result->rate}).");
        }

        return back()->with('success', "Tipo de cambio del BCCR del {$date->format('d/m/Y')}: {$result->rate}.");
    }

    public function destroy(int $exchangeRate): RedirectResponse
    {
        $rate = ExchangeRate::findOrFail($exchangeRate);
        $rate->delete();

        return back()->with('success', 'Tipo de cambio eliminado.');
    }
}
