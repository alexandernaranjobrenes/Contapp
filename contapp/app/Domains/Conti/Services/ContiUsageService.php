<?php

namespace App\Domains\Conti\Services;

use App\Domains\Conti\Agent\AgentUsage;
use App\Domains\Conti\Models\ContiUsage;
use App\Domains\Core\Models\Company;
use App\Domains\Licensing\Models\License;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Quién puede usar Conti y cuánto (CLAUDE.md secc. 32).
 *
 * - Disponible: hace falta la key de OpenAI, que la licencia de la compañía
 *   tenga Conti activado desde el backoffice, y que el Superusuario no se lo
 *   haya quitado a la persona (ContiAccessService). Una compañía sin
 *   licencia (creada por seeder, de desarrollo) lo tiene sin límites.
 * - Límites, en créditos (1 crédito = conti.credit_usd dólares de lo que
 *   cobra el modelo): por día y por semana para toda la licencia —un cupo
 *   que comparten todas sus compañías y personas, el Superusuario
 *   incluido—, y los de cada persona: el de persona al día del backoffice y
 *   los que le puso el Superusuario. El día y la semana (lunes a domingo)
 *   son los de conti.timezone.
 * - Se revisa antes de llamar al modelo. El mensaje que cruza el límite se
 *   termina de responder; los siguientes ya no salen.
 */
class ContiUsageService
{
    public function __construct(private readonly ContiAccessService $access) {}

    public function configured(): bool
    {
        return filled(config('services.openai.key'));
    }

    /** ¿Conti aparece en esta compañía (y para esta persona, si se dice)? */
    public function enabledFor(?Company $company, ?User $user = null): bool
    {
        if (! $this->configured() || $company === null) {
            return false;
        }

        $license = $company->license;

        if ($license !== null && ! $license->ai_enabled) {
            return false;
        }

        return $user === null || $this->access->allowed($user, $company);
    }

    /**
     * Si esta persona no puede mandarle un mensaje a Conti ahora, por qué:
     * [código HTTP, mensaje]. Null si puede.
     *
     * @return array{0: int, 1: string}|null
     */
    public function blockFor(User $user, Company $company): ?array
    {
        if (! $this->configured()) {
            return [503, 'Conti todavía no está configurado en esta instalación de CONTAPP.'];
        }

        $license = $company->license;

        if ($license === null) {
            return null;
        }

        if (! $license->ai_enabled) {
            return [403, 'Conti no está activado en la licencia de esta compañía. Si querés usarlo, contactá al equipo de CONTAPP.'];
        }

        if (! $this->access->allowed($user, $company)) {
            return [403, 'No tenés acceso a Conti en esta licencia. Si lo necesitás, pedíselo al Superusuario.'];
        }

        foreach ($this->limits($license, $user) as $limit) {
            if (bccomp($limit['used'], $limit['limit'], 4) >= 0) {
                return [429, $limit['blocked']];
            }
        }

        return null;
    }

    /**
     * Un aviso cuando algún límite ya va por encima de conti.warning_percent.
     */
    public function warningFor(User $user, Company $company): ?string
    {
        $license = $company->license;

        if ($license === null) {
            return null;
        }

        $threshold = (string) config('conti.warning_percent', 80);

        foreach ($this->limits($license, $user) as $limit) {
            if (bccomp($limit['limit'], '0', 4) <= 0) {
                continue;
            }

            $percent = bcdiv(bcmul($limit['used'], '100', 4), $limit['limit'], 0);

            if (bccomp($percent, $threshold, 0) >= 0 && bccomp($limit['used'], $limit['limit'], 4) < 0) {
                return "Ya se usó el {$percent} % del {$limit['label']} de Conti.";
            }
        }

        return null;
    }

    /**
     * Los límites que aplican, con lo consumido de cada uno.
     *
     * @return list<array{key: string, label: string, limit: string, used: string, blocked: string}>
     */
    public function limits(License $license, ?User $user = null): array
    {
        $limits = [];

        if ($license->ai_daily_credits !== null) {
            $limits[] = [
                'key' => 'daily',
                'label' => 'límite diario de la licencia',
                'limit' => (string) $license->ai_daily_credits,
                'used' => $this->used($license, $this->dayStart()),
                'blocked' => "Se alcanzó el límite diario de Conti de esta licencia ({$this->credits($license->ai_daily_credits)} créditos), que comparten todas sus personas. Se renueva mañana a las 00:00.",
            ];
        }

        if ($license->ai_weekly_credits !== null) {
            $limits[] = [
                'key' => 'weekly',
                'label' => 'límite semanal de la licencia',
                'limit' => (string) $license->ai_weekly_credits,
                'used' => $this->used($license, $this->weekStart()),
                'blocked' => "Se alcanzó el límite semanal de Conti de esta licencia ({$this->credits($license->ai_weekly_credits)} créditos), que comparten todas sus personas. Se renueva el lunes a las 00:00.",
            ];
        }

        if ($user === null) {
            return $limits;
        }

        // Los de la persona: el de persona al día del backoffice y los que le
        // puso el Superusuario. Por día cuenta el más chico.
        $setting = $this->access->setting($license, $user);
        $daily = array_filter([$license->ai_user_daily_credits, $setting?->daily_credits], fn ($v) => $v !== null);

        if ($daily !== []) {
            $userDaily = bcadd((string) min(array_map('floatval', $daily)), '0', 2);
            $limits[] = [
                'key' => 'user_daily',
                'label' => 'tu límite diario',
                'limit' => $userDaily,
                'used' => $this->used($license, $this->dayStart(), $user->id),
                'blocked' => "Llegaste a tu límite diario de Conti ({$this->credits($userDaily)} créditos). Se renueva mañana a las 00:00.",
            ];
        }

        if ($setting?->weekly_credits !== null) {
            $limits[] = [
                'key' => 'user_weekly',
                'label' => 'tu límite semanal',
                'limit' => (string) $setting->weekly_credits,
                'used' => $this->used($license, $this->weekStart(), $user->id),
                'blocked' => "Llegaste a tu límite semanal de Conti ({$this->credits($setting->weekly_credits)} créditos). Se renueva el lunes a las 00:00.",
            ];
        }

        return $limits;
    }

    /** Créditos consumidos por la licencia (o por una persona en ella) desde ese momento. */
    public function used(License $license, Carbon $from, ?int $userId = null): string
    {
        $sum = ContiUsage::where('license_id', $license->id)
            ->where('created_at', '>=', $from)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->sum('credits');

        return bcadd((string) $sum, '0', 4);
    }

    /** El inicio de hoy, en la zona de Conti, expresado en la hora del servidor. */
    public function dayStart(): Carbon
    {
        return now(config('conti.timezone'))->startOfDay()->setTimezone(config('app.timezone'));
    }

    /** El inicio de esta semana (lunes), en la zona de Conti. */
    public function weekStart(): Carbon
    {
        return now(config('conti.timezone'))->startOfWeek(Carbon::MONDAY)->setTimezone(config('app.timezone'));
    }

    /** El primer día de este mes, en la zona de Conti. */
    public function monthStart(): Carbon
    {
        return now(config('conti.timezone'))->startOfMonth()->setTimezone(config('app.timezone'));
    }

    /**
     * Lo que ve la persona en «Modelo y consumo»: lo que gastó ella hoy, esta
     * semana y este mes, y los límites que le aplican con lo usado de cada
     * uno. Lo suyo en la licencia de esta compañía, que es donde cuentan los
     * límites; en una compañía sin licencia, lo suyo en esa compañía.
     */
    public function personal(User $user, Company $company): array
    {
        $license = $company->license;

        $period = function (Carbon $from) use ($user, $company, $license): array {
            $row = ContiUsage::where('user_id', $user->id)
                ->when(
                    $license !== null,
                    fn ($q) => $q->where('license_id', $license->id),
                    fn ($q) => $q->where('company_id', $company->id),
                )
                ->where('created_at', '>=', $from)
                ->selectRaw('count(*) as mensajes, coalesce(sum(prompt_tokens), 0) as entrada, coalesce(sum(completion_tokens), 0) as salida, coalesce(sum(credits), 0) as creditos')
                ->first();

            return [
                'mensajes' => (int) $row->mensajes,
                'tokens' => (int) $row->entrada + (int) $row->salida,
                'tokens_entrada' => (int) $row->entrada,
                'tokens_salida' => (int) $row->salida,
                'creditos' => bcadd((string) $row->creditos, '0', 2),
            ];
        };

        $renews = ['daily' => 'mañana a las 00:00', 'weekly' => 'el lunes a las 00:00', 'user_daily' => 'mañana a las 00:00', 'user_weekly' => 'el lunes a las 00:00'];
        $names = [
            'daily' => 'De la licencia, hoy',
            'weekly' => 'De la licencia, esta semana',
            'user_daily' => 'Tu límite de hoy',
            'user_weekly' => 'Tu límite de esta semana',
        ];

        return [
            'hoy' => $period($this->dayStart()),
            'semana' => $period($this->weekStart()),
            'mes' => $period($this->monthStart()),
            // Primero los de la persona: son los que más le importan.
            'limites' => collect($license !== null ? $this->limits($license, $user) : [])
                ->sortBy(fn (array $l) => str_starts_with($l['key'], 'user_') ? 0 : 1)
                ->map(fn (array $l) => [
                    'clave' => $l['key'],
                    'nombre' => $names[$l['key']],
                    'limite' => bcadd($l['limit'], '0', 2),
                    'usado' => bcadd($l['used'], '0', 2),
                    'renueva' => $renews[$l['key']],
                ])->values()->all(),
            'credito_usd' => (float) config('conti.credit_usd', 0.01),
        ];
    }

    /** Guarda lo que consumió un mensaje. */
    public function record(User $user, Company $company, AgentUsage $usage, string $status = 'ok'): ?ContiUsage
    {
        if ($usage->isEmpty()) {
            return null;
        }

        $cost = $this->cost($usage->model, $usage->promptTokens, $usage->cachedTokens, $usage->completionTokens);

        return ContiUsage::create([
            'license_id' => $company->license_id,
            'company_id' => $company->id,
            'user_id' => $user->id,
            'model' => $usage->model,
            'prompt_tokens' => $usage->promptTokens,
            'cached_tokens' => $usage->cachedTokens,
            'completion_tokens' => $usage->completionTokens,
            'tool_calls' => $usage->toolCalls,
            'cost_usd' => $cost,
            'credits' => bcdiv($cost, number_format((float) config('conti.credit_usd', 0.01), 6, '.', ''), 4),
            'status' => $status,
        ]);
    }

    /** Lo que cobra el modelo por esos tokens, en dólares. */
    public function cost(string $model, int $promptTokens, int $cachedTokens, int $completionTokens): string
    {
        $price = $this->pricingFor($model);
        $cached = min($cachedTokens, $promptTokens);

        $perMillion = bcadd(
            bcadd(
                bcmul((string) ($promptTokens - $cached), $this->decimal($price['input']), 6),
                bcmul((string) $cached, $this->decimal($price['cached_input']), 6),
                6,
            ),
            bcmul((string) $completionTokens, $this->decimal($price['output']), 6),
            6,
        );

        return bcdiv($perMillion, '1000000', 6);
    }

    /**
     * El precio del modelo: el de conti.pricing con el prefijo más largo
     * («gpt-4.1-mini-2025-04-14» → «gpt-4.1-mini»), o el de respaldo.
     *
     * Después del prefijo solo puede venir la fecha de la versión: «gpt-5.4»
     * o «gpt-5-chat-latest» no son «gpt-5», y van con el precio de respaldo
     * hasta que se agreguen a la tabla.
     *
     * @return array{input: float, cached_input: float, output: float}
     */
    public function pricingFor(string $model): array
    {
        // La tabla entera y no config("conti.pricing.{$model}"): el punto de
        // «gpt-4.1» es el separador de claves de config().
        $pricing = config('conti.pricing', []);
        $match = null;

        foreach (array_keys($pricing) as $prefix) {
            $rest = substr($model, strlen($prefix));
            $isVersion = str_starts_with($model, $prefix) && ($rest === '' || preg_match('/^-\d/', $rest) === 1);

            if ($isVersion && ($match === null || strlen($prefix) > strlen($match))) {
                $match = $prefix;
            }
        }

        return $match !== null ? $pricing[$match] : config('conti.fallback_pricing');
    }

    /**
     * El consumo de una licencia para el backoffice: hoy, esta semana, los
     * últimos 30 días, por compañía, por persona y día por día.
     */
    public function report(License $license): array
    {
        $tz = config('conti.timezone');
        $since = now($tz)->subDays(29)->startOfDay()->setTimezone(config('app.timezone'));

        $rows = ContiUsage::where('license_id', $license->id)
            ->where('created_at', '>=', $since)
            ->with(['company:id,legal_name,trade_name', 'user:id,name'])
            ->get(['id', 'company_id', 'user_id', 'credits', 'cost_usd', 'status', 'created_at']);

        $weekStart = $this->weekStart();
        $week = $rows->filter(fn (ContiUsage $r) => $r->created_at->gte($weekStart));

        $sum = fn ($items) => bcadd((string) $items->reduce(fn ($carry, ContiUsage $r) => bcadd($carry, (string) $r->credits, 4), '0'), '0', 2);
        $group = fn ($items, string $key, callable $label) => $items->groupBy($key)
            ->map(fn ($group) => ['nombre' => $label($group->first()), 'mensajes' => $group->count(), 'creditos' => $sum($group)])
            ->sortByDesc(fn ($g) => (float) $g['creditos'])->values()->all();

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[now($tz)->subDays($i)->format('Y-m-d')] = ['fecha' => now($tz)->subDays($i)->format('Y-m-d'), 'mensajes' => 0, 'creditos' => '0.00'];
        }
        foreach ($rows as $row) {
            $day = $row->created_at->copy()->setTimezone($tz)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day]['mensajes']++;
                $days[$day]['creditos'] = bcadd($days[$day]['creditos'], (string) $row->credits, 2);
            }
        }

        $companyName = fn (ContiUsage $r) => $r->company?->trade_name ?: ($r->company?->legal_name ?? 'Compañía eliminada');
        $userName = fn (ContiUsage $r) => $r->user?->name ?? 'Persona eliminada';

        return [
            'activo' => (bool) $license->ai_enabled,
            'credito_usd' => (float) config('conti.credit_usd', 0.01),
            'limites' => array_map(fn (array $l) => ['clave' => $l['key'], 'nombre' => $l['label'], 'limite' => $l['limit'], 'usado' => bcadd($l['used'], '0', 2)], $this->limits($license)),
            'limite_por_persona' => $license->ai_user_daily_credits !== null ? (string) $license->ai_user_daily_credits : null,
            'hoy' => ['mensajes' => $rows->filter(fn ($r) => $r->created_at->gte($this->dayStart()))->count(), 'creditos' => $this->used($license, $this->dayStart())],
            'semana' => ['mensajes' => $week->count(), 'creditos' => $sum($week)],
            'treinta_dias' => [
                'mensajes' => $rows->count(),
                'creditos' => $sum($rows),
                'costo_usd' => bcadd((string) $rows->reduce(fn ($carry, ContiUsage $r) => bcadd($carry, (string) $r->cost_usd, 6), '0'), '0', 4),
                'errores' => $rows->where('status', 'error')->count(),
            ],
            'por_compania' => $group($week, 'company_id', $companyName),
            'por_persona' => $group($week, 'user_id', $userName),
            'por_dia' => array_values($days),
        ];
    }

    private function credits(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }

    private function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }
}
