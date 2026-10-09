<script setup>
import { computed, onBeforeUnmount, ref, watchEffect } from 'vue';
import { Head } from '@inertiajs/vue3';
import { PrinterIcon, XIcon } from '@lucide/vue';
import { barcodeSvg, describeBarcode } from '../../../Utils/barcode';

/**
 * Etiquetas con el código de barras de un artículo, para imprimir: sin el
 * menú, como el comprobante de pago. Se elige cuántas y en qué papel; lo que
 * se ve es lo que sale (las medidas van en milímetros reales).
 *
 * - Rollo de 50 × 25 mm: una etiqueta por hoja, para impresoras de etiquetas.
 * - Hoja carta de 30 (3 × 10, como la Avery 5160) o A4 de 24 (3 × 8): para
 *   impresoras comunes, con hojas de etiquetas adhesivas.
 *
 * En el diálogo de impresión: escala 100 % («Tamaño real») y sin márgenes
 * del navegador, para que calcen con las etiquetas.
 */
const props = defineProps({
    item: { type: Object, required: true },
    company: { type: Object, required: true },
});

const FORMATS = {
    roll: { label: 'Rollo de etiquetas 50 × 25 mm', perPage: 1 },
    letter: { label: 'Hoja carta: 30 por hoja (3 × 10)', perPage: 30 },
    a4: { label: 'Hoja A4: 24 por hoja (3 × 8)', perPage: 24 },
};

const format = ref('roll');
const quantity = ref(1);
const showName = ref(true);

const count = computed(() => Math.min(Math.max(Math.floor(Number(quantity.value) || 0), 1), 300));
const info = computed(() => describeBarcode(props.item.barcode));
// El dibujo se arma una vez y se repite: 300 etiquetas no son 300 dibujos.
const svg = computed(() => barcodeSvg(props.item.barcode, { height: 50, fontSize: 15, margin: 0, width: 2 }));

const pages = computed(() => {
    const perPage = FORMATS[format.value].perPage;
    return Array.from({ length: Math.ceil(count.value / perPage) }, (_, page) => Math.min(perPage, count.value - page * perPage));
});

// El tamaño de la hoja va en @page, que no se puede poner en un estilo con
// scope: se escribe en el <head> y se quita al salir.
const PAGE_CSS = {
    roll: '@page { size: 50mm 25mm; margin: 0; }',
    letter: '@page { size: letter; margin: 0; }',
    a4: '@page { size: A4; margin: 0; }',
};
const pageStyle = document.createElement('style');
document.head.appendChild(pageStyle);
watchEffect(() => { pageStyle.textContent = PAGE_CSS[format.value]; });
onBeforeUnmount(() => pageStyle.remove());

function printNow() {
    window.print();
}

function closeTab() {
    window.close();
    // Si la pestaña no la abrió CONTAPP, el navegador no deja cerrarla.
    history.back();
}
</script>

<template>
    <Head :title="`Etiquetas — ${item.code}`" />

    <div class="labels-page">
        <div class="toolbar no-print">
            <div class="toolbar-head">
                <div>
                    <h1>Etiquetas de {{ item.code }} — {{ item.name }}</h1>
                    <p class="muted">{{ company.name }}</p>
                </div>
                <button type="button" class="btn btn-ghost" @click="closeTab"><XIcon /> Cerrar</button>
            </div>

            <p v-if="!svg" class="flash flash-error">
                {{ item.barcode ? info.warning ?? 'Este código no se puede dibujar.' : 'Este artículo no tiene código de barras: agregalo o generalo desde «Editar».' }}
            </p>

            <div v-else class="options">
                <div class="field">
                    <label for="labels-format">Papel</label>
                    <select id="labels-format" v-model="format">
                        <option v-for="(f, key) in FORMATS" :key="key" :value="key">{{ f.label }}</option>
                    </select>
                </div>
                <div class="field">
                    <label for="labels-quantity">Cantidad</label>
                    <input id="labels-quantity" v-model="quantity" type="number" min="1" max="300" step="1">
                </div>
                <label class="check">
                    <input v-model="showName" type="checkbox">
                    Con el código y el nombre del artículo
                </label>
                <button type="button" class="btn btn-primary" @click="printNow"><PrinterIcon /> Imprimir</button>
            </div>

            <p v-if="svg" class="hint">
                {{ count }} {{ count === 1 ? 'etiqueta' : 'etiquetas' }} en {{ pages.length }} {{ pages.length === 1 ? 'hoja' : 'hojas' }}.
                Al imprimir, elegí escala 100 % (tamaño real) y sin márgenes, para que calcen con las etiquetas.
            </p>
        </div>

        <div v-if="svg" class="sheets" :class="`is-${format}`">
            <section v-for="(onPage, p) in pages" :key="p" class="sheet">
                <div v-for="n in onPage" :key="n" class="label">
                    <p v-if="showName" class="label-name"><strong>{{ item.code }}</strong> {{ item.name }}</p>
                    <!-- eslint-disable-next-line vue/no-v-html -- SVG de JsBarcode con el código del artículo -->
                    <div class="label-code" v-html="svg" />
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.labels-page {
    min-height: 100vh;
    padding: 1.25rem 1rem 2rem;
    background: var(--color-bg);
    color: var(--color-text);
}

.toolbar {
    max-width: 60rem;
    margin: 0 auto 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.toolbar-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

h1 {
    margin: 0;
    font-size: 1.05rem;
}

.toolbar-head .muted {
    margin: 0.15rem 0 0;
    font-size: 0.82rem;
}

.options {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 0.5rem 1rem;
}

.options .field {
    margin-bottom: 0;
}

.options select {
    min-width: 16rem;
}

.options input[type='number'] {
    width: 7rem;
}

.options .check {
    min-height: 2.5rem;
}

.hint {
    margin: 0;
    font-size: 0.8rem;
}

/* Las hojas, en milímetros reales: en pantalla se ven como van a salir. */
.sheets {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1rem;
}

.sheet {
    box-sizing: border-box;
    background: #fff;
    color: #000;
    box-shadow: 0 1px 6px rgba(0, 0, 0, 0.18);
}

.label {
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 0.6mm;
    overflow: hidden;
}

.label-name {
    margin: 0;
    font: 600 2.6mm/1.15 system-ui, sans-serif;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.label-name strong {
    font-weight: 800;
}

.label-code {
    flex: 1;
    min-height: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.label-code :deep(svg) {
    display: block;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
}

/* Rollo: cada etiqueta es una hoja de 50 × 25 mm. */
.is-roll .sheet {
    width: 50mm;
    height: 25mm;
}

.is-roll .label {
    width: 50mm;
    height: 25mm;
    padding: 1.5mm 2.5mm;
}

/* Carta, 3 × 10: etiquetas de 2,625 × 1 pulgada, margen de 0,5" arriba y
   0,19" a los lados, 0,125" entre columnas (Avery 5160 y compatibles). */
.is-letter .sheet {
    width: 8.5in;
    height: 11in;
    padding: 0.5in 0.19in;
    display: grid;
    grid-template-columns: repeat(3, 2.625in);
    grid-auto-rows: 1in;
    column-gap: 0.125in;
    align-content: start;
}

.is-letter .label {
    padding: 0.06in 0.12in;
}

/* A4, 3 × 8: etiquetas de 70 × 37 mm, sin márgenes. */
.is-a4 .sheet {
    width: 210mm;
    height: 297mm;
    padding: 0.5mm 0;
    display: grid;
    grid-template-columns: repeat(3, 70mm);
    grid-auto-rows: 37mm;
    align-content: start;
}

.is-a4 .label {
    padding: 2mm 4mm;
}

@media print {
    .no-print { display: none !important; }

    .labels-page {
        min-height: 0;
        padding: 0;
        background: #fff;
    }

    .sheets {
        display: block;
    }

    .sheet {
        box-shadow: none;
        break-after: page;
    }

    .sheet:last-child {
        break-after: auto;
    }
}
</style>
