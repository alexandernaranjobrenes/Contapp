<?php

namespace App\Http\Controllers;

use App\Domains\Conti\Services\ContiAccessService;
use App\Domains\Core\Exceptions\CompanyInvitationException;
use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Core\Services\CompanyInvitationService;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\ScreenAccessService;
use App\Domains\Core\Services\UserLifecycleService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Core\Support\MediaStorage;
use App\Domains\Core\Support\ScreenCatalog;
use App\Domains\Licensing\Exceptions\LicenseQuotaExceededException;
use App\Models\User;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios dentro de una licencia (CLAUDE.md secc. 12, 14, 16).
 * Protegido por el middleware 'can-manage-users' en routes/web.php.
 *
 * Dar de alta es invitar (CompanyInvitationService): se pone el correo, el
 * rol y los permisos; la persona acepta desde el correo y, si no tenía
 * cuenta, elige ahí su nombre y su contraseña. Los permisos son por pantalla
 * del menú (ScreenCatalog).
 *
 * Conti (CLAUDE.md secc. 32): al invitar y al editar los permisos de alguien,
 * el Superusuario —y nadie más— decide si puede usar Conti, sus límites y
 * sus modelos (ContiAccessService). El cupo es de la licencia y lo comparten
 * todos: así lo reparte.
 */
class UserManagementController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service, ScreenAccessService $screens, MediaStorage $media, ContiAccessService $conti): Response
    {
        $company = Company::findOrFail($currentCompany->id());
        $grantor = $request->user();
        $grantorIsSuperAdmin = $grantor->isSuperAdmin($company->id);
        $members = $company->users()->get();

        // Lo de Conti de cada persona: solo lo ve el Superusuario.
        $contiSummaries = $conti->canConfigure($grantor, $company) ? $conti->summaries($company->license, $members) : null;

        $users = $members->map(function (User $user) use ($company, $grantor, $grantorIsSuperAdmin, $service, $screens, $media, $contiSummaries) {
            $isSuperAdmin = $user->isSuperAdmin($company->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                // La foto de perfil que cada quien sube en Mi cuenta.
                'photo_url' => $media->versionedUrl($user->photo_path, $user->photo_updated_at),
                'role_type' => $isSuperAdmin ? 'super_admin' : $user->roleTypeFor($company->id),
                'status' => $user->pivot->status,
                'can_manage' => ! $isSuperAdmin && $service->canManage($grantor, $user, $company->id),
                // Convertir Usuario ↔ Administrador: solo el Superusuario.
                'can_change_role' => ! $isSuperAdmin && $grantorIsSuperAdmin,
                'access' => $isSuperAdmin ? null : $this->accessSummary($screens->levelsFor($user, $company->id)),
                'conti' => $contiSummaries[$user->id] ?? null,
            ];
        });

        $invitations = CompanyInvitation::notAccepted()
            ->where('company_id', $company->id)
            ->with('inviter:id,name')
            ->orderByDesc('sent_at')
            ->get()
            ->map(fn (CompanyInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role_type' => $invitation->role_type,
                'status' => $invitation->status(),
                'inviter' => $invitation->inviter?->name,
                'sent_at' => $invitation->sent_at?->toIso8601String(),
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'send_count' => $invitation->send_count,
                'can_manage' => $this->canManageInvitation($grantor, $invitation, $service),
                'access' => $this->accessSummary($invitation->screen_permissions ?? []),
                'conti' => $contiSummaries !== null ? $conti->describe($invitation->conti_settings) : null,
            ]);

        return Inertia::render('Users/Index', ['users' => $users, 'invitations' => $invitations]);
    }

    public function create(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service, ContiAccessService $conti): Response
    {
        $company = Company::findOrFail($currentCompany->id());
        $grantor = $request->user();
        $license = $company->license;

        $grantableRoleTypes = $service->grantableRoleTypes($grantor, $company->id);
        if ($license) {
            $grantableRoleTypes = array_values(array_filter($grantableRoleTypes, fn ($type) => match ($type) {
                'admin' => $license->canAddAnotherAdmin(),
                'user' => $license->canAddAnotherUser(),
                default => true,
            }));
        }

        $pending = $license
            ? CompanyInvitation::pending()->whereIn('company_id', $license->companies()->pluck('id'))->get(['role_type'])->countBy('role_type')
            : collect();

        return Inertia::render('Users/Create', [
            'grantableRoleTypes' => $grantableRoleTypes,
            'quota' => $license ? [
                'admins_count' => $license->adminsCount(),
                'max_admins' => $license->max_admins,
                'users_count' => $license->usersCount(),
                'max_users' => $license->max_users,
                'pending_admins' => $pending['admin'] ?? 0,
                'pending_users' => $pending['user'] ?? 0,
            ] : null,
            'sections' => $this->screenTree($service->grantableScreenLevels($grantor, $company->id)),
            'expiresInDays' => CompanyInvitationService::EXPIRES_IN_DAYS,
            'conti' => $conti->canConfigure($grantor, $company) ? $conti->form($company) : null,
        ]);
    }

    /** Invita a una persona: le llega un correo para aceptar. */
    public function store(Request $request, CurrentCompany $currentCompany, CompanyInvitationService $invitations, ContiAccessService $conti): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $configuresConti = $conti->canConfigure($request->user(), $company);
        $validated = $this->validateInvitation($request, $configuresConti ? $company : null, $conti);

        try {
            ['invitation' => $invitation, 'mailed' => $mailed] = $invitations->invite(
                $request->user(),
                $company,
                $validated['email'],
                $validated['role_type'],
                $validated['permissions'] ?? [],
                $configuresConti && isset($validated['conti']) ? $conti->normalize($validated['conti']) : null,
            );
        } catch (CompanyInvitationException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        } catch (PrivilegeEscalationException|LicenseQuotaExceededException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with(...$this->sentMessage($invitation, $mailed));
    }

    /**
     * Antes de invitar: ¿el correo ya tiene cuenta en CONTAPP? Si la tiene, se
     * muestra su nombre, para que quien invita reconozca a la persona. Nunca
     * crea nada.
     */
    public function lookup(Request $request, CurrentCompany $currentCompany, CompanyInvitationService $invitations): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        $account = $invitations->accountFor($validated['email']);

        return response()->json([
            'exists' => $account !== null && $account->status === 'active',
            'name' => $account?->status === 'active' ? $account->name : null,
            'member' => $account !== null && $account->companies()->whereKey($currentCompany->id())->exists(),
        ]);
    }

    public function resendInvitation(Request $request, int $invitation, CurrentCompany $currentCompany, CompanyInvitationService $invitations, PermissionGrantService $service): RedirectResponse
    {
        $invitation = $this->ownInvitation($invitation, $currentCompany);
        abort_unless($this->canManageInvitation($request->user(), $invitation, $service), 403, 'No podés gestionar esta invitación.');

        try {
            $mailed = $invitations->send($invitation);
        } catch (CompanyInvitationException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with(...$this->sentMessage($invitation, $mailed));
    }

    public function destroyInvitation(Request $request, int $invitation, CurrentCompany $currentCompany, CompanyInvitationService $invitations, PermissionGrantService $service): RedirectResponse
    {
        $invitation = $this->ownInvitation($invitation, $currentCompany);
        abort_unless($this->canManageInvitation($request->user(), $invitation, $service), 403, 'No podés gestionar esta invitación.');

        try {
            $invitations->cancel($invitation);
        } catch (CompanyInvitationException $e) {
            return back()->withErrors(['invitation' => $e->getMessage()]);
        }

        return back()->with('success', "Cancelaste la invitación a {$invitation->email}: el enlace del correo ya no sirve.");
    }

    public function editPermissions(int $user, CurrentCompany $currentCompany, PermissionGrantService $service, ScreenAccessService $screens, ContiAccessService $conti): Response
    {
        $company = Company::findOrFail($currentCompany->id());
        $target = User::findOrFail($user);
        $grantor = request()->user();

        abort_unless($service->canManage($grantor, $target, $company->id), 403);

        return Inertia::render('Users/Permissions', [
            'targetUser' => $target->only(['id', 'name', 'email']),
            'sections' => $this->screenTree(
                $service->grantableScreenLevels($grantor, $company->id),
                $screens->levelsFor($target, $company->id),
            ),
            'conti' => $conti->canConfigure($grantor, $company) ? $conti->form($company, $target) : null,
        ]);
    }

    public function updatePermissions(Request $request, int $user, CurrentCompany $currentCompany, PermissionGrantService $service, ContiAccessService $conti): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $target = User::findOrFail($user);
        $configuresConti = $conti->canConfigure($request->user(), $company);

        $validated = $request->validate(
            [...$this->permissionRules(), ...($configuresConti ? $conti->rules($company->license, $request) : [])],
            $conti->messages($company->license),
        );

        try {
            $service->updateScreenPermissions($request->user(), $target, $company->id, $validated['permissions'] ?? []);
        } catch (PrivilegeEscalationException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        if ($configuresConti && isset($validated['conti'])) {
            $conti->save($request->user(), $company, $target, $conti->normalize($validated['conti']));
        }

        return redirect()->route('users.index')->with('success', "Permisos de {$target->name} actualizados.");
    }

    public function suspend(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        return $this->changeStatus($user, $currentCompany, fn (User $target) => $service->suspend(request()->user(), $target, $currentCompany->id()), 'fue suspendido');
    }

    public function reactivate(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        return $this->changeStatus($user, $currentCompany, fn (User $target) => $service->reactivate(request()->user(), $target, $currentCompany->id()), 'fue reactivado');
    }

    public function deactivate(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        return $this->changeStatus($user, $currentCompany, fn (User $target) => $service->deactivate(request()->user(), $target, $currentCompany->id()), 'fue desactivado');
    }

    /** El Superusuario convierte a un Usuario en Administrador, o al revés. */
    public function changeRole(Request $request, int $user, CurrentCompany $currentCompany, PermissionGrantService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $target = User::findOrFail($user);
        $roleType = $request->validate(['role_type' => ['required', 'string', 'in:admin,user']])['role_type'];

        try {
            $service->changeRole($request->user(), $target, $company, $roleType);
        } catch (PrivilegeEscalationException|LicenseQuotaExceededException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$target->name} ahora es ".($roleType === 'admin' ? 'Administrador' : 'Usuario').' en esta compañía.');
    }

    /**
     * Suspender, reactivar o desactivar, y avisarle a la persona por correo
     * (UserLifecycleService). El mensaje dice si el correo salió.
     */
    private function changeStatus(int $user, CurrentCompany $currentCompany, Closure $apply, string $done): RedirectResponse
    {
        $target = User::findOrFail($user);

        try {
            $mailed = $apply($target);
        } catch (PrivilegeEscalationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $mailed
            ? "{$target->name} {$done} y le avisamos por correo a {$target->email}."
            : "{$target->name} {$done}, pero no se pudo enviar el correo a {$target->email}.");
    }

    /**
     * Con compañía, también lo de Conti (lo manda solo el Superusuario).
     *
     * @return array<string, mixed>
     */
    private function validateInvitation(Request $request, ?Company $contiCompany, ContiAccessService $conti): array
    {
        return $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role_type' => ['required', 'string', 'in:admin,user'],
            ...$this->permissionRules(),
            ...($contiCompany ? $conti->rules($contiCompany->license, $request) : []),
        ], $conti->messages($contiCompany?->license), ['email' => 'correo', 'role_type' => 'rol']);
    }

    /** Los permisos llegan como clave de pantalla => nivel. */
    private function permissionRules(): array
    {
        return [
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(['none', 'read', 'read_write'])],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function sentMessage(CompanyInvitation $invitation, bool $mailed): array
    {
        return $mailed
            ? ['success', "Le enviamos la invitación a {$invitation->email}. Va a poder entrar cuando la acepte desde el correo."]
            : ['error', "La invitación a {$invitation->email} quedó creada, pero no se pudo enviar el correo. Probá reenviarla desde su ficha en un momento."];
    }

    private function ownInvitation(int $id, CurrentCompany $currentCompany): CompanyInvitation
    {
        return CompanyInvitation::notAccepted()->where('company_id', $currentCompany->id())->findOrFail($id);
    }

    /** El Superusuario, todas; un Administrador, las de rol Usuario (como con las personas). */
    private function canManageInvitation(User $grantor, CompanyInvitation $invitation, PermissionGrantService $service): bool
    {
        return in_array($invitation->role_type, $service->grantableRoleTypes($grantor, $invitation->company_id), true);
    }

    /**
     * Las secciones del menú con sus pantallas, para el editor de permisos:
     * hasta dónde puede dar quien edita y lo que la persona tiene hoy.
     *
     * @param  array<string, string>  $maxLevels
     * @param  array<string, string>  $current
     */
    private function screenTree(array $maxLevels, array $current = []): array
    {
        return collect(ScreenCatalog::sections())->map(fn (array $section) => [
            'key' => $section['key'],
            'label' => $section['label'],
            'screens' => collect($section['screens'])->map(fn (array $screen) => [
                'key' => $screen['key'],
                'label' => $screen['label'],
                'group' => $screen['group'],
                'read_only' => $screen['read_only'],
                'max_level' => ScreenAccessService::min($maxLevels[$screen['key']] ?? 'none', ScreenCatalog::maxLevel($screen['key'])),
                'current_level' => $current[$screen['key']] ?? 'none',
            ])->values(),
        ])->values()->all();
    }

    /**
     * Para la ficha: por sección, qué pantallas tiene y con qué nivel.
     *
     * @param  array<string, string>  $levels
     * @return list<array{label: string, screens: list<array{label: string, level: string}>}>
     */
    private function accessSummary(array $levels): array
    {
        return collect(ScreenCatalog::sections())
            ->map(fn (array $section) => [
                'label' => $section['label'],
                'screens' => collect($section['screens'])
                    ->filter(fn (array $screen) => ($levels[$screen['key']] ?? 'none') !== 'none')
                    ->map(fn (array $screen) => ['label' => $screen['label'], 'level' => $levels[$screen['key']]])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $section) => $section['screens'] !== [])
            ->values()
            ->all();
    }
}
