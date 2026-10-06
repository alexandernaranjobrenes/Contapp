<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Services\ContiAccessService;
use App\Domains\Conti\Services\ContiModelService;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Support\CurrentCompany;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * «Modelo y consumo», en el chat de Conti (ContiSettings.vue, CLAUDE.md
 * secc. 32): con qué modelo de OpenAI le responde Conti a la persona, y
 * cuánto gastó ella hoy, esta semana y este mes, con los límites que le
 * aplican.
 *
 * Es de la persona, no de la compañía: se puede ver y cambiar también con la
 * licencia vencida, y al llegar a un límite (para entender por qué Conti ya
 * no responde). Los modelos son los que el Superusuario le permite
 * (ContiAccessService).
 */
class ContiSettingsController extends Controller
{
    public function show(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiModelService $models, ContiAccessService $access): JsonResponse
    {
        $user = $request->user();
        $company = $this->company($currentCompany, $usage, $user);
        $allowed = $access->allowedModels($user, $company);

        return response()->json([
            'modelo' => $models->forUser($user, $allowed),
            'modelos' => $models->available($allowed),
            // El Superusuario le eligió los modelos: el chat lo aclara.
            'modelos_limitados' => $allowed !== null,
            ...$usage->personal($user, $company),
        ])->header('Cache-Control', 'no-store');
    }

    public function updateModel(Request $request, CurrentCompany $currentCompany, ContiUsageService $usage, ContiModelService $models, ContiAccessService $access): JsonResponse
    {
        $user = $request->user();
        $company = $this->company($currentCompany, $usage, $user);

        $validated = $request->validate([
            'modelo' => ['required', 'string', Rule::in(array_column($models->available($access->allowedModels($user, $company)), 'id'))],
        ], [
            'modelo.in' => 'Ese modelo no está disponible. Elegí uno de la lista.',
        ]);

        // El predeterminado no se guarda: si la instalación cambia de
        // modelo predeterminado, quien no eligió otro pasa al nuevo.
        $user->forceFill([
            'conti_model' => $validated['modelo'] === $models->default() ? null : $validated['modelo'],
        ])->save();

        return response()->json(['modelo' => $validated['modelo'], 'nombre' => $models->name($validated['modelo'])]);
    }

    /** La compañía activa, si en ella hay Conti para esta persona. */
    private function company(CurrentCompany $currentCompany, ContiUsageService $usage, User $user): Company
    {
        $companyId = $currentCompany->id() ?? abort(409, 'Elegí una compañía para usar Conti.');
        $company = Company::with('license')->findOrFail($companyId);

        abort_unless($usage->enabledFor($company), 403, 'Conti no está activado en la licencia de esta compañía. Si querés usarlo, contactá al equipo de CONTAPP.');
        abort_unless($usage->enabledFor($company, $user), 403, 'No tenés acceso a Conti en esta licencia. Si lo necesitás, pedíselo al Superusuario.');

        return $company;
    }
}
