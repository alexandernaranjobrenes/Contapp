<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeftIcon, PencilIcon, PlusIcon, RouteIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

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

// Ficha, alta y edición del lote en un solo modal (CLAUDE.md secc. 20 y 21).
// El número solo se elige al crear: es la identidad con la que el lote queda
// grabado en el kardex.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.lots,
    defaults: () => ({ code: '', expires_at: '', status: 'active', notes: '' }),
    toForm: (l) => ({ code: l.code, expires_at: l.expires_at ?? '', status: l.status, notes: l.notes ?? '' }),
    store: () => route('item-lots.store', props.item.id),
    update: (l) => route('item-lots.update', [props.item.id, l.id]),
    updatePayload: ({ expires_at, status, notes }) => ({ expires_at, status, notes }),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nuevo lote';
    return selected.value ? `Lote ${selected.value.code}` : '';
});

function destroy() {
    const l = selected.value;

    confirmAction({
        title: 'Eliminar lote',
        message: `El lote ${l.code} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('item-lots.destroy', [props.item.id, l.id]), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="`Lotes de ${item.code}`" />

    <AppLayout :title="`Lotes — ${item.code} ${item.name}`">
        <div class="view-toolbar">
            <Link :href="route('items.index')" class="btn btn-ghost"><ArrowLeftIcon /> Artículos</Link>
            <div class="view-actions">
                <Link :href="route('lot-expiry.index')" class="btn btn-ghost">Próximos a vencer</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Lote</th>
                            <th>Vencimiento</th>
                            <th class="num">Existencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in lots"
                            :key="l.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(l)"
                            @keydown.enter="openDetail(l)"
                            @keydown.space.prevent="openDetail(l)"
                        >
                            <td class="code-cell">{{ l.code }}</td>
                            <td data-label="Vencimiento">
                                <span class="badge" :class="expiryClass(l)">{{ expiryLabel(l) }}</span>
                            </td>
                            <td data-label="Existencia" class="num">{{ quantity(l.on_hand) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="l.status === 'active' ? 'badge-success' : 'badge-warning'">
                                    {{ l.status === 'active' ? 'Activo' : 'Retenido' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!lots.length">
                            <td colspan="4" class="muted empty-row">Este artículo todavía no tiene lotes.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-warning'">
                    {{ selected.status === 'active' ? 'Activo' : 'Retenido' }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Vencimiento</dt>
                    <dd><span class="badge" :class="expiryClass(selected)">{{ expiryLabel(selected) }}</span></dd>
                </div>
                <div>
                    <dt>Existencia</dt>
                    <dd>{{ quantity(selected.on_hand) }}</dd>
                </div>
                <div class="full">
                    <dt>Notas</dt>
                    <dd>{{ selected.notes ?? '—' }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="lot-form" @submit.prevent="submit">
                <p v-if="mode === 'edit'" class="muted small">
                    El número no se puede cambiar: es la identidad con la que el lote ya quedó grabado en el kardex.
                </p>

                <div v-if="mode === 'create'" class="field">
                    <label for="lot-code">Número de lote</label>
                    <input id="lot-code" v-model="form.code" type="text" maxlength="40" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="lot-expires">Vencimiento (opcional)</label>
                    <input id="lot-expires" v-model="form.expires_at" type="date">
                    <span class="muted small">Dejalo en blanco si el producto no caduca.</span>
                </div>

                <div class="field">
                    <label for="lot-status">Estado</label>
                    <select id="lot-status" v-model="form.status">
                        <option value="active">Activo</option>
                        <option value="blocked">Retenido</option>
                    </select>
                    <span class="muted small">
                        Un lote retenido sigue contando en el inventario, pero no se puede vender ni consumir en producción.
                    </span>
                </div>

                <div class="field">
                    <label for="lot-notes">Notas (opcional)</label>
                    <input id="lot-notes" v-model="form.notes" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('item-lots.trace', [item.id, selected.id])" class="btn btn-ghost"><RouteIcon /> Trazabilidad</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="lot-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>
