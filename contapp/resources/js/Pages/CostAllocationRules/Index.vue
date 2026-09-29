<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { PencilIcon, PlusIcon, XIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { confirmAction } from '../../Utils/confirm';

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

function statusLabel(r) {
    if (isVigenteHoy(r)) return 'Vigente';
    return r.is_active ? 'Fuera de vigencia' : 'Inactiva';
}

function emptyLine() {
    return { cost_center_id: props.costCenters[0]?.id ?? null, percentage: '' };
}

// Ficha de la norma (CLAUDE.md secc. 20) y alta (secc. 21) en un solo modal.
// Crear y editar comparten el formulario, con su reparto entre centros de
// costo; el código solo se elige al crear.
const mode = ref('details'); // 'create' | 'details' | 'edit'
const selectedId = ref(null);

const selected = computed(() => props.rules.find((r) => r.id === selectedId.value) ?? null);
const modalOpen = computed(() => mode.value === 'create' || !!selected.value);
const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva norma de reparto';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

const form = useForm({
    code: '',
    name: '',
    valid_from: today,
    valid_until: '',
    is_active: true,
    lines: [emptyLine(), emptyLine()],
});

function fillForm(values) {
    form.clearErrors();
    for (const [key, value] of Object.entries(values)) form[key] = value;
}

function openCreate() {
    fillForm({ code: '', name: '', valid_from: today, valid_until: '', is_active: true, lines: [emptyLine(), emptyLine()] });
    selectedId.value = null;
    mode.value = 'create';
}

function openRule(rule) {
    selectedId.value = rule.id;
    mode.value = 'details';
}

function closeRule() {
    selectedId.value = null;
    mode.value = 'details';
}

function startEdit() {
    const r = selected.value;
    fillForm({
        code: r.code,
        name: r.name,
        valid_from: r.valid_from,
        valid_until: r.valid_until ?? '',
        is_active: r.is_active,
        lines: r.lines.map((l) => ({ cost_center_id: l.cost_center_id, percentage: l.percentage })),
    });
    mode.value = 'edit';
}

function cancelForm() {
    if (mode.value === 'create') {
        closeRule();
        return;
    }

    mode.value = 'details';
}

function addLine() {
    form.lines.push(emptyLine());
}

function removeLine(index) {
    if (form.lines.length > 1) form.lines.splice(index, 1);
}

const total = computed(() =>
    form.lines.reduce((sum, l) => sum + (parseFloat(l.percentage) || 0), 0).toFixed(2)
);
const isBalanced = computed(() => total.value === '100.00');

function submit() {
    if (mode.value === 'create') {
        form.post(route('cost-allocation-rules.store'), { preserveScroll: true, onSuccess: closeRule });
        return;
    }

    form.put(route('cost-allocation-rules.update', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { mode.value = 'details'; },
    });
}

function destroy() {
    const r = selected.value;

    confirmAction({
        title: 'Eliminar norma de reparto',
        message: `La norma ${r.code} — ${r.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('cost-allocation-rules.destroy', r.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Normas de reparto" />

    <AppLayout title="Normas de reparto">
        <div class="view-toolbar">
            <div class="view-filters">
                <input v-model="search" type="search" placeholder="Buscar código o nombre..." aria-label="Buscar norma de reparto">
            </div>
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div v-if="page.props.errors?.rule" class="flash flash-error">{{ page.props.errors.rule }}</div>

        <p class="hint">
            Una norma de reparto reparte el monto de una línea de asiento entre varios centros de costo, según el
            porcentaje configurado — las cuentas de costo/gasto que exigen centro de costo ahora eligen una norma en
            vez de un centro directo (ver "Nuevo asiento").
        </p>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in filtered"
                            :key="r.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openRule(r)"
                            @keydown.enter="openRule(r)"
                            @keydown.space.prevent="openRule(r)"
                        >
                            <td class="code-cell">{{ r.code }}</td>
                            <td data-label="Nombre">{{ r.name }}</td>
                            <td data-label="Vigencia" class="muted date-range">{{ r.valid_from }} — {{ r.valid_until ?? 'sin fin' }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="isVigenteHoy(r) ? 'badge-success' : 'badge-neutral'">{{ statusLabel(r) }}</span>
                            </td>
                        </tr>
                        <tr v-if="!filtered.length">
                            <td colspan="4" class="muted empty-row">
                                {{ rules.length ? 'Ninguna norma coincide con la búsqueda.' : 'Todavía no hay normas de reparto registradas.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="closeRule">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="isVigenteHoy(selected) ? 'badge-success' : 'badge-neutral'">
                    {{ statusLabel(selected) }}
                </span>
            </template>

            <template v-if="selected && mode === 'details'">
                <dl class="detail-list">
                    <div>
                        <dt>Vigente desde</dt>
                        <dd>{{ selected.valid_from }}</dd>
                    </div>
                    <div>
                        <dt>Vigente hasta</dt>
                        <dd>{{ selected.valid_until ?? 'Sin fin' }}</dd>
                    </div>
                </dl>

                <h3 class="section-title">Reparto</h3>
                <ul class="split-list">
                    <li v-for="line in selected.lines" :key="line.cost_center_id">
                        <span>{{ line.cost_center.code }} — {{ line.cost_center.name }}</span>
                        <strong>{{ line.percentage }}%</strong>
                    </li>
                </ul>
            </template>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="rule-form" @submit.prevent="submit">
                <div class="field-row">
                    <div v-if="mode === 'create'" class="field">
                        <label for="rule-code">Código</label>
                        <input id="rule-code" v-model="form.code" type="text" maxlength="20" required>
                        <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                    </div>
                    <div class="field">
                        <label for="rule-name">Nombre</label>
                        <input id="rule-name" v-model="form.name" type="text" required>
                        <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="rule-from">Vigente desde</label>
                        <input id="rule-from" v-model="form.valid_from" type="date" required>
                        <span v-if="form.errors.valid_from" class="error">{{ form.errors.valid_from }}</span>
                    </div>
                    <div class="field">
                        <label for="rule-until">Vigente hasta (opcional)</label>
                        <input id="rule-until" v-model="form.valid_until" type="date">
                        <span v-if="form.errors.valid_until" class="error">{{ form.errors.valid_until }}</span>
                    </div>
                </div>

                <label class="check">
                    <input v-model="form.is_active" type="checkbox">
                    Activa
                </label>

                <fieldset class="lines-editor">
                    <legend>Reparto entre centros de costo</legend>
                    <div v-for="(line, index) in form.lines" :key="index" class="line-row">
                        <select v-model="line.cost_center_id" required aria-label="Centro de costo">
                            <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                        </select>
                        <input v-model="line.percentage" type="number" step="0.01" min="0" max="100" required aria-label="Porcentaje" placeholder="%">
                        <button type="button" class="btn btn-ghost remove-btn" :disabled="form.lines.length <= 1" aria-label="Quitar centro de costo" @click="removeLine(index)"><XIcon /></button>
                    </div>
                    <button type="button" class="btn btn-ghost" @click="addLine"><PlusIcon /> Centro de costo</button>
                    <p class="total-row">
                        Total: {{ total }}%
                        <span class="badge" :class="isBalanced ? 'badge-success' : 'badge-danger'">
                            {{ isBalanced ? 'Cuadrado' : 'No cuadra' }}
                        </span>
                    </p>
                    <span v-if="form.errors.lines" class="error-text">{{ form.errors.lines }}</span>
                </fieldset>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="rule-form" class="btn btn-primary" :disabled="form.processing || !isBalanced">
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
.date-range { font-variant-numeric: tabular-nums; white-space: nowrap; }

.split-list {
    margin: 0;
    padding: 0;
    list-style: none;
}

.split-list li {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.4rem 0;
    border-top: 1px solid var(--color-border);
    font-size: 0.85rem;
}

.lines-editor {
    margin: 0.5rem 0 0;
    padding: 0.75rem 0 0;
    border: 0;
    border-top: 1px solid var(--color-border);
}

.lines-editor legend {
    padding: 0 0.4rem 0 0;
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--color-text-muted);
}

.line-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 6.5rem auto;
    gap: 0.5rem;
    margin-bottom: 0.4rem;
}

.remove-btn {
    width: 2.25rem;
    padding: 0;
}

.total-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.85rem;
    font-weight: 600;
    margin: 0.6rem 0 0;
}
</style>
