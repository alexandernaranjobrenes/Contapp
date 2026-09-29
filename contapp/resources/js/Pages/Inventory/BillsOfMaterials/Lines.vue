<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import { ArrowLeftIcon, PlusIcon, SaveIcon, XIcon } from '@lucide/vue';

const props = defineProps({
    bom: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    preview: { type: Array, default: () => [] },
});

const page = usePage();

const form = useForm({
    lines: props.lines.map((l) => ({
        component_item_id: l.component_item_id,
        quantity: l.quantity,
        scrap_percentage: l.scrap_percentage,
        warehouse_id: l.warehouse_id ?? '',
        notes: l.notes ?? '',
    })),
});

function addLine() {
    form.lines.push({ component_item_id: '', quantity: 1, scrap_percentage: 0, warehouse_id: '', notes: '' });
}

function removeLine(index) {
    form.lines.splice(index, 1);
}

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

function itemLabel(id) {
    const item = props.items.find((i) => i.id === id);

    return item ? `${item.code} — ${item.name}` : '';
}

// Lo que de verdad se va a emitir por línea, con la merma ya aplicada. Se
// calcula acá también para que se vea al escribir, sin tener que guardar.
function required(line) {
    const base = Number(line.quantity || 0);
    const scrap = Number(line.scrap_percentage || 0);

    return base * (1 + scrap / 100);
}

// El producto no puede ser componente de su propia receta: el servidor lo
// rechaza, pero avisarlo acá evita perder lo escrito.
const selfReference = computed(
    () => form.lines.some((l) => Number(l.component_item_id) === Number(props.bom.item_id))
);

const duplicated = computed(() => {
    const ids = form.lines.map((l) => Number(l.component_item_id)).filter(Boolean);

    return ids.length !== new Set(ids).size;
});

function submit() {
    form.transform((data) => ({
        lines: data.lines.map((l) => ({
            ...l,
            warehouse_id: l.warehouse_id === '' ? null : l.warehouse_id,
            notes: l.notes === '' ? null : l.notes,
            scrap_percentage: l.scrap_percentage === '' ? 0 : l.scrap_percentage,
        })),
    })).put(route('bills-of-materials.lines.update', props.bom.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="'Componentes — ' + bom.code" />

    <AppLayout :title="'Componentes — ' + bom.code + ' ' + bom.name">
        <div class="view-toolbar">
            <Link :href="route('bills-of-materials.index')" class="btn btn-ghost"><ArrowLeftIcon /> Recetas</Link>
        </div>

        <div v-if="page.props.errors?.lines" class="flash flash-error">{{ page.props.errors.lines }}</div>

        <p class="hint">
            Esta receta produce <strong>{{ quantity(bom.output_quantity) }}</strong> unidad(es) de
            <strong>{{ bom.item_code }} — {{ bom.item_name }}</strong>.
            Las cantidades de abajo son <strong>por lote completo</strong>, no por unidad.
        </p>

        <p v-if="selfReference" class="flash flash-error">
            {{ bom.item_code }} no puede ser componente de su propia receta: un producto no se fabrica a partir
            de sí mismo.
        </p>

        <p v-if="duplicated" class="flash flash-error">
            Hay un componente repetido. Dos líneas del mismo insumo emitirían el doble sin que nadie lo note.
        </p>

        <!-- Grilla de captura: cada línea se edita en la tabla y conserva su
             botón de quitar (CLAUDE.md secc. 20, excepción). -->
        <form @submit.prevent="submit">
            <div class="card">
                <div class="table-responsive capture-grid">
                    <table class="lines-table">
                        <colgroup>
                            <col>
                            <col class="col-qty">
                            <col class="col-scrap">
                            <col class="col-emit">
                            <col class="col-warehouse">
                            <col class="col-notes">
                            <col class="col-remove">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Componente</th>
                                <th class="num">Cantidad por lote</th>
                                <th class="num">Merma %</th>
                                <th class="num">Se emite</th>
                                <th>Almacén</th>
                                <th>Notas</th>
                                <th><span class="sr-only">Quitar</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(line, index) in form.lines" :key="index">
                                <td>
                                    <select v-model="line.component_item_id" required aria-label="Componente">
                                        <option value="">Elegí un artículo</option>
                                        <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                    </select>
                                </td>
                                <td data-label="Cantidad por lote" class="num">
                                    <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="num-input" aria-label="Cantidad por lote">
                                </td>
                                <td data-label="Merma %" class="num">
                                    <input v-model="line.scrap_percentage" type="number" step="0.0001" min="0" max="100" class="num-input" aria-label="Merma %">
                                </td>
                                <td data-label="Se emite" class="num"><strong>{{ quantity(required(line)) }}</strong></td>
                                <td data-label="Almacén">
                                    <select v-model="line.warehouse_id" aria-label="Almacén">
                                        <option value="">Lo elige quien emite</option>
                                        <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                    </select>
                                </td>
                                <td data-label="Notas"><input v-model="line.notes" type="text" maxlength="255" aria-label="Notas"></td>
                                <td class="remove-cell">
                                    <button type="button" class="btn btn-ghost remove-btn" aria-label="Quitar componente" title="Quitar componente" @click="removeLine(index)"><XIcon /></button>
                                </td>
                            </tr>
                            <tr v-if="!form.lines.length">
                                <td colspan="7" class="muted empty-row">
                                    La receta no tiene componentes todavía.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="form-actions lines-actions">
                <button type="button" class="btn btn-ghost" @click="addLine"><PlusIcon /> Agregar componente</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || selfReference || duplicated">
                    <SaveIcon /> Guardar componentes
                </button>
            </div>
        </form>

        <p class="hint small">
            La merma es lo que se pierde en el proceso: si de cada 100 se pierden 5, hay que emitir 105 para que
            queden 100. Sin declararla, la orden cierra con una desviación sistemática que parece un error.
        </p>

        <template v-if="preview.length">
            <h2 class="block-title">Con qué se cuenta para un lote de {{ quantity(bom.output_quantity) }}</h2>
            <div class="card">
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Componente</th>
                                <th class="num">Hace falta</th>
                                <th class="num">Hay</th>
                                <th class="num">Falta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in preview" :key="p.component_item_id">
                                <td><strong class="item-code">{{ p.item_code }}</strong> — {{ p.item_name }}</td>
                                <td data-label="Hace falta" class="num">{{ quantity(p.required_quantity) }} {{ p.uom }}</td>
                                <td data-label="Hay" class="num muted">{{ quantity(p.on_hand) }}</td>
                                <td data-label="Falta" class="num" :class="{ short: Number(p.shortage) > 0 }">
                                    {{ Number(p.shortage) > 0 ? quantity(p.shortage) : '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.84rem; }
.item-code { font-variant-numeric: tabular-nums; }
.short { color: var(--color-danger); font-weight: 600; }

/* Anchos fijos para lo que tiene un largo conocido: la grilla cabe en los
   ~720px que deja la barra lateral a 1025px, y el componente y las notas se
   quedan con el resto. */
.lines-table { table-layout: fixed; }
.lines-table th, .lines-table td { padding: 0.4rem 0.5rem; }
.col-qty { width: 7rem; }
.col-scrap { width: 5.5rem; }
.col-emit { width: 6rem; }
.col-warehouse { width: 8.5rem; }
.col-notes { width: 18%; }
.col-remove { width: 3rem; }
.lines-table select,
.lines-table input { width: 100%; min-width: 0; }
.num-input { text-align: right; }
.remove-btn { width: 2.25rem; padding: 0; }
.lines-actions { justify-content: space-between; margin-bottom: 0.75rem; }

@media screen and (max-width: 1024px) {
    .remove-cell { justify-content: flex-end; }
}
</style>
