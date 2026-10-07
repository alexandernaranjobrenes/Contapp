<script setup>
import { computed, reactive } from 'vue';
import { CheckIcon } from '@lucide/vue';

/**
 * Preguntas de Conti con opciones para elegir (la herramienta «preguntar»,
 * CLAUDE.md secc. 32): una o varias por pregunta, y siempre «Otra», para
 * escribir algo que no está en la lista. Al responder, la respuesta va como
 * el próximo mensaje de la persona y las preguntas quedan como resumen.
 */
const props = defineProps({
    message: { type: Object, required: true },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['answer']);

const OTHER = '__otra__';

// Por pregunta: lo elegido y el texto de «Otra».
const state = reactive(props.message.preguntas.map(() => ({ chosen: [], other: '' })));

const answered = computed(() => props.message.respuestas);

function toggle(index, value, multiple) {
    const current = state[index].chosen;

    if (!multiple) {
        state[index].chosen = [value];
        return;
    }

    state[index].chosen = current.includes(value) ? current.filter((v) => v !== value) : [...current, value];
}

function isChosen(index, value) {
    return state[index].chosen.includes(value);
}

// Cada pregunta, con algo elegido (y, si es «Otra», con texto).
const complete = computed(() => state.every((s) => {
    const options = s.chosen.filter((v) => v !== OTHER);
    const other = s.chosen.includes(OTHER) ? s.other.trim() : '';
    return options.length > 0 || other !== '';
}));

function submit() {
    if (!complete.value || props.disabled) return;

    const respuestas = props.message.preguntas.map((question, i) => ({
        pregunta: question.encabezado || question.pregunta,
        elegidas: state[i].chosen.filter((v) => v !== OTHER),
        otra: state[i].chosen.includes(OTHER) ? state[i].other.trim() : '',
    }));

    emit('answer', respuestas);
}

function summary(answer) {
    return [...answer.elegidas, ...(answer.otra ? [answer.otra] : [])].join(', ');
}
</script>

<template>
    <div class="conti-questions" :class="{ 'is-answered': answered }">
        <template v-if="!answered">
            <fieldset v-for="(question, i) in message.preguntas" :key="i" class="question">
                <legend>
                    <span v-if="question.encabezado" class="question-chip">{{ question.encabezado }}</span>
                    <span class="question-text">{{ question.pregunta }}</span>
                    <span v-if="question.multiple" class="question-hint">Podés elegir varias.</span>
                </legend>

                <button
                    v-for="option in question.opciones"
                    :key="option.etiqueta"
                    type="button"
                    class="option"
                    :class="{ 'is-chosen': isChosen(i, option.etiqueta), 'is-multiple': question.multiple }"
                    :aria-pressed="isChosen(i, option.etiqueta)"
                    :disabled="disabled"
                    @click="toggle(i, option.etiqueta, question.multiple)"
                >
                    <span class="option-mark" aria-hidden="true"><CheckIcon v-if="isChosen(i, option.etiqueta)" :size="12" /></span>
                    <span class="option-body">
                        <span class="option-label">{{ option.etiqueta }}</span>
                        <span v-if="option.descripcion" class="option-desc">{{ option.descripcion }}</span>
                    </span>
                </button>

                <button
                    type="button"
                    class="option"
                    :class="{ 'is-chosen': isChosen(i, OTHER), 'is-multiple': question.multiple }"
                    :aria-pressed="isChosen(i, OTHER)"
                    :disabled="disabled"
                    @click="toggle(i, OTHER, question.multiple)"
                >
                    <span class="option-mark" aria-hidden="true"><CheckIcon v-if="isChosen(i, OTHER)" :size="12" /></span>
                    <span class="option-label">Otra</span>
                </button>
                <input
                    v-if="isChosen(i, OTHER)"
                    v-model="state[i].other"
                    type="text"
                    class="other-input"
                    maxlength="300"
                    placeholder="Escribí tu respuesta"
                    :aria-label="`Otra respuesta: ${question.pregunta}`"
                    @keydown.enter.prevent="submit"
                >
            </fieldset>

            <button type="button" class="btn btn-primary btn-sm answer-btn" :disabled="!complete || disabled" @click="submit">
                Responder
            </button>
        </template>

        <ul v-else class="answers">
            <li v-for="(answer, i) in answered" :key="i">
                <CheckIcon :size="13" aria-hidden="true" />
                <span><strong>{{ answer.pregunta }}:</strong> {{ summary(answer) }}</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.conti-questions {
    align-self: stretch;
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
    padding: 0.75rem 0.8rem;
    border: 1px solid color-mix(in srgb, var(--color-primary) 30%, var(--color-border));
    border-radius: var(--radius-md);
    background: var(--color-surface);
    font-size: 0.84rem;
}

.conti-questions.is-answered {
    padding: 0.5rem 0.7rem;
    border-color: var(--color-border);
    background: var(--color-surface-alt);
}

.question {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    margin: 0;
    padding: 0;
    border: none;
    min-width: 0;
}

.question legend {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.3rem 0.45rem;
    margin-bottom: 0.15rem;
    padding: 0;
}

.question-chip {
    padding: 0.05rem 0.45rem;
    border-radius: 999px;
    background: var(--color-primary-soft);
    color: color-mix(in srgb, var(--color-primary) 70%, var(--color-text));
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.02em;
}

.question-text {
    font-weight: 600;
}

.question-hint {
    font-size: 0.72rem;
    color: var(--color-text-muted);
}

.option {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    width: 100%;
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: var(--color-bg);
    font: inherit;
    color: var(--color-text);
    text-align: left;
    cursor: pointer;
}

.option:hover:not(:disabled) {
    border-color: color-mix(in srgb, var(--color-primary) 45%, var(--color-border));
}

.option.is-chosen {
    border-color: var(--color-primary);
    background: var(--color-primary-soft);
}

.option-mark {
    flex-shrink: 0;
    display: inline-grid;
    place-items: center;
    width: 1rem;
    height: 1rem;
    margin-top: 0.1rem;
    border: 1.5px solid var(--color-text-muted);
    border-radius: 50%;
    color: var(--color-on-primary, #fff);
}

.option.is-multiple .option-mark {
    border-radius: 0.25rem;
}

.option.is-chosen .option-mark {
    border-color: var(--color-primary);
    background: var(--color-primary);
}

.option-body {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
    min-width: 0;
}

.option-desc {
    font-size: 0.74rem;
    color: var(--color-text-muted);
}

.other-input {
    font-size: 0.84rem;
}

.answer-btn {
    align-self: flex-end;
}

.answers {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.8rem;
    color: var(--color-text-muted);
}

.answers li {
    display: flex;
    align-items: flex-start;
    gap: 0.35rem;
}

.answers svg {
    flex-shrink: 0;
    margin-top: 0.2rem;
    color: var(--color-success);
}
</style>
