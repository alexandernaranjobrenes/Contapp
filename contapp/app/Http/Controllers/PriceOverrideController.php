<?php

namespace App\Http\Controllers;

use App\Domains\Billing\Models\PriceOverrideAuthorization;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los cambios de precio autorizados. Es el punto del control: bloquear solo
 * sirve si después alguien puede preguntar cuántos hubo, quién los pide y
 * quién los firma — un descuento aislado es una decisión comercial, y el
 * mismo descuento cien veces al mes es una lista de precios mal puesta.
 */
class PriceOverrideController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany): Response
    {
        $companyId = $currentCompany->id();

        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'authorized_by' => ['nullable', 'integer'],
            'requested_by' => ['nullable', 'integer'],
        ]);

        // Por defecto el mes corrido: es la pregunta que se hace al revisar,
        // y sin acotar la consulta crece sin techo.
        $from = $filters['from'] ?? now()->startOfMonth()->format('Y-m-d');
        $to = $filters['to'] ?? now()->format('Y-m-d');

        $query = PriceOverrideAuthorization::with([
            'salesDocument:id,consecutive,document_date,business_partner_id',
            'salesOrder:id,number,business_partner_id',
            'salesOrder.businessPartner:id,code,name',
            'salesDocument.businessPartner:id,code,name',
            'item:id,code,name',
            'requestedBy:id,name',
            'authorizedBy:id,name',
        ])
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->when(isset($filters['authorized_by']), fn ($q) => $q->where('authorized_by', $filters['authorized_by']))
            ->when(isset($filters['requested_by']), fn ($q) => $q->where('requested_by', $filters['requested_by']));

        $rows = (clone $query)->orderByDesc('created_at')->paginate(50)->withQueryString();

        return Inertia::render('Billing/PriceOverrides/Index', [
            'overrides' => $rows,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'authorized_by' => $filters['authorized_by'] ?? null,
                'requested_by' => $filters['requested_by'] ?? null,
            ],
            // El resumen es lo que convierte la lista en información: cuánto
            // se dejó de cobrar en el período.
            'summary' => [
                'count' => (clone $query)->count(),
                'total_difference' => number_format(
                    (float) (clone $query)->sum('difference'), 2, '.', ''
                ),
            ],
            'users' => User::whereIn('id', PriceOverrideAuthorization::query()
                ->select('authorized_by')
                ->union(PriceOverrideAuthorization::query()->select('requested_by'))
                ->pluck('authorized_by')
                ->filter())
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
