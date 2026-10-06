<script setup>
import { computed } from 'vue';

/**
 * La sección «Conti» al invitar a alguien o al editar sus permisos (Users/
 * Create.vue y Users/Permissions.vue). Solo la ve el Superusuario: con ella
 * reparte el cupo de Conti de la licencia (ContiAccessService, CLAUDE.md
 * secc. 32). El cupo lo comparten todos, el Superusuario incluido: lo que
 * gaste una persona deja menos para las demás.
 *
 * v-model: { enabled, daily_credits, weekly_credits, models }. Los campos se
 * enlazan directo a ese objeto (el del formulario): así cada clic ve lo que
 * dejó el anterior, aunque se marquen varios modelos seguidos.
 */
const props = defineProps({
    // Lo que manda el servidor: modelos, cupo de la licencia, máximos y, al
    // editar, lo que lleva gastado la persona.
    options: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
});

const value = defineModel({ type: Object, required: true });

const creditFormat = new Intl.NumberFormat('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
const credits = (value) => (value === null || value === undefined || value === '' ? 'sin límite' : creditFormat.format(Number(value)));

const COST_BADGES = { bajo: 'badge-success', medio: 'badge-neutral', alto: 'badge-warning' };

const license = computed(() => props.options.license);
</script>

<template>
    <section class="conti-access" aria-labelledby="conti-access-title">
        <h3 id="conti-access-title">Conti, el asistente</h3>
        <p class="hint">
            El cupo de Conti es de toda la licencia y lo comparten todas sus personas, vos incluido: si alguien lo gasta,
            nadie más puede usar Conti hasta que se renueve. Con estos límites lo repartís.
        </p>
        <p class="license-line">
            Tu licencia: <strong>{{ credits(license.daily) }}</strong> créditos por día y <strong>{{ credits(license.weekly) }}</strong> por semana<template v-if="license.per_person_daily">, y hasta {{ credits(license.per_person_daily) }} por persona al día</template>.
            Hoy se usaron {{ credits(license.used_today) }}; esta semana, {{ credits(license.used_week) }}.
        </p>

        <label class="check">
            <input v-model="value.enabled" type="checkbox">
            Puede usar Conti
        </label>
        <p v-if="errors['conti.enabled']" class="error">{{ errors['conti.enabled'] }}</p>

        <template v-if="value.enabled">
            <div class="form-grid limits">
                <div class="field">
                    <label for="conti-daily">Límite por día (créditos)</label>
                    <input
                        id="conti-daily"
                        v-model="value.daily_credits"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        :max="options.max_daily ?? undefined"
                        placeholder="Sin límite propio"
                    >
                    <p v-if="errors['conti.daily_credits']" class="error">{{ errors['conti.daily_credits'] }}</p>
                    <p v-else class="hint">{{ options.max_daily ? `Hasta ${credits(options.max_daily)}, lo de la licencia.` : 'Vacío: sin límite propio.' }}</p>
                </div>
                <div class="field">
                    <label for="conti-weekly">Límite por semana (créditos)</label>
                    <input
                        id="conti-weekly"
                        v-model="value.weekly_credits"
                        type="number"
                        min="0"
                        step="0.01"
                        inputmode="decimal"
                        :max="options.max_weekly ?? undefined"
                        placeholder="Sin límite propio"
                    >
                    <p v-if="errors['conti.weekly_credits']" class="error">{{ errors['conti.weekly_credits'] }}</p>
                    <p v-else class="hint">{{ options.max_weekly ? `Hasta ${credits(options.max_weekly)}, lo de la licencia.` : 'Vacío: sin límite propio.' }}</p>
                </div>
            </div>
            <p v-if="options.usage" class="hint usage-line">
                Lleva gastados {{ credits(options.usage.today) }} créditos hoy y {{ credits(options.usage.week) }} esta semana.
            </p>

            <fieldset class="models">
                <legend>Modelos que puede usar</legend>
                <label v-for="model in options.models" :key="model.id" class="check">
                    <input v-model="value.models" type="checkbox" :value="model.id">
                    {{ model.nombre }}
                    <span v-if="model.costo" class="badge" :class="COST_BADGES[model.costo]">Costo {{ model.costo }}</span>
                </label>
                <p v-if="errors['conti.models']" class="error">{{ errors['conti.models'] }}</p>
                <p v-else class="hint">
                    Entre estos elige en «Modelo y consumo», en el chat. Con todos marcados, también va a poder usar los que se agreguen más adelante.
                </p>
            </fieldset>
        </template>
        <p v-else class="hint">No va a ver el botón de Conti.</p>
    </section>
</template>

<style scoped>
.conti-access {
    margin-top: 1.25rem;
}

h3 {
    font-size: 0.88rem;
    margin: 0 0 0.25rem;
}

.hint {
    font-size: 0.78rem;
    margin: 0.2rem 0 0.6rem;
}

.field .hint {
    margin: 0;
}

.license-line {
    font-size: 0.8rem;
    margin: 0 0 0.75rem;
    padding: 0.5rem 0.65rem;
    border-radius: var(--radius-sm);
    background: var(--color-surface-alt);
    line-height: 1.5;
}

.error {
    color: var(--color-danger);
    font-size: 0.76rem;
    margin: 0.2rem 0 0;
}

.limits {
    margin-top: 0.75rem;
}

.usage-line {
    margin-top: -0.4rem;
}

.models {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin: 0.5rem 0 0;
    padding: 0;
    border: none;
    min-width: 0;
}

.models legend {
    padding: 0;
    margin-bottom: 0.3rem;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.models .badge {
    font-size: 0.66rem;
}
</style>
