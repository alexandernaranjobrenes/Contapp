<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    item: { type: Object, required: true },
    serials: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    reconciliation: { type: Object, required: true },
});

const page = usePage();

const search = ref(props.filters?.search ?? '');
const status = ref(props.filters?.status ?? '');

let searchTimer = null;

function applyFilters() {
    router.get(route('item-serials.index', props.item.id), {
        search: search.value.trim() === '' ? undefined : search.value.trim(),
        status: status.value === '' ? undefined : status.value,
    }, { preserveState: true, replace: true, preserveScroll: true });
}

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
}

const rows = computed(() => props.serials.data ?? []);

// El invariante de la capa: tantas series en existencia como existencia del
// artículo. Si no cuadra hay una unidad que el inventario cree tener.
const balanced = computed(
    () => props.reconciliation.serials_in_stock === Math.round(props.reconciliation.on_hand)
);

const editing = ref(null);
const editForm = useForm({ warranty_until: '', notes: '' });

function openEdit(serial) {
    editForm.clearErrors();
    editForm.warranty_until = serial.warranty_until ?? '';
    editForm.notes = serial.notes ?? '';
    editing.value = serial;
}

function submitEdit() {
    editForm.transform((data) => ({
        ...data,
        warranty_until: data.warranty_until === '' ? null : data.warranty_until,
        notes: data.notes === '' ? null : data.notes,
    })).put(route('item-serials.update', [props.item.id, editing.value.id]), {
        onSuccess: () => (editing.value = null), preserveScroll: true,
    });
}

function scrap(serial) {
    if (! confirm(`¿Marcar la serie ${serial.serial_number} como dada de baja?`)) return;

    router.post(route('item-serials.scrap', [props.item.id, serial.id]), {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="'Series — ' + item.code" />

    <AppLayout :title="'Series — ' + item.code + ' ' + item.name">
        <template #actions>
            <input
                v-model="search"
                type="search"
                placeholder="Buscar número de serie..."
                class="search-input"
                @input="onSearchInput"
            >
            <select v-model="status" class="search-input" @change="applyFilters">
                <option value="">Todos los estados</option>
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
            <Link :href="route('items.index')" class="btn btn-ghost">Volver a artículos</Link>
        </template>

        <div v-if="page.props.errors?.serial" class="flash flash-error">{{ page.props.errors.serial }}</div>

        <p v-if="!item.tracks_serials" class="flash flash-warning">
            Este artículo ya no maneja números de serie. Lo que se ve acá es el histórico de las unidades que
            se registraron mientras los manejaba.
        </p>

        <div class="card reconciliation" :class="{ off: !balanced }">
            <div>
                <strong>{{ reconciliation.serials_in_stock }}</strong> serie(s) en existencia ·
                existencia del artículo: <strong>{{ reconciliation.on_hand }}</strong>
            </div>
            <span v-if="balanced" class="ok">Cuadra</span>
            <span v-else class="off-label">
                No cuadra: hay una unidad que el inventario cree tener y no está en el maestro, o al revés.
            </span>
        </div>

        <p class="hint">
            Las series no se dan de alta acá: nacen con la entrada de mercancía que las trajo, igual que la
            existencia. Se edita la garantía y las notas, que son lo que el movimiento no sabe.
        </p>

        <div class="card">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Serie</th>
                            <th>Estado</th>
                            <th>Almacén</th>
                            <th>Lote</th>
                            <th>Entró con</th>
                            <th>Salió con</th>
                            <th>Garantía hasta</th>
                            <th>Notas</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in rows" :key="s.id">
                            <td class="num"><strong>{{ s.serial_number }}</strong></td>
                            <td>
                                <span class="badge" :class="'badge-' + s.status">{{ statuses[s.status] ?? s.status }}</span>
                            </td>
                            <td>{{ s.warehouse?.code ?? '—' }}<template v-if="s.bin"> / {{ s.bin.code }}</template></td>
                            <td class="muted">{{ s.lot?.code ?? '—' }}</td>
                            <td class="muted small">
                                {{ s.received_document?.number ?? '—' }}
                                <span v-if="s.received_at" class="block">{{ s.received_at }}</span>
                            </td>
                            <td class="muted small">
                                <template v-if="s.issued_document">
                                    {{ s.issued_document.number }}
                                    <span class="block">
                                        {{ s.issued_document.business_partner?.name ?? '' }}
                                    </span>
                                    <span v-if="s.issued_at" class="block">{{ s.issued_at }}</span>
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td>{{ s.warranty_until ?? '—' }}</td>
                            <td class="muted small">{{ s.notes ?? '' }}</td>
                            <td class="row-actions">
                                <button type="button" class="btn btn-ghost btn-sm" @click="openEdit(s)">Editar</button>
                                <button
                                    v-if="s.status === 'issued'"
                                    type="button" class="btn btn-ghost btn-sm" @click="scrap(s)"
                                >Dar de baja</button>
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="9" class="muted empty-row">
                                No hay series registradas. Aparecen al recibir mercancía de este artículo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="serials.last_page > 1" class="pagination">
            <Link
                v-for="link in serials.links"
                :key="link.label"
                :href="link.url ?? ''"
                class="page-link"
                :class="{ active: link.active, disabled: !link.url }"
                preserve-scroll
                v-html="link.label"
            />
        </div>

        <div v-if="editing" class="modal-backdrop" @click.self="editing = null">
            <form class="modal card" @submit.prevent="submitEdit">
                <h2>Serie {{ editing.serial_number }}</h2>

                <div class="field">
                    <label>Garantía hasta</label>
                    <input v-model="editForm.warranty_until" type="date">
                    <span class="hint small">
                        Sin fecha significa que no se registró cobertura, no que la garantía sea indefinida.
                    </span>
                </div>

                <div class="field">
                    <label>Notas</label>
                    <input v-model="editForm.notes" type="text" maxlength="255">
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-ghost" @click="editing = null">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.num { font-variant-numeric: tabular-nums; }
.small { font-size: 0.76rem; }
.block { display: block; }
.hint { color: var(--color-text-muted); font-size: 0.82rem; margin: 0 0 0.75rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.row-actions { display: flex; gap: 0.3rem; justify-content: flex-end; }
.reconciliation { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.7rem 1.1rem; margin-bottom: 0.75rem; font-size: 0.85rem; flex-wrap: wrap; }
.reconciliation.off { background: var(--color-danger-soft); color: var(--color-danger); }
.ok { font-weight: 600; color: var(--color-success, #1a7f4b); }
.off-label { font-weight: 600; }
.badge { display: inline-block; font-size: 0.68rem; padding: 0.1rem 0.4rem; border-radius: 3px; font-weight: 600; }
.badge-in_stock { background: #e8f3ec; color: #1a7f4b; }
.badge-issued { background: #e8eef7; color: #0B1F3A; }
.badge-scrapped { background: #fdf0ea; color: #a04000; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 1rem; }
.modal { width: min(440px, 100%); padding: 1.2rem; }
.modal h2 { margin: 0 0 0.8rem; font-size: 1rem; }
.field { display: flex; flex-direction: column; gap: 0.2rem; margin-bottom: 0.7rem; }
.field label { font-size: 0.78rem; font-weight: 600; }
.modal-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.8rem; }
.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }
.flash-warning { background: #fdf0ea; color: #a04000; }
.pagination { display: flex; gap: 0.25rem; margin-top: 0.75rem; flex-wrap: wrap; }
.page-link { padding: 0.25rem 0.55rem; border-radius: var(--radius-sm); font-size: 0.8rem; }
.page-link.active { background: var(--color-primary, #0B1F3A); color: #fff; }
.page-link.disabled { opacity: 0.4; pointer-events: none; }
</style>
