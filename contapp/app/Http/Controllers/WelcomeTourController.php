<?php

namespace App\Http\Controllers;

use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * El recorrido de bienvenida de una licencia recién activada (CLAUDE.md
 * secc. 12, WelcomeTourHost.vue): lo ve su dueño la primera vez que entra, y
 * termina pidiéndole los datos reales de la compañía, que nació con un nombre
 * predefinido (LicenseActivationService::DEFAULT_COMPANY_NAME).
 *
 * Terminarlo o saltarlo lo apaga para siempre. Una cuenta es dueña de una
 * sola licencia, así que no hace falta saber en qué compañía está parada.
 */
class WelcomeTourController extends Controller
{
    public function complete(Request $request): RedirectResponse
    {
        $user = $request->user();

        License::where('superuser_id', $user->id)->where('welcome_pending', true)->update(['welcome_pending' => false]);

        // «Cambiar los datos»: a Mi cuenta, con la ficha de la compañía
        // abierta. Solo si es su Superusuario, que es quien los cambia.
        $companyId = app(CurrentCompany::class)->id();

        if ($request->boolean('edit_company') && $companyId !== null && $user->isSuperAdmin($companyId)) {
            return redirect()->route('profile.edit', ['compania' => $companyId]);
        }

        return back();
    }
}
