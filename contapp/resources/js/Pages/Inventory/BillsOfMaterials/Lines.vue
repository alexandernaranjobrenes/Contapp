<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

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
        <template #actions>
            <Link :href="route('bills-of-materials.index')" class="btn btn-ghost">Volver a las recetas</Link>
        </template>

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

        <form class="card" @submit.prevent="submit">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Componente</th>
                            <th class="right">Cantidad por lote</th>
                            <th class="right">Merma %</th>
                            <th class="right">Se emite</th>
                            <th>Almacén</th>
                            <th>Notas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(line, index) in form.lines" :key="index">
                            <td>
                                <select v-model="line.component_item_id" required class="wide">
                                    <option value="">Elegí un artículo</option>
                                    <option v-for="i in items" :key="i.id" :value="i.id">{{ i.code }} — {{ i.name }}</option>
                                </select>
                            </td>
                            <td class="right">
                                <input v-model="line.quantity" type="number" step="0.000001" min="0.000001" required class="qty">
                            </td>
                            <td class="right">
                                <input v-model="line.scrap_percentage" type="number" step="0.0001" min="0" max="100" class="scrap">
                            </td>
                            <td class="right"><strong>{{ quantity(required(line)) }}</strong></td>
                            <td>
                                <select v-model="line.warehouse_id">
                                    <option value="">Lo elige quien emite</option>
                                    <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }}</option>
                                </select>
                            </td>
                            <td><input v-model="line.notes" type="text" maxlength="255"></td>
                            <td>
                                <button type="button" class="btn btn-ghost btn-sm" @click="removeLine(index)">Quitar</button>
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

            <div class="form-actions">
                <button type="button" class="btn btn-ghost" @click="addLine">+ Agregar componente</button>
                <button type="submit" class="btn btn-primary" :disabled="form.processing || selfReference || duplicated">
                    Guardar componentes
                </button>
            </div>
        </form>

        <p class="hint small">
            La merma es lo que se pierde en el proceso: si de cada 100 se pierden 5, hay que emitir 105 para que
            queden 100. Sin declararla, la orden cierra con una desviación sistemática que parece un error.
        </p>

        <div v-if="preview.length" class="card">
            <div class="card-header">
                <strong>Con qué se cuenta para un lote de {{ quantity(bom.output_quantity) }}</strong>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Componente</th>
                        <th class="right">Hace falta</th>
                        <th class="right">Hay</th>
                        <th class="right">Falta</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in preview" :key="p.component_item_id">
                        <td><strong class="num">{{ p.item_code }}</strong> — {{ p.item_name }}</td>
                        <td class="right">{{ quantity(p.required_quantity) }} {{ p.uom }}</td>
                        <td class="right muted">{{ quantity(p.on_hand) }}</td>
                        <td class="right" :class="{ short: Number(p.shortage) > 0 }">
                            {{ Number(p.shortage) > 0 ? quantity(p.shortage) : '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>

<style scoped>
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.qty, .scrap { width: 7rem; text-align: right; }
.wide { min-width: 14rem; }
.short { color: var(--color-danger); font-weight: 600; }
.form-actions { display: flex; align-items: center; justify-content: space-between; padding: 0.75rem 1.1rem; }
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
</style>
