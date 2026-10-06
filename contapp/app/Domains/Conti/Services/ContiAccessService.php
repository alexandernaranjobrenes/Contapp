<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Conti\Models\ContiUserSetting;
use App\Domains\Core\Models\AuditLog;
use App\Domains\Core\Models\Company;
use App\Domains\Core\Models\CompanyInvitation;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cómo reparte el Superusuario el cupo de Conti entre las personas de su
 * licencia (CLAUDE.md secc. 32).
 *
 * El cupo (los límites que pone el backoffice) es de la licencia: lo
 * comparten todas sus compañías y personas, el Superusuario incluido. Si
 * alguien lo gasta, nadie más puede usar Conti hasta que se renueve. Por eso
 * el Superusuario, al invitar o al editar los permisos de alguien, decide:
 *
 * - si puede usar Conti;
 * - su límite por día y por semana, en créditos, sin pasar los de la
 *   licencia (cuentan los dos: el suyo y el de todos);
 * - qué modelos puede elegir.
 *
 * Es por persona y licencia (conti_user_settings): vale en todas las
 * compañías de la licencia. Sin decisión, la persona puede usar Conti, sin
 * límite propio y con todos los modelos. Al Superusuario no se lo limita.
 */
class ContiAccessService
{
    public function __construct(private readonly ContiModelService $models) {}

    /** Lo que decidió el Superusuario para esta persona. Null: nada, o es el Superusuario. */
    public function setting(?License $license, User $user): ?ContiUserSetting
    {
        if ($license === null || (int) $license->superuser_id === (int) $user->id) {
            return null;
        }

        return ContiUserSetting::where('license_id', $license->id)->where('user_id', $user->id)->first();
    }

    public function allowed(User $user, Company $company): bool
    {
        return $this->setting($company->license, $user)?->enabled ?? true;
    }

    /**
     * Los modelos que puede elegir. Null: todos.
     *
     * @return list<string>|null
     */
    public function allowedModels(User $user, ?Company $company): ?array
    {
        $models = $company ? $this->setting($company->license, $user)?->models : null;

        return is_array($models) && $models !== [] ? array_values($models) : null;
    }

    /** ¿Quien edita puede repartir Conti? Solo el Superusuario, y si la licencia tiene Conti. */
    public function canConfigure(User $grantor, Company $company): bool
    {
        $license = $company->license;

        return $license !== null
            && $license->ai_enabled
            && filled(config('services.openai.key'))
            && (int) $license->superuser_id === (int) $grantor->id;
    }

    /** Lo más que se le puede dar a alguien por día: el de la licencia y el de persona del backoffice. */
    public function maxDaily(License $license): ?string
    {
        $caps = array_filter([$license->ai_daily_credits, $license->ai_user_daily_credits], fn ($v) => $v !== null);

        return $caps === [] ? null : bcadd((string) min(array_map('floatval', $caps)), '0', 2);
    }

    public function maxWeekly(License $license): ?string
    {
        return $license->ai_weekly_credits !== null ? bcadd((string) $license->ai_weekly_credits, '0', 2) : null;
    }

    /**
     * Lo que necesita la sección «Conti» del formulario (ContiAccessFields.vue):
     * los modelos, el cupo de la licencia con lo que lleva gastado, y lo que
     * tiene y gastó la persona, si ya existe.
     */
    public function form(Company $company, ?User $target = null): array
    {
        $license = $company->license;
        $usage = app(ContiUsageService::class);
        $setting = $target ? $this->setting($license, $target) : null;
        $catalog = $this->models->catalog();

        return [
            'models' => array_values(array_map(fn (array $m) => ['id' => $m['id'], 'nombre' => $m['nombre'], 'costo' => $m['costo']], $catalog)),
            'license' => [
                'daily' => $license->ai_daily_credits !== null ? bcadd((string) $license->ai_daily_credits, '0', 2) : null,
                'weekly' => $this->maxWeekly($license),
                'per_person_daily' => $license->ai_user_daily_credits !== null ? bcadd((string) $license->ai_user_daily_credits, '0', 2) : null,
                'used_today' => bcadd($usage->used($license, $usage->dayStart()), '0', 2),
                'used_week' => bcadd($usage->used($license, $usage->weekStart()), '0', 2),
            ],
            'max_daily' => $this->maxDaily($license),
            'max_weekly' => $this->maxWeekly($license),
            'current' => [
                'enabled' => $setting?->enabled ?? true,
                'daily_credits' => $setting?->daily_credits,
                'weekly_credits' => $setting?->weekly_credits,
                'models' => $setting?->models ? array_values(array_intersect(array_keys($catalog), $setting->models)) : array_keys($catalog),
            ],
            'usage' => $target ? [
                'today' => bcadd($usage->used($license, $usage->dayStart(), $target->id), '0', 2),
                'week' => bcadd($usage->used($license, $usage->weekStart(), $target->id), '0', 2),
            ] : null,
        ];
    }

    /**
     * Reglas de la sección «conti» del formulario. Si no viene, no cambia
     * nada (una página vieja, todavía sin la sección).
     */
    public function rules(License $license, Request $request): array
    {
        $maxDaily = $this->maxDaily($license) ?? '1000000';
        $maxWeekly = $this->maxWeekly($license) ?? '1000000';

        return [
            'conti' => ['sometimes', 'array'],
            'conti.enabled' => ['required_with:conti', 'boolean'],
            'conti.daily_credits' => [
                'nullable', 'numeric', 'min:0', "max:{$maxDaily}",
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $weekly = $request->input('conti.weekly_credits');
                    if ($value !== null && $weekly !== null && is_numeric($weekly) && (float) $value > (float) $weekly) {
                        $fail('El límite por día no puede ser mayor que el de la semana.');
                    }
                },
            ],
            'conti.weekly_credits' => ['nullable', 'numeric', 'min:0', "max:{$maxWeekly}"],
            'conti.models' => ['array', Rule::requiredIf(fn () => $request->boolean('conti.enabled'))],
            'conti.models.*' => ['string', Rule::in(array_keys($this->models->catalog()))],
        ];
    }

    public function messages(?License $license = null): array
    {
        // «50» y no «50.00»: así lo escribe la persona.
        $number = fn (?string $value) => $value === null ? ':max' : rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');

        return [
            'conti.daily_credits.max' => 'No puede pasar lo que la licencia permite por día: '.$number($license ? $this->maxDaily($license) : null).' créditos.',
            'conti.weekly_credits.max' => 'No puede pasar lo que la licencia permite por semana: '.$number($license ? $this->maxWeekly($license) : null).' créditos.',
            'conti.daily_credits.min' => 'No puede ser negativo.',
            'conti.weekly_credits.min' => 'No puede ser negativo.',
            'conti.models.required' => 'Elegí al menos un modelo.',
            'conti.models.*.in' => 'Ese modelo no está disponible.',
        ];
    }

    /**
     * Lo validado, como se guarda. Con todos los modelos marcados se guarda
     * «todos» (null): así también puede usar los que se agreguen después.
     *
     * @return array{enabled: bool, daily_credits: ?string, weekly_credits: ?string, models: ?list<string>}
     */
    public function normalize(array $conti): array
    {
        $catalog = array_keys($this->models->catalog());
        $models = array_values(array_intersect($catalog, $conti['models'] ?? []));
        $credits = fn ($value) => $value === null || $value === '' ? null : bcadd((string) $value, '0', 2);

        return [
            'enabled' => (bool) $conti['enabled'],
            'daily_credits' => $credits($conti['daily_credits'] ?? null),
            'weekly_credits' => $credits($conti['weekly_credits'] ?? null),
            'models' => $models === [] || count($models) === count($catalog) ? null : $models,
        ];
    }

    /** Guarda lo que decidió el Superusuario para una persona, y lo deja en la auditoría. */
    public function save(User $grantor, Company $company, User $target, array $settings): void
    {
        $license = $company->license;

        if ($license === null || (int) $license->superuser_id === (int) $target->id) {
            return;
        }

        $setting = ContiUserSetting::firstOrNew(['license_id' => $license->id, 'user_id' => $target->id]);
        $old = $setting->exists ? $this->describe($setting) : null;
        $setting->fill([...$settings, 'updated_by' => $grantor->id])->save();

        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $grantor->id,
            'action' => 'conti_access_updated',
            'auditable_type' => User::class,
            'auditable_id' => $target->id,
            'old_values' => $old,
            'new_values' => $this->describe($setting),
            'created_at' => now(),
        ]);
    }

    /**
     * Al aceptar una invitación: lo de Conti que eligió el Superusuario al
     * invitar. Si la invitó otra persona (un Administrador), no hay nada que
     * aplicar: queda con Conti, sin límite propio, hasta que el Superusuario
     * decida otra cosa.
     */
    public function applyInvitation(CompanyInvitation $invitation, User $account): void
    {
        $license = $invitation->company?->license;
        $settings = $invitation->conti_settings;

        if ($license === null || ! is_array($settings) || (int) $invitation->invited_by !== (int) $license->superuser_id || (int) $account->id === (int) $license->superuser_id) {
            return;
        }

        ContiUserSetting::updateOrCreate(
            ['license_id' => $license->id, 'user_id' => $account->id],
            [
                'enabled' => (bool) ($settings['enabled'] ?? true),
                'daily_credits' => $settings['daily_credits'] ?? null,
                'weekly_credits' => $settings['weekly_credits'] ?? null,
                'models' => $settings['models'] ?? null,
                'updated_by' => $invitation->invited_by,
            ],
        );
    }

    /**
     * Para la ficha de cada persona en Usuarios: lo que tiene y lo que gastó
     * hoy y esta semana en la licencia.
     *
     * @param  iterable<User>  $users
     * @return array<int, array>
     */
    public function summaries(License $license, iterable $users): array
    {
        $usage = app(ContiUsageService::class);
        $ids = collect($users)->pluck('id')->all();
        $settings = ContiUserSetting::where('license_id', $license->id)->whereIn('user_id', $ids)->get()->keyBy('user_id');

        $spent = fn ($from) => ContiUsage::where('license_id', $license->id)
            ->whereIn('user_id', $ids)
            ->where('created_at', '>=', $from)
            ->groupBy('user_id')
            ->selectRaw('user_id, sum(credits) as total')
            ->pluck('total', 'user_id');

        $today = $spent($usage->dayStart());
        $week = $spent($usage->weekStart());

        $summaries = [];
        foreach ($ids as $id) {
            $isSuperuser = (int) $id === (int) $license->superuser_id;
            $summaries[$id] = [
                ...$this->describe($isSuperuser ? null : $settings->get($id)),
                'superuser' => $isSuperuser,
                'used_today' => bcadd((string) ($today[$id] ?? 0), '0', 2),
                'used_week' => bcadd((string) ($week[$id] ?? 0), '0', 2),
            ];
        }

        return $summaries;
    }

    /**
     * Lo que se muestra de una decisión (de una persona o de una invitación).
     *
     * @return array{enabled: bool, daily_credits: ?string, weekly_credits: ?string, models: ?list<string>}
     */
    public function describe(ContiUserSetting|array|null $setting): array
    {
        $data = $setting instanceof ContiUserSetting
            ? $setting->only(['enabled', 'daily_credits', 'weekly_credits', 'models'])
            : ($setting ?? []);
        $catalog = $this->models->catalog();
        $models = $data['models'] ?? null;
        $credits = fn ($value) => $value === null ? null : bcadd((string) $value, '0', 2);

        return [
            'enabled' => (bool) ($data['enabled'] ?? true),
            'daily_credits' => $credits($data['daily_credits'] ?? null),
            'weekly_credits' => $credits($data['weekly_credits'] ?? null),
            'models' => is_array($models) && $models !== [] ? array_values(array_map(fn ($id) => $catalog[$id]['nombre'] ?? $id, $models)) : null,
        ];
    }
}
