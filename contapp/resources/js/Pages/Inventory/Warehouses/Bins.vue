<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    warehouse: { type: Object, required: true },
    bins: { type: Array, default: () => [] },
});

const page = usePage();

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

const creating = ref(false);
const createForm = useForm({ code: '', name: '', status: 'active' });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function submitCreate() {
    createForm.post(route('warehouse-bins.store', props.warehouse.id), {
        onSuccess: () => (creating.value = false),
        preserveScroll: true,
    });
}

const editing = ref(null);
const editForm = useForm({ name: '', status: 'active' });

function openEdit(bin) {
    editForm.clearErrors();
    editForm.name = bin.name ?? '';
    editForm.status = bin.status;
    editing.value = bin;
}

function submitEdit() {
    editForm.put(route('warehouse-bins.update', [props.warehouse.id, editing.value.id]), {
        onSuccess: () => (editing.value = null),
        preserveScroll: true,
    });
}

function destroy(bin) {
    if (! confirm(`¿Eliminar la ubicación ${bin.code}?`)) return;

    router.delete(route('warehouse-bins.destroy', [props.warehouse.id, bin.id]), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Ubicaciones de ${warehouse.code}`" />

    <AppLayout :title="`Ubicaciones — ${warehouse.code} ${warehouse.name}`">
        <template #actions>
            <Link :href="route('warehouses.index')" class="btn btn-ghost">Volver a almacenes</Link>
        </template>

        <div v-if="page.props.errors?.bin" class="flash flash-error">{{ page.props.errors.bin }}</div>

        <p v-if="!warehouse.uses_bins" class="flash flash-warning">
            Este almacén todavía no tiene activado el manejo por ubicaciones, así que los movimientos no las van a pedir.
            Activalo desde la ficha del almacén cuando las ubicaciones estén creadas.
        </p>

        <p class="hint">
            Las ubicaciones son una capa <strong>logística</strong>: dicen dónde está cada unidad, no cuánto vale. El costo
            promedio sigue siendo global por artículo, así que mover algo entre ubicaciones no genera asiento.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ bins.length }} ubicación(es)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva ubicación</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th class="right">Artículos</th>
                        <th class="right">Existencia</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="b in bins" :key="b.id">
                        <td class="num code-cell">{{ b.code }}</td>
                        <td>{{ b.name ?? '—' }}</td>
                        <td class="num right">{{ b.items_count }}</td>
                        <td class="num right">{{ quantity(b.on_hand) }}</td>
                        <td>
                            <span class="badge" :class="b.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                {{ b.status === 'active' ? 'Activa' : 'Inactiva' }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(b)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(b)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!bins.length">
                        <td colspan="6" class="muted empty-row">Este almacén todavía no tiene ubicaciones.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nueva ubicación</h2>

                <div class="field">
                    <label>Código (ej. A-01-03)</label>
                    <input v-model="createForm.code" type="text" maxlength="30" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre (opcional)</label>
                    <input v-model="createForm.name" type="text">
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="createForm.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar {{ editing.code }}</h2>
                <p class="muted small">El código no se puede cambiar una vez creada la ubicación.</p>

                <div class="field">
                    <label>Nombre (opcional)</label>
                    <input v-model="editForm.name" type="text">
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="editForm.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="editing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: var(--color-warning-soft); color: var(--color-warning); }

.hint { font-size: 0.82rem; color: var(--color-text-muted); margin: -0.25rem 0 1rem; }

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.right { text-align: right; }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(11, 31, 58, 0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 1rem;
}

.modal-card { width: 460px; max-width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; }
.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.75rem; }
.field label { font-size: 0.78rem; color: var(--color-text-muted); }

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error { color: var(--color-danger); font-size: 0.76rem; }
.modal-actions { display: flex; gap: 0.6rem; }
</style>
