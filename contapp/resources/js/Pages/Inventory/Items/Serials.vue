<script setup>
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { ArrowLeftIcon, PencilIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';

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

const STATUS_BADGES = { in_stock: 'badge-success', issued: 'badge-neutral', scrapped: 'badge-danger' };

// Ficha de la serie (CLAUDE.md secc. 20): se edita la garantía y las notas
// —lo que el movimiento no sabe— dentro del mismo modal.
const selectedId = ref(null);
const mode = ref('details'); // 'details' | 'edit'
const selected = computed(() => rows.value.find((s) => s.id === selectedId.value) ?? null);

function openSerial(serial) {
    selectedId.value = serial.id;
    mode.value = 'details';
}

function closeSerial() {
    selectedId.value = null;
    mode.value = 'details';
}

const editForm = useForm({ warranty_until: '', notes: '' });

function startEdit() {
    editForm.clearErrors();
    editForm.warranty_until = selected.value.warranty_until ?? '';
    editForm.notes = selected.value.notes ?? '';
    mode.value = 'edit';
}

function submitEdit() {
    editForm.transform((data) => ({
        ...data,
        warranty_until: data.warranty_until === '' ? null : data.warranty_until,
        notes: data.notes === '' ? null : data.notes,
    })).put(route('item-serials.update', [props.item.id, selected.value.id]), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function scrap() {
    const serial = selected.value;

    confirmAction({
        title: 'Dar de baja la serie',
        message: `La serie ${serial.serial_number} queda marcada como dada de baja.`,
        confirmLabel: 'Dar de baja',
        danger: true,
        onConfirm: () => router.post(route('item-serials.scrap', [props.item.id, serial.id]), {}, { preserveScroll: true }),
    });
}
</script>

<template>
    <Head :title="'Series — ' + item.code" />

    <AppLayout :title="'Series — ' + item.code + ' ' + item.name">
        <div class="view-toolbar">
            <Link :href="route('items.index')" class="btn btn-ghost"><ArrowLeftIcon /> Artículos</Link>
            <div class="view-filters">
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar número de serie..."
                    aria-label="Buscar número de serie"
                    @input="onSearchInput"
                >
                <select v-model="status" aria-label="Estado" @change="applyFilters">
                    <option value="">Todos los estados</option>
                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                </select>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Serie</th>
                            <th>Estado</th>
                            <th>Almacén</th>
                            <th>Garantía hasta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in rows"
                            :key="s.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openSerial(s)"
                            @keydown.enter="openSerial(s)"
                            @keydown.space.prevent="openSerial(s)"
                        >
                            <td class="serial-cell">{{ s.serial_number }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="STATUS_BADGES[s.status] ?? 'badge-neutral'">{{ statuses[s.status] ?? s.status }}</span>
                            </td>
                            <td data-label="Almacén">{{ s.warehouse?.code ?? '—' }}<template v-if="s.bin"> / {{ s.bin.code }}</template></td>
                            <td data-label="Garantía hasta">{{ s.warranty_until ?? '—' }}</td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="4" class="muted empty-row">
                                No hay series registradas. Aparecen al recibir mercancía de este artículo.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav v-if="serials.last_page > 1" class="pagination" aria-label="Páginas">
                <Link
                    v-for="link in serials.links"
                    :key="link.label"
                    :href="link.url ?? ''"
                    class="page-link"
                    :class="{ active: link.active, disabled: !link.url }"
                    preserve-scroll
                    v-html="link.label"
                />
            </nav>
        </div>

        <DetailModal :open="!!selected" :title="selected ? `Serie ${selected.serial_number}` : ''" @close="closeSerial">
            <template #badge>
                <span v-if="selected" class="badge" :class="STATUS_BADGES[selected.status] ?? 'badge-neutral'">{{ statuses[selected.status] ?? selected.status }}</span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Almacén</dt>
                    <dd>{{ selected.warehouse?.code ?? '—' }}<template v-if="selected.bin"> / {{ selected.bin.code }}</template></dd>
                </div>
                <div>
                    <dt>Lote</dt>
                    <dd>{{ selected.lot?.code ?? '—' }}</dd>
                </div>
                <div>
                    <dt>Entró con</dt>
                    <dd>{{ selected.received_document?.number ?? '—' }}<template v-if="selected.received_at"> · {{ selected.received_at }}</template></dd>
                </div>
                <div>
                    <dt>Salió con</dt>
                    <dd>
                        <template v-if="selected.issued_document">
                            {{ selected.issued_document.number }}
                            <template v-if="selected.issued_document.business_partner?.name"> · {{ selected.issued_document.business_partner.name }}</template>
                            <template v-if="selected.issued_at"> · {{ selected.issued_at }}</template>
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div>
                    <dt>Garantía hasta</dt>
                    <dd>{{ selected.warranty_until ?? '—' }}</dd>
                </div>
                <div class="full">
                    <dt>Notas</dt>
                    <dd>{{ selected.notes || '—' }}</dd>
                </div>
            </dl>

            <form v-if="selected && mode === 'edit'" id="serial-form" @submit.prevent="submitEdit">
                <div class="field">
                    <label for="serial-warranty">Garantía hasta</label>
                    <input id="serial-warranty" v-model="editForm.warranty_until" type="date">
                    <span class="muted small">
                        Sin fecha significa que no se registró cobertura, no que la garantía sea indefinida.
                    </span>
                </div>

                <div class="field">
                    <label for="serial-notes">Notas</label>
                    <input id="serial-notes" v-model="editForm.notes" type="text" maxlength="255">
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button v-if="selected.status === 'issued'" type="button" class="btn btn-ghost btn-danger-text" @click="scrap">Dar de baja</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="selected">
                    <button type="button" class="btn btn-ghost" @click="mode = 'details'">Cancelar</button>
                    <button type="submit" form="serial-form" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.serial-cell { font-weight: 700; font-variant-numeric: tabular-nums; }
.reconciliation { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.7rem 1.1rem; margin-bottom: 0.75rem; font-size: 0.85rem; flex-wrap: wrap; }
.reconciliation.off { background: var(--color-danger-soft); color: var(--color-danger); }
.ok { font-weight: 600; color: var(--color-success); }
.off-label { font-weight: 600; }
</style>
