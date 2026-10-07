<script setup>
import { ScanLineIcon, SparklesIcon } from '@lucide/vue';
import ContiLookupInput from './ContiLookupInput.vue';

/**
 * Un campo de un formulario del chat (ContiForm.vue), según su tipo
 * (BaseContiAction::field): texto, texto_largo, numero, monto, fecha,
 * opciones, si_no o buscar.
 */
defineProps({
    field: { type: Object, required: true },
    inputId: { type: String, required: true },
    error: { type: String, default: null },
    // Por qué lo sugirió CONTAPP, si el valor es el sugerido.
    suggestion: { type: String, default: null },
    // «documento» si se leyó de un documento escaneado.
    suggestionOrigin: { type: String, default: null },
});

const model = defineModel({ type: [String, Number, Boolean], default: '' });
</script>

<template>
    <div class="cf-field" :class="{ 'is-check': field.tipo === 'si_no', 'is-wide': field.tipo === 'texto_largo' }">
        <label v-if="field.tipo === 'si_no'" class="cf-check">
            <input :id="inputId" v-model="model" type="checkbox">
            {{ field.etiqueta }}
            <span v-if="suggestion" class="cf-suggested" :title="suggestion">
                <ScanLineIcon v-if="suggestionOrigin === 'documento'" :size="11" /><SparklesIcon v-else :size="11" />
                {{ suggestionOrigin === 'documento' ? 'Del documento' : 'Sugerido' }}
            </span>
        </label>

        <template v-else>
            <label :for="inputId" class="cf-label">
                {{ field.etiqueta }}<span v-if="field.requerido" class="cf-required" title="Obligatorio">*</span>
                <span v-if="suggestion" class="cf-suggested" :title="suggestion">
                    <ScanLineIcon v-if="suggestionOrigin === 'documento'" :size="11" /><SparklesIcon v-else :size="11" />
                    {{ suggestionOrigin === 'documento' ? 'Del documento' : 'Sugerido' }}
                </span>
            </label>

            <ContiLookupInput
                v-if="field.tipo === 'buscar'"
                v-model="model"
                :fuente="field.fuente"
                :filtros="field.filtros ?? null"
                :input-id="inputId"
                :invalid="!!error"
            />
            <textarea v-else-if="field.tipo === 'texto_largo'" :id="inputId" v-model="model" rows="3" :aria-invalid="!!error || null" />
            <select v-else-if="field.tipo === 'opciones'" :id="inputId" v-model="model" :aria-invalid="!!error || null">
                <option value="">—</option>
                <option v-for="option in field.opciones" :key="option.valor" :value="option.valor">{{ option.etiqueta }}</option>
            </select>
            <input
                v-else-if="field.tipo === 'fecha'"
                :id="inputId"
                v-model="model"
                type="date"
                :aria-invalid="!!error || null"
            >
            <input
                v-else-if="field.tipo === 'numero' || field.tipo === 'monto'"
                :id="inputId"
                v-model="model"
                type="number"
                :step="field.tipo === 'monto' ? '0.01' : 'any'"
                inputmode="decimal"
                :aria-invalid="!!error || null"
            >
            <input v-else :id="inputId" v-model="model" type="text" :aria-invalid="!!error || null">
        </template>

        <p v-if="error" class="cf-error">{{ error }}</p>
        <p v-else-if="suggestion" class="cf-help is-suggestion">{{ suggestion }}</p>
        <p v-else-if="field.ayuda" class="cf-help">{{ field.ayuda }}</p>
    </div>
</template>

<style scoped>
.cf-field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    min-width: 0;
}

.cf-field.is-wide,
.cf-field.is-check {
    grid-column: 1 / -1;
}

.cf-label {
    font-size: 0.74rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.cf-required {
    margin-left: 0.15rem;
    color: var(--color-danger);
}

.cf-check {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.8rem;
}

.cf-check input {
    width: auto;
    accent-color: var(--color-primary);
}

.cf-field :is(input:not([type="checkbox"]), select, textarea) {
    font-size: 0.84rem;
}

.cf-help,
.cf-error {
    margin: 0;
    font-size: 0.7rem;
    line-height: 1.35;
}

.cf-help {
    color: var(--color-text-muted);
}

/* Lo que sugirió CONTAPP: se ve distinto de lo que escribió la persona. */
.cf-suggested {
    display: inline-flex;
    align-items: center;
    gap: 0.15rem;
    margin-left: 0.35rem;
    padding: 0 0.35rem;
    border-radius: 999px;
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
    font-size: 0.64rem;
    font-weight: 700;
    vertical-align: 1px;
}

.cf-help.is-suggestion {
    color: color-mix(in srgb, var(--color-primary) 55%, var(--color-text-muted));
}

.cf-error {
    color: var(--color-danger);
}
</style>
