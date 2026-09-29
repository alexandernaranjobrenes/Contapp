<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { PencilIcon, PlusIcon, TagIcon } from '@lucide/vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useCrudModal } from '../../../Utils/crudModal';

const props = defineProps({
    priceLists: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const page = usePage();

// Vacío tiene que viajar como null: '' lo tomaría como una fecha inválida en
// vez de "sin límite".
function normalize(data) {
    return {
        ...data,
        valid_from: data.valid_from === '' ? null : data.valid_from,
        valid_to: data.valid_to === '' ? null : data.valid_to,
    };
}

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21). El
// código solo se elige al crear.
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.priceLists,
    defaults: () => ({
        code: '', name: '', currency_id: '', prices_include_tax: false, valid_from: '', valid_to: '', is_default: false, status: 'active',
    }),
    toForm: (l) => ({
        code: l.code,
        name: l.name,
        currency_id: l.currency_id,
        prices_include_tax: l.prices_include_tax,
        valid_from: l.valid_from ?? '',
        valid_to: l.valid_to ?? '',
        is_default: l.is_default,
        status: l.status,
    }),
    store: () => route('price-lists.store'),
    update: (l) => route('price-lists.update', l.id),
    storePayload: normalize,
    updatePayload: ({ code, ...data }) => normalize(data),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva lista de precios';
    return selected.value ? `${selected.value.code} — ${selected.value.name}` : '';
});

function validity(l) {
    if (! l.valid_from && ! l.valid_to) return 'Sin límite';
    return `${l.valid_from ?? '—'} a ${l.valid_to ?? '—'}`;
}

function destroy() {
    const l = selected.value;

    confirmAction({
        title: 'Eliminar lista de precios',
        message: `La lista ${l.code} — ${l.name} se elimina de forma definitiva.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('price-lists.destroy', l.id), { preserveScroll: true }),
    });
}

const hasDefault = computed(() => props.priceLists.some((l) => l.is_default && l.status === 'active'));
</script>

<template>
    <Head title="Listas de precios" />

    <AppLayout title="Listas de precios">
        <div class="view-toolbar">
            <div class="view-actions">
                <Link :href="route('items.index')" class="btn btn-ghost">Artículos</Link>
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

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
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Nombre</th>
                            <th>Moneda</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="l in priceLists"
                            :key="l.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(l)"
                            @keydown.enter="openDetail(l)"
                            @keydown.space.prevent="openDetail(l)"
                        >
                            <td>
                                <span class="code-cell">
                                    {{ l.code }}
                                    <span v-if="l.is_default" class="badge badge-warning">Predeterminada</span>
                                </span>
                            </td>
                            <td data-label="Nombre">{{ l.name }}</td>
                            <td data-label="Moneda">{{ l.currency_code }}</td>
                            <td data-label="Vigencia" class="muted">{{ validity(l) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="l.status === 'active' ? 'badge-success' : 'badge-neutral'">
                                    {{ l.status === 'active' ? 'Activa' : 'Inactiva' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!priceLists.length">
                            <td colspan="5" class="muted empty-row">
                                Todavía no hay listas de precios. Sin una lista, cada precio se digita en la factura.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <template #badge>
                <span v-if="selected && mode !== 'create'" class="badge" :class="selected.status === 'active' ? 'badge-success' : 'badge-neutral'">
                    {{ selected.status === 'active' ? 'Activa' : 'Inactiva' }}
                </span>
            </template>

            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Moneda</dt>
                    <dd>{{ selected.currency_code }}</dd>
                </div>
                <div>
                    <dt>Impuesto</dt>
                    <dd>{{ selected.prices_include_tax ? 'Precios con IVA' : 'Precios sin IVA' }}</dd>
                </div>
                <div>
                    <dt>Vigencia</dt>
                    <dd>{{ validity(selected) }}</dd>
                </div>
                <div>
                    <dt>Predeterminada</dt>
                    <dd>{{ selected.is_default ? 'Sí' : 'No' }}</dd>
                </div>
                <div>
                    <dt>Artículos con precio</dt>
                    <dd>{{ selected.lines_count }}</dd>
                </div>
                <div>
                    <dt>Clientes asignados</dt>
                    <dd>{{ selected.customers_count }}</dd>
                </div>
                <div>
                    <dt>Categorías asignadas</dt>
                    <dd>{{ selected.categories_count }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="price-list-form" @submit.prevent="submit">
                <div v-if="mode === 'create'" class="field">
                    <label for="pl-code">Código</label>
                    <input id="pl-code" v-model="form.code" type="text" maxlength="20" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="pl-name">Nombre</label>
                    <input id="pl-name" v-model="form.name" type="text" maxlength="255" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label for="pl-currency">Moneda</label>
                    <select id="pl-currency" v-model="form.currency_id" required>
                        <option value="">Elegí una moneda</option>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <span class="muted small">
                        Una lista en colones no da precio a una factura en dólares: son dos listas distintas,
                        cada una con su precio decidido.
                    </span>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="pl-from">Vigente desde</label>
                        <input id="pl-from" v-model="form.valid_from" type="date">
                    </div>
                    <div class="field">
                        <label for="pl-to">Vigente hasta</label>
                        <input id="pl-to" v-model="form.valid_to" type="date">
                        <span v-if="form.errors.valid_to" class="error">{{ form.errors.valid_to }}</span>
                    </div>
                </div>
                <p class="hint small">
                    Dejalas vacías para una lista sin límite. Sirven para cargar el aumento de enero en
                    diciembre y que entre solo.
                </p>

                <label class="check">
                    <input v-model="form.prices_include_tax" type="checkbox">
                    Los precios ya incluyen el IVA
                </label>

                <label class="check">
                    <input v-model="form.is_default" type="checkbox">
                    Predeterminada (la usan los clientes sin lista propia)
                </label>

                <div class="field">
                    <label for="pl-status">Estado</label>
                    <select id="pl-status" v-model="form.status">
                        <option value="active">Activa</option>
                        <option value="inactive">Inactiva</option>
                    </select>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroy">Eliminar</button>
                    <Link :href="route('price-lists.prices', selected.id)" class="btn btn-ghost"><TagIcon /> Precios</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="price-list-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { display: inline-flex; align-items: center; flex-wrap: wrap; gap: 0.4rem; font-variant-numeric: tabular-nums; }
</style>
