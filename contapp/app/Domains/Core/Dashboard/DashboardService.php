<?php

namespace App\Domains\Core\Dashboard;

use App\Domains\Billing\Services\Hacienda\ElectronicInvoicing;
use App\Domains\Conti\Services\ContiUsageService;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\DashboardLayout;
use App\Domains\Core\Scopes\CompanyScope;
use App\Domains\Core\Services\ScreenAccessService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El Panel de una compañía (CLAUDE.md secc. 44): qué elementos lleva, cuáles
 * ve cada persona y quién lo cambia.
 *
 * - Cada persona ve solo los elementos de las pantallas a las que tiene
 *   acceso, y los que la licencia o la compañía permiten (canSee). Los demás
 *   ni se calculan.
 * - Lo cambian el Superusuario y los Administradores. Nadie puede agregar
 *   un elemento que no ve: no hay forma de mostrarle a otros lo que uno no
 *   tiene permitido. Lo que no ve queda donde estaba al guardar: no lo puede
 *   quitar ni mover.
 * - Un elemento que falla al calcularse se reporta y sale con su aviso; el
 *   Panel nunca se cae por uno.
 */
class DashboardService
{
    public function __construct(
        private readonly ScreenAccessService $screens,
        private readonly ContiUsageService $conti,
        private readonly ElectronicInvoicing $electronicInvoicing,
    ) {}

    /**
     * Las claves del Panel, en orden: lo guardado, o el de siempre. Una clave
     * que ya no está en el catálogo se ignora.
     *
     * @return list<string>
     */
    public function layout(Company $company): array
    {
        $saved = DashboardLayout::withoutGlobalScope(CompanyScope::class)->where('company_id', $company->id)->first();

        return array_values(array_filter(
            $saved?->widgets ?? DashboardWidgetCatalog::DEFAULT_LAYOUT,
            fn ($key) => is_string($key) && DashboardWidgetCatalog::find($key) !== null,
        ));
    }

    public function canEdit(User $user, Company $company): bool
    {
        return $user->isSuperAdmin($company->id) || $user->roleTypeFor($company->id) === 'admin';
    }

    /**
     * Lo que ve esta persona: los elementos que puede ver, con sus datos.
     *
     * @return list<array<string, mixed>>
     */
    public function panel(User $user, Company $company): array
    {
        $visible = $this->visibility($user, $company);
        $context = DashboardContext::for($company, $user);

        return collect($this->layout($company))
            ->map(fn (string $key) => DashboardWidgetCatalog::find($key))
            ->filter(fn (DashboardWidget $widget) => $visible($widget))
            ->map(fn (DashboardWidget $widget) => $this->render($widget, $context))
            ->values()
            ->all();
    }

    /**
     * Para quien edita: lo que puede agregar (lo que ve) y cuántos elementos
     * del Panel no ve, que igual ocupan lugar.
     *
     * @return array{catalog: list<array<string, mixed>>, hidden: int}
     */
    public function editor(User $user, Company $company): array
    {
        $visible = $this->visibility($user, $company);

        return [
            'catalog' => collect(DashboardWidgetCatalog::all())
                ->filter(fn (DashboardWidget $widget) => $visible($widget))
                ->map(fn (DashboardWidget $widget) => $this->describe($widget))
                ->values()
                ->all(),
            'hidden' => collect($this->layout($company))
                ->reject(fn (string $key) => $visible(DashboardWidgetCatalog::find($key)))
                ->count(),
        ];
    }

    /**
     * Guarda el Panel que armó quien edita: las claves que ve, en su orden.
     * Lo que no ve se reinserta donde estaba.
     *
     * @param  list<string>  $keys
     *
     * @throws ValidationException
     */
    public function save(User $user, Company $company, array $keys, ?string $ip = null): void
    {
        $visible = $this->visibility($user, $company);

        foreach ($keys as $key) {
            $widget = is_string($key) ? DashboardWidgetCatalog::find($key) : null;

            if ($widget === null) {
                throw ValidationException::withMessages(['widgets' => 'Uno de los elementos no existe. Recargá la página y volvé a intentarlo.']);
            }

            if (! $visible($widget)) {
                throw ValidationException::withMessages(['widgets' => "«{$widget->title()}» es de una pantalla a la que no tenés acceso: no lo podés agregar."]);
            }
        }

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['widgets' => 'Un elemento no puede estar dos veces en el Panel.']);
        }

        $current = $this->layout($company);
        $result = array_values($keys);

        foreach ($current as $index => $key) {
            if (! $visible(DashboardWidgetCatalog::find($key))) {
                array_splice($result, min($index, count($result)), 0, [$key]);
            }
        }

        if (count($result) > DashboardWidgetCatalog::MAX_WIDGETS) {
            throw ValidationException::withMessages(['widgets' => 'El Panel lleva hasta '.DashboardWidgetCatalog::MAX_WIDGETS.' elementos. Quitá alguno para agregar otro.']);
        }

        DB::transaction(function () use ($user, $company, $current, $result, $ip) {
            $layout = DashboardLayout::withoutGlobalScope(CompanyScope::class)->firstOrNew(['company_id' => $company->id]);
            $isNew = ! $layout->exists;
            $layout->fill(['widgets' => $result, 'updated_by' => $user->id])->save();

            if ($isNew || $current !== $result) {
                AuditLog::create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'action' => 'company.dashboard_updated',
                    'auditable_type' => DashboardLayout::class,
                    'auditable_id' => $layout->id,
                    'old_values' => ['widgets' => $current],
                    'new_values' => ['widgets' => $result],
                    'ip_address' => $ip,
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Quién ve qué: una función que responde por un elemento, con los
     * permisos de la persona calculados una sola vez.
     *
     * @return callable(?DashboardWidget): bool
     */
    private function visibility(User $user, Company $company): callable
    {
        $levels = $this->screens->levelsFor($user, $company->id);
        $features = [];

        return function (?DashboardWidget $widget) use ($levels, $user, $company, &$features): bool {
            if ($widget === null) {
                return false;
            }

            $screens = $widget->screens();
            if ($screens !== [] && ! collect($screens)->contains(fn (string $s) => ($levels[$s] ?? 'none') !== 'none')) {
                return false;
            }

            foreach ($widget->requires() as $feature) {
                $features[$feature] ??= match ($feature) {
                    'conti' => $this->conti->enabledFor($company, $user),
                    'electronic_invoicing' => $this->electronicInvoicing->isEnabled($company),
                    default => false,
                };

                if (! $features[$feature]) {
                    return false;
                }
            }

            return true;
        };
    }

    /** @return array<string, mixed> */
    private function describe(DashboardWidget $widget): array
    {
        return [
            'key' => $widget->key(),
            'title' => $widget->title(),
            'description' => $widget->description(),
            'section' => $widget->section(),
            'kind' => $widget->kind(),
            'size' => $widget->size(),
        ];
    }

    /** @return array<string, mixed> */
    private function render(DashboardWidget $widget, DashboardContext $context): array
    {
        try {
            $data = $widget->data($context);
            $error = false;
        } catch (Throwable $e) {
            report($e);
            $data = null;
            $error = true;
        }

        return [
            ...$this->describe($widget),
            'href' => $widget->href($context),
            'data' => $data,
            'error' => $error,
        ];
    }
}
