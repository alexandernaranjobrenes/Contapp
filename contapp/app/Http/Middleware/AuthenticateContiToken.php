<?php

namespace App\Http\Middleware;

use App\Domains\Conti\Services\ContiTokenService;
use App\Domains\Conti\Support\ContiContext;
use App\Domains\Core\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * La puerta de la API de Conti (routes/api.php): el agente de n8n llega con
 * el pase que CONTAPP le dio en la metadata del mensaje
 * (Authorization: Bearer conti_…).
 *
 * En cada petición vuelve a revisar lo mismo que SetCurrentCompany revisa en
 * la sesión web: que la cuenta siga activa, que siga siendo miembro activo
 * de esa compañía y que la licencia no esté suspendida ni revocada. Una
 * licencia vencida deja consultar, en modo de gracia, pero no preparar nada
 * para guardar (ContiActionController).
 *
 * Después deja fijadas la persona (Auth) y la compañía activa
 * (CurrentCompany), así el CompanyScope de los modelos filtra igual que en la
 * aplicación.
 */
class AuthenticateContiToken
{
    public function __construct(private readonly ContiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolve($request->bearerToken());

        if ($token === null) {
            return $this->deny(401, 'El acceso de Conti venció o no es válido. Pedile a la persona que vuelva a escribir su mensaje desde CONTAPP.');
        }

        $user = $token->user;

        if ($user === null || ($user->status ?? 'active') !== 'active') {
            return $this->deny(401, 'Esa cuenta ya no tiene acceso a CONTAPP.');
        }

        $company = $user->companies()
            ->wherePivot('status', 'active')
            ->with('license')
            ->where('companies.id', $token->company_id)
            ->first();

        if ($company === null) {
            return $this->deny(403, 'La persona ya no tiene acceso activo a esa compañía.');
        }

        if ($company->license_id && $company->license?->isBlocked()) {
            return $this->deny(403, $company->license->blockedMessage($company->trade_name ?: $company->legal_name));
        }

        $grace = (bool) $company->license?->isExpiredButActive();

        Auth::guard('web')->setUser($user);
        $request->setUserResolver(fn () => $user);

        app(CurrentCompany::class)->setGraceMode($grace);
        app(CurrentCompany::class)->set($company->id);
        app(ContiContext::class)->set($user, $company, $grace);

        return $next($request);
    }

    private function deny(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
