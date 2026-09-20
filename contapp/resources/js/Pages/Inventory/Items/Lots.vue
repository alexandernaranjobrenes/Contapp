<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
    lots: { type: Array, default: () => [] },
});

const page = usePage();

function quantity(value) {
    return Number(value ?? 0).toLocaleString('es-CR', { minimumFractionDigits: 0, maximumFractionDigits: 6 });
}

/**
 * El "vencido" ya viene resuelto del servidor (is_expired); acá solo se
 * traduce la cercanía a una etiqueta, para no repetir en el componente una
 * comparación de fechas que el backend ya hizo.
 */
function expiryLabel(lot) {
    if (lot.expires_at === null) return 'Sin vencimiento';
    if (lot.is_expired) return `Vencido el ${lot.expires_at}`;
    if (lot.days_to_expiry <= 30) return `${lot.expires_at} (en ${lot.days_to_expiry} días)`;
    return lot.expires_at;
}

function expiryClass(lot) {
    if (lot.expires_at === null) return 'badge-neutral';
    if (lot.is_expired) return 'badge-danger';
    if (lot.days_to_expiry <= 30) return 'badge-warning';
    return 'badge-success';
}

const creating = ref(false);
const createForm = useForm({ code: '', expires_at: '', status: 'active', notes: '' });

function openCreate() {
    createForm.reset();
    creating.value = true;
}

function submitCreate() {
    createForm.post(route('item-lots.store', props.item.id), {
        onSuccess: () => (creating.value = false),
        preserveScroll: true,
    });
}

const editing = ref(null);
const editForm = useForm({ expires_at: '', status: 'active', notes: '' });

function openEdit(lot) {
    editForm.clearErrors();
    editForm.expires_at = lot.expires_at ?? '';
    editForm.status = lot.status;
    editForm.notes = lot.notes ?? '';
    editing.value = lot;
}

function submitEdit() {
    editForm.put(route('item-lots.update', [props.item.id, editing.value.id]), {
        onSuccess: () => (editing.value = null),
        preserveScroll: true,
    });
}

function destroy(lot) {
    if (! confirm(`¿Eliminar el lote ${lot.code}?`)) return;

    router.delete(route('item-lots.destroy', [props.item.id, lot.id]), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Lotes de ${item.code}`" />

    <AppLayout :title="`Lotes — ${item.code} ${item.name}`">
        <template #actions>
            <Link :href="route('lot-expiry.index')" class="btn btn-ghost">Próximos a vencer</Link>
            <Link :href="route('items.index')" class="btn btn-ghost">Volver a artículos</Link>
        </template>

        <div v-if="page.props.errors?.lot" class="flash flash-error">{{ page.props.errors.lot }}</div>

        <p v-if="!item.tracks_lots" class="flash flash-warning">
            Este artículo todavía no tiene activado el manejo por lotes, así que los movimientos no los van a pedir.
            Activalo desde la ficha del artículo cuando los lotes estén creados.
        </p>

        <p class="hint">
            Los lotes son una capa de <strong>trazabilidad</strong>: dicen de dónde viene cada unidad y cuándo vence,
            no cuánto vale. El costo promedio sigue siendo global por artículo, así que dos lotes del mismo artículo
            salen al mismo costo.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ lots.length }} lote(s)</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo lote</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Lote</th>
                        <th>Vencimiento</th>
                        <th class="right">Existencia</th>
                        <th>Estado</th>
                        <th>Notas</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="l in lots" :key="l.id">
                        <td class="num code-cell">{{ l.code }}</td>
                        <td>
                            <span class="badge" :class="expiryClass(l)">{{ expiryLabel(l) }}</span>
                        </td>
                        <td class="num right">{{ quantity(l.on_hand) }}</td>
                        <td>
                            <span class="badge" :class="l.status === 'active' ? 'badge-success' : 'badge-warning'">
                                {{ l.status === 'active' ? 'Activo' : 'Retenido' }}
                            </span>
                        </td>
                        <td class="muted small">{{ l.notes ?? '—' }}</td>
                        <td class="actions-cell">
                            <Link :href="route('item-lots.trace', [item.id, l.id])" class="btn btn-ghost">Trazabilidad</Link>
                            <button type="button" class="btn btn-ghost" @click="openEdit(l)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(l)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!lots.length">
                        <td colspan="6" class="muted empty-row">Este artículo todavía no tiene lotes.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="creating" class="modal-backdrop" @click.self="creating = false">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nuevo lote</h2>

                <div class="field">
                    <label>Número de lote</label>
                    <input v-model="createForm.code" type="text" maxlength="40" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Vencimiento (opcional)</label>
                    <input v-model="createForm.expires_at" type="date">
                    <span class="hint small">Dejalo en blanco si el producto no caduca.</span>
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="createForm.status">
                        <option value="active">Activo</option>
                        <option value="blocked">Retenido</option>
                    </select>
                    <span class="hint small">
                        Un lote retenido sigue contando en el inventario, pero no se puede vender ni consumir en producción.
                    </span>
                </div>

                <div class="field">
                    <label>Notas (opcional)</label>
                    <input v-model="createForm.notes" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                </div>
            </form>
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar lote {{ editing.code }}</h2>
                <p class="muted small">
                    El número no se puede cambiar: es la identidad con la que el lote ya quedó grabado en el kardex.
                </p>

                <div class="field">
                    <label>Vencimiento</label>
                    <input v-model="editForm.expires_at" type="date">
                </div>

                <div class="field">
                    <label>Estado</label>
                    <select v-model="editForm.status">
                        <option value="active">Activo</option>
                        <option value="blocked">Retenido</option>
                    </select>
                </div>

                <div class="field">
                    <label>Notas</label>
                    <input v-model="editForm.notes" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="editing = null">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
