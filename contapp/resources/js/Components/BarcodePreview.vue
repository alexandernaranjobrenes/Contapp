<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { describeBarcode, drawBarcode } from '../Utils/barcode';

/**
 * El código de barras dibujado, como lo va a leer un lector (Utils/barcode.js):
 * en el formulario del artículo mientras se escribe, y en su ficha. Debajo,
 * el tipo (EAN-13, Code 128…) y el aviso si algo no calza.
 */
const props = defineProps({
    value: { type: String, default: '' },
    // En la ficha: más chico y sin el tipo.
    compact: { type: Boolean, default: false },
});

const svg = ref(null);
const info = computed(() => describeBarcode(props.value));
const drawn = ref(false);

watch(() => [props.value, svg.value], async () => {
    await nextTick();
    drawn.value = drawBarcode(svg.value, props.value, props.compact ? { height: 44, fontSize: 13, width: 1.6 } : {});
}, { immediate: true });
</script>

<template>
    <div v-if="value" class="barcode-preview" :class="{ 'is-compact': compact }">
        <div v-show="drawn" class="barcode-paper">
            <svg ref="svg" role="img" :aria-label="`Código de barras ${value}`" />
        </div>
        <p v-if="!compact && drawn && info.label" class="barcode-type">{{ info.label }}</p>
        <p v-if="info.warning" class="barcode-warning">{{ info.warning }}</p>
    </div>
</template>

<style scoped>
.barcode-preview {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.3rem;
    min-width: 0;
}

/* Negro sobre blanco siempre, como se imprime: también en el tema oscuro. */
.barcode-paper {
    max-width: 100%;
    padding: 0.25rem;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    background: #ffffff;
}

.barcode-paper svg {
    display: block;
    max-width: 100%;
    height: auto;
}

.is-compact .barcode-paper svg {
    max-width: 14rem;
}

.barcode-type {
    margin: 0;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--color-text-muted);
}

.barcode-warning {
    margin: 0;
    font-size: 0.76rem;
    line-height: 1.4;
    color: var(--color-warning);
}
</style>
