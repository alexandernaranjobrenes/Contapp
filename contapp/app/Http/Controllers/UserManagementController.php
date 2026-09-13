<?php

namespace App\Http\Controllers;

use App\Domains\Core\Exceptions\PrivilegeEscalationException;
use App\Domains\Core\Exceptions\UserNotFoundException;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\Module;
use App\Domains\Core\Models\ModulePermission;
use App\Domains\Core\Services\PermissionGrantService;
use App\Domains\Core\Services\UserLifecycleService;
use App\Domains\Core\Support\CurrentCompany;
use App\Domains\Licensing\Exceptions\LicenseQuotaExceededException;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestión de usuarios dentro de una licencia (CLAUDE.md secc. 12, 14, 16).
 * Protegido por el middleware 'can-manage-users' en routes/web.php.
 */
class UserManagementController extends Controller
{
    public function index(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service): Response
    {
        $company = Company::findOrFail($currentCompany->id());
        $modules = Module::orderBy('name')->get();
        $grantor = $request->user();

        $users = $company->users()->get()->map(function (User $user) use ($company, $modules, $grantor, $service) {
            $permissions = ModulePermission::where('company_id', $company->id)
                ->where('subject_type', 'user')
                ->where('subject_id', $user->id)
                ->pluck('access_level', 'module_id');

            $isSuperAdmin = $user->isSuperAdmin($company->id);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_type' => $isSuperAdmin ? 'super_admin' : $user->roleTypeFor($company->id),
                'status' => $user->pivot->status,
                'can_manage' => ! $isSuperAdmin && $service->canManage($grantor, $user, $company->id),
                'permissions' => $modules->map(fn (Module $module) => [
                    'module' => $module->only(['id', 'code', 'name']),
                    'access_level' => $isSuperAdmin ? 'read_write' : ($permissions[$module->id] ?? 'none'),
                ])->values(),
            ];
        });

        return Inertia::render('Users/Index', ['users' => $users]);
    }

    public function create(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service): Response
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

        return Inertia::render('Users/Create', [
            'grantableRoleTypes' => $grantableRoleTypes,
            'license' => $license ? [
                'admins_count' => $license->adminsCount(),
                'max_admins' => $license->max_admins,
                'users_count' => $license->usersCount(),
                'max_users' => $license->max_users,
            ] : null,
            'modules' => Module::orderBy('name')->get()->map(fn (Module $module) => [
                'id' => $module->id,
                'code' => $module->code,
                'name' => $module->name,
                'max_access_level' => $service->effectiveAccessLevel($grantor, $company->id, $module),
            ]),
        ]);
    }

    public function store(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role_type' => ['required', 'string', 'in:admin,user'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:none,read,read_write'],
        ]);

        $modulePermissions = collect($validated['permissions'] ?? [])
            ->filter(fn ($level) => $level !== 'none')
            ->all();

        try {
            $service->createUser(
                $request->user(),
                $company,
                ['name' => $validated['name'], 'email' => $validated['email'], 'password' => $validated['password']],
                $validated['role_type'],
                $modulePermissions,
            );
        } catch (PrivilegeEscalationException|LicenseQuotaExceededException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with('success', 'Usuario creado.');
    }

    /**
     * Lookup previo al flujo de "invitar usuario existente" (nunca crea
     * nada): permite mostrar en el frontend "ya existe una cuenta con este
     * correo (nombre: X)" ANTES de que el Superusuario/Administrador
     * confirme vincularla — mitigación mínima ante la ausencia de un flujo
     * de invitación por correo con aceptación (no hay envío de mail real
     * configurado, ver MAIL_MAILER=log).
     */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $validated['email'])->where('status', 'active')->first();

        return response()->json([
            'exists' => (bool) $user,
            'name' => $user?->name,
        ]);
    }

    public function storeInvite(Request $request, CurrentCompany $currentCompany, PermissionGrantService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role_type' => ['required', 'string', 'in:admin,user'],
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:none,read,read_write'],
        ]);

        $modulePermissions = collect($validated['permissions'] ?? [])
            ->filter(fn ($level) => $level !== 'none')
            ->all();

        try {
            $user = $service->inviteUser(
                $request->user(),
                $company,
                $validated['email'],
                $validated['role_type'],
                $modulePermissions,
            );
        } catch (UserNotFoundException|PrivilegeEscalationException|LicenseQuotaExceededException $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with('success', "{$user->name} ahora tiene acceso a esta compañía.");
    }

    public function editPermissions(int $user, CurrentCompany $currentCompany, PermissionGrantService $service): Response
    {
        $company = Company::findOrFail($currentCompany->id());
        $target = User::findOrFail($user);

        abort_unless($service->canManage(request()->user(), $target, $company->id), 403);

        $current = ModulePermission::where('company_id', $company->id)
            ->where('subject_type', 'user')
            ->where('subject_id', $target->id)
            ->pluck('access_level', 'module_id');

        return Inertia::render('Users/Permissions', [
            'targetUser' => $target->only(['id', 'name', 'email']),
            'modules' => Module::orderBy('name')->get()->map(fn (Module $module) => [
                'id' => $module->id,
                'code' => $module->code,
                'name' => $module->name,
                'current_access_level' => $current[$module->id] ?? 'none',
                'max_access_level' => $service->effectiveAccessLevel(request()->user(), $company->id, $module),
            ]),
        ]);
    }

    public function updatePermissions(Request $request, int $user, CurrentCompany $currentCompany, PermissionGrantService $service): RedirectResponse
    {
        $company = Company::findOrFail($currentCompany->id());
        $target = User::findOrFail($user);

        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'in:none,read,read_write'],
        ]);

        try {
            $service->updatePermissions($request->user(), $target, $company->id, $validated['permissions'] ?? []);
        } catch (PrivilegeEscalationException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        return redirect()->route('users.index')->with('success', "Permisos de {$target->name} actualizados.");
    }

    public function suspend(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        $target = User::findOrFail($user);

        try {
            $service->suspend(request()->user(), $target, $currentCompany->id());
        } catch (PrivilegeEscalationException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "{$target->name} fue suspendido.");
    }

    public function reactivate(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        $target = User::findOrFail($user);

        try {
            $service->reactivate(request()->user(), $target, $currentCompany->id());
        } catch (PrivilegeEscalationException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "{$target->name} fue reactivado.");
    }

    public function deactivate(int $user, CurrentCompany $currentCompany, UserLifecycleService $service): RedirectResponse
    {
        $target = User::findOrFail($user);

        try {
            $service->deactivate(request()->user(), $target, $currentCompany->id());
        } catch (PrivilegeEscalationException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('success', "{$target->name} fue desactivado.");
    }
}
