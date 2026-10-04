<?php

namespace App\Http\Controllers;

use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mi cuenta → Tus compañías: los datos de una compañía —razón social, nombre
 * comercial y cédula jurídica—, que se cargaron al crearla.
 *
 * Solo el Superusuario de esa compañía. No son datos de la persona sino la
 * identidad legal de la compañía: salen en el encabezado de los reportes y
 * en los comprobantes electrónicos que emite (SalesDocumentXmlBuilder usa la
 * razón social y la cédula como datos del emisor). Un Administrador o un
 * Usuario los ve, pero no los cambia. La regla se comprueba acá, no solo
 * escondiendo el botón (CLAUDE.md secc. 18).
 *
 * La compañía viene en la ruta y no tiene por qué ser la activa: desde Mi
 * cuenta se editan todas las propias. Por eso el modo de gracia se revisa
 * contra la licencia de ESA compañía, y no lo hace el middleware global, que
 * mira la activa (ver EnforceLicenseGracePeriod).
 */
class CompanyDetailsController extends Controller
{
    private const FIELDS = ['legal_name', 'trade_name', 'tax_id'];

    public function update(Request $request, int $company): RedirectResponse
    {
        $company = Company::with('license')->findOrFail($company);
        $user = $request->user();

        $isMember = $user->companies()->wherePivot('status', 'active')->whereKey($company->id)->exists();

        abort_unless($isMember && $user->isSuperAdmin($company->id), 403, 'Solo el Superusuario de la compañía puede cambiar sus datos.');

        if ($company->license?->isBlocked()) {
            abort(403, 'La licencia de esta compañía está suspendida o revocada.');
        }

        if ($company->license?->isExpiredButActive()) {
            abort(403, 'La licencia de esta compañía está vencida. Podés consultar y exportar información, pero no modificar sus datos hasta renovarla.');
        }

        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_id' => ['nullable', 'string', 'max:50'],
        ], [], [
            'legal_name' => 'razón social',
            'trade_name' => 'nombre comercial',
            'tax_id' => 'cédula jurídica',
        ]);

        $legalName = trim($validated['legal_name']);
        $tradeName = trim((string) ($validated['trade_name'] ?? ''));
        $taxId = trim((string) ($validated['tax_id'] ?? ''));

        $data = [
            'legal_name' => $legalName,
            // Igual que al crearla: sin nombre comercial, se usa la razón social.
            'trade_name' => $tradeName !== '' ? $tradeName : $legalName,
            'tax_id' => $taxId !== '' ? $taxId : null,
        ];

        $before = $company->only(self::FIELDS);
        $changed = array_keys(array_filter($data, fn ($value, $field) => $before[$field] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed !== []) {
            DB::transaction(function () use ($company, $data, $before, $changed, $request) {
                $company->update($data);

                AuditLog::create([
                    'company_id' => $company->id,
                    'user_id' => $request->user()->id,
                    'action' => 'company.details_updated',
                    'auditable_type' => Company::class,
                    'auditable_id' => $company->id,
                    'old_values' => array_intersect_key($before, array_flip($changed)),
                    'new_values' => array_intersect_key($data, array_flip($changed)),
                    'ip_address' => $request->ip(),
                    'created_at' => now(),
                ]);
            });
        }

        return back()->with('success', "Los datos de {$data['trade_name']} quedaron guardados.");
    }
}
