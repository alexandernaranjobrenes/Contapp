<?php

namespace App\Domains\BusinessPartners\Support;

/**
 * NOTA DE UBICACIÓN: esta clase ya no es de BusinessPartners — desde que
 * InventoryAgingService la usa, es un utilitario genérico de cortes por día
 * y su namespace quedó desactualizado. Mover el archivo a un Support
 * compartido es un refactor con alcance en tres dominios y sus tests, y se
 * deja anotado acá en vez de hacerlo de contrabando dentro de otra entrega.
 *
 * Cortes de días configurables, compartidos entre AgingService (antigüedad
 * de saldos: días de ATRASO desde el vencimiento), CashFlowProjectionService
 * (proyección: días HASTA el vencimiento) e InventoryAgingService
 * (antigüedad de inventario: días SIN ROTAR) — antes cada uno tenía sus buckets
 * fijos en código (30/60/90 y 15/30/60/90 respectivamente); ahora el usuario
 * puede definir sus propios cortes, y ambos reportes generan sus columnas
 * dinámicamente a partir de la misma lista de enteros ascendentes.
 *
 * Los dos reportes usan la MISMA lista de cortes con dos lecturas distintas
 * (overdue* para antigüedad, untilDue* para proyección) porque el "punto
 * cero" de cada uno es distinto: antigüedad separa lo vigente de lo vencido
 * (el corte 0 ya existe implícito como "current"), proyección separa lo ya
 * vencido de lo que falta por vencer (el corte 0 es el arranque del primer
 * bucket futuro, no uno intermedio).
 */
class DayBucketScheme
{
    /**
     * Cada reporte trae su propio estándar histórico (Aging: 30/60/90;
     * CashFlowProjection: 15/30/60/90) — unificar en un solo default
     * cambiaría en silencio la vista por defecto del que no lo pidió, así
     * que fromInput() recibe el estándar de CADA caller explícitamente en
     * vez de asumir uno solo acá.
     */
    public const AGING_DEFAULT = [30, 60, 90];

    public const CASH_FLOW_DEFAULT = [15, 30, 60, 90];

    /**
     * Inventario: cortes más largos que los de cartera. Una factura a 90 días
     * ya está muy vencida; una existencia de 90 días puede ser rotación
     * normal, y lo que interesa cazar es lo que lleva medio año o más sin
     * moverse.
     */
    public const INVENTORY_DEFAULT = [30, 60, 90, 180, 360];

    private const MAX_BOUNDARIES = 12;

    /**
     * @param  int[]  $boundaries  ascendentes, únicos, positivos (ej. [30, 60, 90])
     */
    private function __construct(public readonly array $boundaries) {}

    /**
     * Parsea "15,45,90,180" (o con espacios) a una lista de enteros
     * ascendentes, únicos, positivos. Cualquier entrada vacía, inválida, o
     * sin al menos un corte usable cae a $defaultBoundaries — nunca revienta
     * el reporte por un parámetro mal formado (es un filtro opcional).
     *
     * @param  int[]  $defaultBoundaries
     */
    public static function fromInput(?string $raw, array $defaultBoundaries = self::AGING_DEFAULT): self
    {
        if ($raw === null || trim($raw) === '') {
            return new self($defaultBoundaries);
        }

        $boundaries = collect(explode(',', $raw))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '' && ctype_digit($v))
            ->map(fn ($v) => (int) $v)
            ->filter(fn ($v) => $v > 0)
            ->unique()
            ->sort()
            ->values()
            ->take(self::MAX_BOUNDARIES)
            ->all();

        return count($boundaries) > 0 ? new self($boundaries) : new self($defaultBoundaries);
    }

    public function toInput(): string
    {
        return implode(',', $this->boundaries);
    }

    /**
     * Antigüedad de saldos: 'current' (vigente) + un bucket por cada tramo
     * entre cortes + 'over' (todo lo que pasa el último corte).
     *
     * @return array<string, string> clave => etiqueta, en orden de despliegue
     */
    public function overdueLabels(): array
    {
        $labels = ['current' => 'Vigente'];
        $prev = 0;

        foreach ($this->boundaries as $b) {
            $labels["d_{$this->plus($prev)}_{$b}"] = "{$this->plus($prev)}-{$b} días";
            $prev = $b;
        }

        $labels['over'] = "+{$prev} días";

        return $labels;
    }

    public function resolveOverdueKey(int $daysOverdue): string
    {
        if ($daysOverdue <= 0) {
            return 'current';
        }

        $prev = 0;
        foreach ($this->boundaries as $b) {
            if ($daysOverdue <= $b) {
                return "d_{$this->plus($prev)}_{$b}";
            }
            $prev = $b;
        }

        return 'over';
    }

    /**
     * Proyección de cobros/pagos: 'overdue' (ya vencido) + un bucket 0-{b1}
     * + un tramo por cada corte siguiente + 'over'.
     *
     * @return array<string, string>
     */
    public function untilDueLabels(): array
    {
        $labels = ['overdue' => 'Vencido'];
        $prev = -1;

        foreach ($this->boundaries as $b) {
            $labels["d_{$this->plus($prev)}_{$b}"] = "{$this->plus($prev)}-{$b} días";
            $prev = $b;
        }

        $labels['over'] = "+{$prev} días";

        return $labels;
    }

    public function resolveUntilDueKey(int $daysUntilDue): string
    {
        if ($daysUntilDue < 0) {
            return 'overdue';
        }

        $prev = -1;
        foreach ($this->boundaries as $b) {
            if ($daysUntilDue <= $b) {
                return "d_{$this->plus($prev)}_{$b}";
            }
            $prev = $b;
        }

        return 'over';
    }

    /**
     * Antigüedad de inventario: días que una existencia lleva SIN ROTAR.
     *
     * Tercera lectura de la misma lista de cortes. No reusa overdueLabels()
     * ni untilDueLabels() porque ninguna de las dos calza: los días sin rotar
     * nunca son negativos, así que "Vigente" y "Vencido" —los buckets que
     * esas dos reservan para el signo— quedarían siempre vacíos y confundirían
     * la lectura del reporte.
     *
     * @return array<string, string>
     */
    public function idleLabels(): array
    {
        $labels = [];
        $prev = -1;

        foreach ($this->boundaries as $b) {
            $labels["d_{$this->plus($prev)}_{$b}"] = "{$this->plus($prev)}-{$b} días";
            $prev = $b;
        }

        $labels['over'] = "+{$prev} días";

        return $labels;
    }

    public function resolveIdleKey(int $daysIdle): string
    {
        $prev = -1;

        foreach ($this->boundaries as $b) {
            if ($daysIdle <= $b) {
                return "d_{$this->plus($prev)}_{$b}";
            }
            $prev = $b;
        }

        return 'over';
    }

    private function plus(int $value): int
    {
        return $value + 1;
    }
}
