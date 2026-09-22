<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../../Components/DocumentToolbar.vue';

const props = defineProps({
    priceLists: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const page = usePage();

const blank = {
    code: '',
    name: '',
    currency_id: '',
    prices_include_tax: false,
    valid_from: '',
    valid_to: '',
    is_default: false,
    status: 'active',
};

const creating = ref(false);
const createForm = useForm({ ...blank });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

const editing = ref(null);
const editForm = useForm({ ...blank });

const activeForm = computed(() => (creating.value ? createForm : editForm));

function openEdit(list) {
    editForm.clearErrors();
    editForm.name = list.name;
    editForm.currency_id = list.currency_id;
    editForm.prices_include_tax = list.prices_include_tax;
    editForm.valid_from = list.valid_from ?? '';
    editForm.valid_to = list.valid_to ?? '';
    editForm.is_default = list.is_default;
    editForm.status = list.status;
    editing.value = list;
}

// Vacío tiene que viajar como null: '' lo tomaría como una fecha inválida en
// vez de "sin límite".
function normalize(data) {
    return {
        ...data,
        valid_from: data.valid_from === '' ? null : data.valid_from,
        valid_to: data.valid_to === '' ? null : data.valid_to,
    };
}

function submitCreate() {
    createForm.transform(normalize).post(route('price-lists.store'), {
        onSuccess: () => (creating.value = false), preserveScroll: true,
    });
}

function submitEdit() {
    editForm.transform(normalize).put(route('price-lists.update', editing.value.id), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function destroy(list) {
    if (! confirm(`¿Eliminar la lista ${list.code} — ${list.name}?`)) return;

    router.delete(route('price-lists.destroy', list.id), { preserveScroll: true });
}

const hasDefault = computed(() => props.priceLists.some((l) => l.is_default && l.status === 'active'));
</script>

<template>
    <Head title="Listas de precios" />

    <AppLayout title="Listas de precios">
        <template #actions>
            <Link :href="route('items.index')" class="btn btn-ghost">Artículos</Link>
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.price_list" class="flash flash-error">{{ page.props.errors.price_list }}</div>

        <p v-if="priceLists.length && !hasDefault" class="flash flash-warning">
            Ninguna lista está marcada como predeterminada. Los clientes que no tengan una asignada van a
            facturar sin precio sugerido.
        </p>

        <p class="hint">
            El precio es una decisión comercial y no tiene relación con el costo, que lo mantiene el motor de
            movimientos. Una lista se denomina en <strong>una moneda</strong> y no se convierte: convertir al
            tipo de cambio del día haría que el precio cambiara solo, todos los días.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ priceLists.length }} lista(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva lista</button>
            </div>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Moneda</th>
                            <th>Impuesto</th>
                            <th>Vigencia</th>
                            <th class="right">Artículos</th>
                            <th class="right">Clientes</th>
                            <th>Estado</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="l in priceLists" :key="l.id">
                            <td class="num">
                                {{ l.code }}
                                <span v-if="l.is_default" class="badge-default">predeterminada</span>
                            </td>
                            <td>{{ l.name }}</td>
                            <td>{{ l.currency_code }}</td>
                            <td class="muted small">{{ l.prices_include_tax ? 'Precios con IVA' : 'Precios sin IVA' }}</td>
                            <td class="muted small">
                                <template v-if="l.valid_from || l.valid_to">
                                    {{ l.valid_from ?? '—' }} a {{ l.valid_to ?? '—' }}
                                </template>
                                <template v-else>Sin límite</template>
                            </td>
                            <td class="right">{{ l.lines_count }}</td>
                            <td class="right">{{ l.customers_count }}</td>
                            <td>{{ l.status === 'active' ? 'Activa' : 'Inactiva' }}</td>
                            <td class="row-actions">
                                <Link :href="route('price-lists.prices', l.id)" class="btn btn-ghost btn-sm">Precios</Link>
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(l)">Editar</button>
                                <button type="button" class="btn btn-ghost btn-sm" @click="destroy(l)">Eliminar</button>
                            </td>
                        </tr>
                        <tr v-if="!priceLists.length">
                            <td colspan="9" class="muted empty-row">
                                Todavía no hay listas de precios. Sin una lista, cada precio se digita en la factura.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="creating || editing" class="modal-backdrop" @click.self="creating = false; editing = null">
            <form class="modal card" @submit.prevent="creating ? submitCreate() : submitEdit()">
                <h2>{{ creating ? 'Nueva lista de precios' : 'Editar ' + editing.code }}</h2>

                <div v-if="creating" class="field">
                    <label>Código</label>
                    <input v-model="createForm.code" type="text" maxlength="20" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="activeForm.name" type="text" maxlength="255" required>
                    <span v-if="activeForm.errors.name" class="error">{{ activeForm.errors.name }}</span>
                </div>

                <div class="field">
                    <label>Moneda</label>
                    <select v-model="activeForm.currency_id" required>
                        <option value="">Elegí una moneda</option>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <span class="hint small">
                        Una lista en colones no da precio a una factura en dólares: son dos listas distintas,
                        cada una con su precio decidido.
                    </span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="activeForm.valid_from" type="date">
                    </div>
                    <div class="field">
                        <label>Vigente hasta</label>
                        <input v-model="activeForm.valid_to" type="date">
                        <span v-if="activeForm.errors.valid_to" class="error">{{ activeForm.errors.valid_to }}</span>
                    </div>
                </div>
                <span class="hint small">
                    Dejalas vacías para una lista sin límite. Sirven para cargar el aumento de enero en
                    diciembre y que entre solo.
                </span>

                <label class="check">
                    <input v-model="activeForm.prices_include_tax" type="checkbox">
                    Los precios ya incluyen el IVA
                </label>

                <label class="check">
                    <input v-model="activeForm.is_default" type="checkbox">
                    Predeterminada (la usan los clientes sin lista propia)
                </label>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="activeForm.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="creating = false; editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="activeForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.right { text-align: right; }
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.row-actions { display: flex; gap: 0.3rem; justify-content: flex-end; }
.badge-default { display: inline-block; margin-left: 0.4rem; font-size: 0.65rem; padding: 0.05rem 0.3rem; border-radius: 3px; background: var(--color-primary-soft, #e8eef7); color: var(--color-primary, #0B1F3A); font-weight: 600; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
.modal { width: min(520px, 100%); max-height: 90vh; overflow-y: auto; padding: 1.2rem; }
.modal h2 { margin: 0 0 0.8rem; font-size: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.7rem; }
.field-row { display: flex; gap: 0.7rem; }
.field-row .field { flex: 1; }
.field label { font-size: 0.78rem; font-weight: 600; }
.check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; margin-bottom: 0.5rem; }
.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.8rem; }
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: #fdf0ea; color: #a04000; }
</style>
