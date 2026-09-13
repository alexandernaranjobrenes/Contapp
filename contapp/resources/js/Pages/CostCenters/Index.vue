<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import LedgerPanel from '../../Components/LedgerPanel.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    costCenters: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');
const today = new Date().toISOString().slice(0, 10);

const sorted = computed(() => [...props.costCenters].sort((a, b) => a.code.localeCompare(b.code)));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return sorted.value;

    return sorted.value.filter((c) =>
        c.code.toLowerCase().includes(q) || c.name.toLowerCase().includes(q)
    );
});

function isVigenteHoy(c) {
    if (! c.is_active) return false;
    if (c.start_date > today) return false;
    if (c.end_date && c.end_date < today) return false;
    return true;
}

// --- crear ---

const creating = ref(false);

const createForm = useForm({
    code: '',
    name: '',
    start_date: today,
    end_date: '',
    is_active: true,
});

function openCreate() {
    createForm.reset();
    createForm.start_date = today;
    createForm.is_active = true;
    creating.value = true;
}

function closeCreate() {
    creating.value = false;
}

function submitCreate() {
    createForm.post(route('cost-centers.store'), { onSuccess: closeCreate, preserveScroll: true });
}

// --- editar ---

const editing = ref(null);

const editForm = useForm({
    name: '',
    start_date: '',
    end_date: '',
    is_active: true,
});

function openEdit(costCenter) {
    editForm.clearErrors();
    editForm.name = costCenter.name;
    editForm.start_date = costCenter.start_date;
    editForm.end_date = costCenter.end_date ?? '';
    editForm.is_active = costCenter.is_active;
    editing.value = costCenter;
}

function closeEdit() {
    editing.value = null;
}

function submitEdit() {
    editForm.put(route('cost-centers.update', editing.value.id), { onSuccess: closeEdit, preserveScroll: true });
}

function destroy(costCenter) {
    if (! confirm(`¿Eliminar el centro de costo ${costCenter.code} — ${costCenter.name}?`)) return;

    router.delete(route('cost-centers.destroy', costCenter.id), { preserveScroll: true });
}

const ledger = ref({ open: false, ownerId: null, ownerLabel: '' });

function openLedger(costCenter) {
    ledger.value = { open: true, ownerId: costCenter.id, ownerLabel: `${costCenter.code} — ${costCenter.name}` };
}

function closeLedger() {
    ledger.value.open = false;
}
</script>

<template>
    <Head title="Centros de costo" />

    <AppLayout title="Centros de costo">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.cost_center" class="flash flash-error">{{ page.props.errors.cost_center }}</div>

        <p class="hint">
            Un centro de costo recibe montos vía una <strong>norma de reparto</strong> — ya no se elige directo en una
            línea de asiento. Ver "Normas de reparto" para configurar cómo se distribuye cada gasto/costo entre ellos.
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} centro(s) de costo</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nuevo centro de costo</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Vigencia</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in filtered" :key="c.id" class="clickable-row" title="Ver movimientos y saldo" @click="openLedger(c)">
                        <td class="num code-cell">{{ c.code }}</td>
                        <td>{{ c.name }}</td>
                        <td class="muted small num">{{ c.start_date }} — {{ c.end_date ?? 'sin fin' }}</td>
                        <td>
                            <span class="badge" :class="isVigenteHoy(c) ? 'badge-success' : 'badge-neutral'">
                                {{ isVigenteHoy(c) ? 'Vigente' : (c.is_active ? 'Fuera de vigencia' : 'Inactivo') }}
                            </span>
                        </td>
                        <td class="actions-cell" @click.stop>
                            <button type="button" class="btn btn-ghost" @click="openEdit(c)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(c)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay centros de costo registrados.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Nuevo -->
        <div v-if="creating" class="modal-backdrop" @click.self="closeCreate">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nuevo centro de costo</h2>

                <div class="field">
                    <label>Código</label>
                    <input v-model="createForm.code" type="text" maxlength="20" required>
                    <span v-if="createForm.errors.code" class="error">{{ createForm.errors.code }}</span>
                </div>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="createForm.name" type="text" required>
                    <span v-if="createForm.errors.name" class="error">{{ createForm.errors.name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="createForm.start_date" type="date" required>
                        <span v-if="createForm.errors.start_date" class="error">{{ createForm.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label>Vigente hasta (opcional)</label>
                        <input v-model="createForm.end_date" type="date">
                        <span v-if="createForm.errors.end_date" class="error">{{ createForm.errors.end_date }}</span>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="createForm.is_active" type="checkbox">
                    Activo
                </label>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeCreate">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Editar -->
        <div v-if="editing" class="modal-backdrop" @click.self="closeEdit">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar {{ editing.code }}</h2>
                <p class="muted small">El código no se puede cambiar una vez creado el centro de costo.</p>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="editForm.start_date" type="date" required>
                        <span v-if="editForm.errors.start_date" class="error">{{ editForm.errors.start_date }}</span>
                    </div>
                    <div class="field">
                        <label>Vigente hasta (opcional)</label>
                        <input v-model="editForm.end_date" type="date">
                        <span v-if="editForm.errors.end_date" class="error">{{ editForm.errors.end_date }}</span>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="editForm.is_active" type="checkbox">
                    Activo
                </label>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeEdit">Cancelar</button>
                </div>
            </form>
        </div>

        <LedgerPanel
            :open="ledger.open"
            dimension="cost-center"
            :owner-id="ledger.ownerId"
            :owner-label="ledger.ownerLabel"
            @close="closeLedger"
        />
    </AppLayout>
</template>

<style scoped>
.search-input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.6rem;
    font-size: 0.82rem;
    width: 220px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.85rem 1.1rem;
    border-bottom: 1px solid var(--color-border);
}

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.hint {
    font-size: 0.82rem;
    color: var(--color-text-muted);
    margin: -0.5rem 0 1rem;
}

table { font-size: 0.85rem; width: 100%; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); white-space: nowrap; }
.code-cell { font-variant-numeric: tabular-nums; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; white-space: normal; }
.actions-cell { display: flex; gap: 0.4rem; }
.clickable-row { cursor: pointer; }
.clickable-row:hover { background: var(--color-primary-soft); }

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

.modal-card {
    width: 460px;
    max-width: 100%;
    max-height: 90vh;
    overflow-y: auto;
    padding: 1.5rem;
}

.modal-card h2 { font-size: 1rem; margin: 0 0 0.5rem; }

.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 1rem; }

.field {
    display: flex;
    flex-direction: column;
    gap: 0.2rem;
    margin-bottom: 0.75rem;
}

.field label {
    font-size: 0.78rem;
    color: var(--color-text-muted);
}

.field input, .field select {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}

.error {
    color: var(--color-danger);
    font-size: 0.76rem;
}

.check-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    margin: 0.5rem 0 1rem;
}

.modal-actions { display: flex; gap: 0.6rem; }
</style>
