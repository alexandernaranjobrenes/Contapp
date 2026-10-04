<?php

namespace App\Http\Controllers;

use App\Domains\Licensing\Exceptions\InvitationException;
use App\Domains\Licensing\Models\License;
use App\Domains\Licensing\Models\LicenseInvitation;
use App\Domains\Licensing\Services\LicenseActivationService;
use App\Domains\Licensing\Services\LicenseInvitationService;
use App\Http\Controllers\Concerns\RecordsPropietarioAudit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Backoffice → Licencias: asignar una licencia a una persona, reenviarle el
 * correo, cambiar a quién está asignada o quitar la asignación
 * (LicenseInvitationService). Guard 'propietario'; cada acción queda en la
 * bitácora de la licencia.
 *
 * Asignar al emitir la licencia lo hace LicenseController::store con estas
 * mismas piezas (assignFromRequest y sendInvitation).
 */
class LicenseInvitationController extends Controller
{
    use RecordsPropietarioAudit;

    /**
     * Lo que el backoffice necesita saber de un correo antes de asignar: si
     * tiene cuenta, si ya es dueña de una licencia, y si su contraseña la
     * eligió ella (si no, al aceptar tendrá que elegir una nueva).
     */
    public function lookup(Request $request, LicenseActivationService $activation): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', trim($validated['email']))->first();
        $owned = $user ? $activation->ownedLicense($user) : null;
        $pending = LicenseInvitation::with('license')
            ->where('email', trim($validated['email']))
            ->whereNull('accepted_at')
            ->first();

        return response()->json([
            'exists' => $user !== null,
            'name' => $user?->name,
            'active' => $user?->status === 'active',
            'owned_license' => $owned?->maskedCode(),
            'password_chosen' => $user?->password_chosen_at !== null,
            'pending_license' => $pending?->license?->maskedCode(),
            'pending_license_id' => $pending?->license_id,
        ])->header('Cache-Control', 'no-store');
    }

    /** Asignar una licencia ya emitida, o cambiar a quién está asignada. */
    public function store(Request $request, int $license, LicenseInvitationService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $validated = $this->validateAssignment($request, required: true);

        try {
            $invitation = DB::transaction(fn () => $this->assignFromRequest($request, $license, $validated, $service));
        } catch (InvitationException $e) {
            return back()->withErrors([$e->field === 'invitation' ? 'invitation' : "assign_{$e->field}" => $e->getMessage()]);
        }

        return $this->sendInvitation($request, $invitation, $service, 'license_invitation_sent');
    }

    public function resend(Request $request, int $license, LicenseInvitationService $service): RedirectResponse
    {
        $invitation = License::findOrFail($license)->invitation;
        abort_if($invitation === null, 404);

        return $this->sendInvitation($request, $invitation, $service, 'license_invitation_resent');
    }

    public function destroy(Request $request, int $license, LicenseInvitationService $service): RedirectResponse
    {
        $license = License::findOrFail($license);
        $invitation = $license->invitation;
        abort_if($invitation === null, 404);

        try {
            DB::transaction(function () use ($request, $license, $invitation, $service) {
                $service->cancel($invitation);

                $this->auditPropietario($request, 'license_invitation_cancelled', $license, $this->invitationSnapshot($invitation), null);
            });
        } catch (InvitationException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('success', "Quitamos la asignación de {$license->maskedCode()}: el enlace que se había enviado ya no sirve, y la licencia vuelve a activarse con su código.");
    }

    /**
     * Las reglas de los campos de la asignación. Con $required en falso,
     * asignar es opcional (al emitir una licencia, «Sin asignar»).
     *
     * @return array{assign_to?: string, assign_email?: ?string, assign_name?: ?string}
     */
    public function validateAssignment(Request $request, bool $required): array
    {
        $types = [LicenseInvitation::EXISTING, LicenseInvitation::NEW];

        return $request->validate([
            'assign_to' => [$required ? 'required' : 'nullable', Rule::in($required ? $types : ['none', ...$types])],
            'assign_email' => ['nullable', 'required_if:assign_to,existing,new', 'email', 'max:255'],
            'assign_name' => ['nullable', 'required_if:assign_to,new', 'string', 'max:255'],
        ], [
            'assign_email.required_if' => 'Escribí el correo de la persona.',
            'assign_name.required_if' => 'Escribí el nombre de la persona.',
        ], [
            'assign_email' => 'correo',
            'assign_name' => 'nombre',
        ]);
    }

    /**
     * La asignación y su rastro en la bitácora. Va dentro de la transacción
     * de quien llama: si algo falla, no queda ni la asignación ni la entrada.
     *
     * @param  array{assign_to?: string, assign_email?: ?string, assign_name?: ?string}  $validated
     *
     * @throws InvitationException
     */
    public function assignFromRequest(Request $request, License $license, array $validated, LicenseInvitationService $service): LicenseInvitation
    {
        $previous = $license->invitation;
        $before = $previous ? $this->invitationSnapshot($previous) : null;

        $invitation = $service->assign(
            $license,
            $validated['assign_to'],
            $validated['assign_email'],
            $validated['assign_name'] ?? null,
            $request->user('propietario')->id,
        );

        $after = $this->invitationSnapshot($invitation);

        if ($before === null) {
            $this->auditPropietario($request, 'license_invitation_assigned', $license, null, $after);
        } elseif ($before != $after) {
            [$old, $new] = $this->changedValues($before, $after);
            $this->auditPropietario($request, 'license_invitation_updated', $license, $old, $new);
        }

        return $invitation;
    }

    /**
     * Manda el correo y lo deja en la bitácora. El correo va fuera de una
     * transacción: si el servidor de correo falla, la asignación queda hecha
     * y el backoffice puede reenviar.
     */
    public function sendInvitation(Request $request, LicenseInvitation $invitation, LicenseInvitationService $service, string $action, ?string $issuedMessage = null): RedirectResponse
    {
        $license = $invitation->license;

        try {
            $service->send($invitation);
        } catch (InvitationException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', ($issuedMessage ? "{$issuedMessage} " : '')."No se pudo enviar el correo a {$invitation->email}. Probá «Reenviar correo» en un momento.");
        }

        $this->auditPropietario($request, $action, $license, null, ['email' => $invitation->email]);

        $minutes = LicenseInvitationService::EXPIRES_IN_MINUTES;

        return back()->with('success', ($issuedMessage ? "{$issuedMessage} " : '')
            ."Le enviamos a {$invitation->email} el correo para aceptar la licencia {$license->maskedCode()}. El enlace vence en {$minutes} minutos.");
    }

    /** @return array{account_type: string, email: string, name: ?string} */
    private function invitationSnapshot(LicenseInvitation $invitation): array
    {
        return [
            'account_type' => $invitation->account_type,
            'email' => $invitation->email,
            'name' => $invitation->personName(),
        ];
    }
}
