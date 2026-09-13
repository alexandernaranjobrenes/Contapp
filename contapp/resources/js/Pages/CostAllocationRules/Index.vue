<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    rules: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
});

const page = usePage();
const search = ref('');
const today = new Date().toISOString().slice(0, 10);

const sorted = computed(() => [...props.rules].sort((a, b) => a.code.localeCompare(b.code)));

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (! q) return sorted.value;

    return sorted.value.filter((r) => r.code.toLowerCase().includes(q) || r.name.toLowerCase().includes(q));
});

function isVigenteHoy(r) {
    if (! r.is_active) return false;
    if (r.valid_from > today) return false;
    if (r.valid_until && r.valid_until < today) return false;
    return true;
}

function emptyLine() {
    return { cost_center_id: props.costCenters[0]?.id ?? null, percentage: '' };
}

// --- crear ---

const creating = ref(false);

const createForm = useForm({
    code: '',
    name: '',
    valid_from: today,
    valid_until: '',
    is_active: true,
    lines: [emptyLine(), emptyLine()],
});

function openCreate() {
    createForm.reset();
    createForm.valid_from = today;
    createForm.is_active = true;
    createForm.lines = [emptyLine(), emptyLine()];
    creating.value = true;
}

function closeCreate() {
    creating.value = false;
}

function addCreateLine() {
    createForm.lines.push(emptyLine());
}

function removeCreateLine(index) {
    if (createForm.lines.length > 1) createForm.lines.splice(index, 1);
}

const createTotal = computed(() =>
    createForm.lines.reduce((sum, l) => sum + (parseFloat(l.percentage) || 0), 0).toFixed(2)
);
const createIsBalanced = computed(() => createTotal.value === '100.00');

function submitCreate() {
    createForm.post(route('cost-allocation-rules.store'), { onSuccess: closeCreate, preserveScroll: true });
}

// --- editar ---

const editing = ref(null);

const editForm = useForm({
    code: '',
    name: '',
    valid_from: '',
    valid_until: '',
    is_active: true,
    lines: [],
});

function openEdit(rule) {
    editForm.clearErrors();
    editForm.code = rule.code;
    editForm.name = rule.name;
    editForm.valid_from = rule.valid_from;
    editForm.valid_until = rule.valid_until ?? '';
    editForm.is_active = rule.is_active;
    editForm.lines = rule.lines.map((l) => ({ cost_center_id: l.cost_center_id, percentage: l.percentage }));
    editing.value = rule;
}

function closeEdit() {
    editing.value = null;
}

function addEditLine() {
    editForm.lines.push(emptyLine());
}

function removeEditLine(index) {
    if (editForm.lines.length > 1) editForm.lines.splice(index, 1);
}

const editTotal = computed(() =>
    editForm.lines.reduce((sum, l) => sum + (parseFloat(l.percentage) || 0), 0).toFixed(2)
);
const editIsBalanced = computed(() => editTotal.value === '100.00');

function submitEdit() {
    editForm.put(route('cost-allocation-rules.update', editing.value.id), { onSuccess: closeEdit, preserveScroll: true });
}

function destroy(rule) {
    if (! confirm(`¿Eliminar la norma de reparto ${rule.code} — ${rule.name}?`)) return;

    router.delete(route('cost-allocation-rules.destroy', rule.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Normas de reparto" />

    <AppLayout title="Normas de reparto">
        <template #actions>
            <input v-model="search" type="search" placeholder="Buscar código o nombre..." class="search-input">
        </template>

        <DocumentToolbar can-create @new="openCreate()" />

        <div v-if="page.props.errors?.rule" class="flash flash-error">{{ page.props.errors.rule }}</div>

        <p class="hint">
            Una norma de reparto reparte el monto de una línea de asiento entre varios centros de costo, según el
            porcentaje configurado — las cuentas de costo/gasto que exigen centro de costo ahora eligen una norma en
            vez de un centro directo (ver "Nuevo asiento").
        </p>

        <div class="card">
            <div class="card-header">
                <span class="muted">{{ filtered.length }} norma(s) de reparto</span>
                <button type="button" class="btn btn-primary" @click="openCreate()">+ Nueva norma de reparto</button>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Reparto</th>
                        <th>Vigencia</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in filtered" :key="r.id">
                        <td class="num code-cell">{{ r.code }}</td>
                        <td>{{ r.name }}</td>
                        <td class="muted small">
                            {{ r.lines.map((l) => `${l.cost_center.code} ${l.percentage}%`).join(' · ') }}
                        </td>
                        <td class="muted small num">{{ r.valid_from }} — {{ r.valid_until ?? 'sin fin' }}</td>
                        <td>
                            <span class="badge" :class="isVigenteHoy(r) ? 'badge-success' : 'badge-neutral'">
                                {{ isVigenteHoy(r) ? 'Vigente' : (r.is_active ? 'Fuera de vigencia' : 'Inactiva') }}
                            </span>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(r)">Editar</button>
                            <button type="button" class="btn btn-ghost" @click="destroy(r)">Eliminar</button>
                        </td>
                    </tr>
                    <tr v-if="!filtered.length">
                        <td colspan="6" class="muted empty-row">Todavía no hay normas de reparto registradas.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Nueva -->
        <div v-if="creating" class="modal-backdrop" @click.self="closeCreate">
            <form class="modal-card card" @submit.prevent="submitCreate">
                <h2>Nueva norma de reparto</h2>

                <div class="grid-2">
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
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="createForm.valid_from" type="date" required>
                        <span v-if="createForm.errors.valid_from" class="error">{{ createForm.errors.valid_from }}</span>
                    </div>
                    <div class="field">
                        <label>Vigente hasta (opcional)</label>
                        <input v-model="createForm.valid_until" type="date">
                        <span v-if="createForm.errors.valid_until" class="error">{{ createForm.errors.valid_until }}</span>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="createForm.is_active" type="checkbox">
                    Activa
                </label>

                <div class="lines-editor">
                    <div class="lines-editor-header">
                        <span>Centro de costo</span>
                        <span>Porcentaje</span>
                        <span></span>
                    </div>
                    <div v-for="(line, index) in createForm.lines" :key="index" class="line-row">
                        <select v-model="line.cost_center_id" required>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <input v-model="line.percentage" type="number" step="0.01" min="0" max="100" required>
                        <button type="button" class="btn btn-ghost" :disabled="createForm.lines.length <= 1" @click="removeCreateLine(index)">✕</button>
                    </div>
                    <button type="button" class="btn btn-ghost" @click="addCreateLine">+ Centro de costo</button>
                    <p class="total-row">
                        Total: {{ createTotal }}%
                        <span class="badge" :class="createIsBalanced ? 'badge-success' : 'badge-danger'">
                            {{ createIsBalanced ? 'Cuadrado' : 'No cuadra' }}
                        </span>
                    </p>
                    <span v-if="createForm.errors.lines" class="error">{{ createForm.errors.lines }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="createForm.processing || !createIsBalanced">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeCreate">Cancelar</button>
                </div>
            </form>
        </div>

        <!-- Editar -->
        <div v-if="editing" class="modal-backdrop" @click.self="closeEdit">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar {{ editing.code }}</h2>

                <div class="field">
                    <label>Nombre</label>
                    <input v-model="editForm.name" type="text" required>
                    <span v-if="editForm.errors.name" class="error">{{ editForm.errors.name }}</span>
                </div>

                <div class="grid-2">
                    <div class="field">
                        <label>Vigente desde</label>
                        <input v-model="editForm.valid_from" type="date" required>
                        <span v-if="editForm.errors.valid_from" class="error">{{ editForm.errors.valid_from }}</span>
                    </div>
                    <div class="field">
                        <label>Vigente hasta (opcional)</label>
                        <input v-model="editForm.valid_until" type="date">
                        <span v-if="editForm.errors.valid_until" class="error">{{ editForm.errors.valid_until }}</span>
                    </div>
                </div>

                <label class="check-row">
                    <input v-model="editForm.is_active" type="checkbox">
                    Activa
                </label>

                <div class="lines-editor">
                    <div class="lines-editor-header">
                        <span>Centro de costo</span>
                        <span>Porcentaje</span>
                        <span></span>
                    </div>
                    <div v-for="(line, index) in editForm.lines" :key="index" class="line-row">
                        <select v-model="line.cost_center_id" required>
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <input v-model="line.percentage" type="number" step="0.01" min="0" max="100" required>
                        <button type="button" class="btn btn-ghost" :disabled="editForm.lines.length <= 1" @click="removeEditLine(index)">✕</button>
                    </div>
                    <button type="button" class="btn btn-ghost" @click="addEditLine">+ Centro de costo</button>
                    <p class="total-row">
                        Total: {{ editTotal }}%
                        <span class="badge" :class="editIsBalanced ? 'badge-success' : 'badge-danger'">
                            {{ editIsBalanced ? 'Cuadrado' : 'No cuadra' }}
                        </span>
                    </p>
                    <span v-if="editForm.errors.lines" class="error">{{ editForm.errors.lines }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing || !editIsBalanced">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeEdit">Cancelar</button>
                </div>
            </form>
        </div>
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
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.code-cell { font-variant-numeric: tabular-nums; white-space: nowrap; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; white-space: nowrap; }

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
    width: 560px;
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

.lines-editor {
    border-top: 1px solid var(--color-border);
    padding-top: 0.75rem;
    margin-top: 0.25rem;
}

.lines-editor-header {
    display: grid;
    grid-template-columns: 1fr 100px 32px;
    gap: 0.5rem;
    font-size: 0.74rem;
    color: var(--color-text-muted);
    margin-bottom: 0.3rem;
}

.line-row {
    display: grid;
    grid-template-columns: 1fr 100px 32px;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
}

.line-row select, .line-row input {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.4rem 0.5rem;
    font-size: 0.82rem;
    color: var(--color-text);
}

.total-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    margin: 0.6rem 0 0;
}

.modal-actions { display: flex; gap: 0.6rem; margin-top: 1rem; }
</style>
