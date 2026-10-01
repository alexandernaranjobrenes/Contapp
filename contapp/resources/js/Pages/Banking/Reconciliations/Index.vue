<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../../Layouts/AppLayout.vue';
import DetailModal from '../../../Components/DetailModal.vue';
import MoneyInput from '../../../Components/MoneyInput.vue';
import { confirmAction } from '../../../Utils/confirm';
import { useRecordDetail } from '../../../Utils/recordDetail';
import { formatMoney } from '../../../Utils/money';
import { ArrowLeftIcon, ArrowRightIcon, PlusIcon, RotateCcwIcon } from '@lucide/vue';

const props = defineProps({
    bankAccount: { type: Object, required: true },
    reconciliations: { type: Array, default: () => [] },
});

// Ficha de la conciliación (CLAUDE.md secc. 20): abrirla, reabrirla o
// eliminarla se hace desde acá, no desde botones en la fila.
const { selected, openDetail, closeDetail } = useRecordDetail(() => props.reconciliations);

function reopen() {
    const r = selected.value;

    confirmAction({
        title: 'Reabrir conciliación',
        message: `La conciliación del corte ${r.cutoff_date} vuelve a estado "En proceso".`,
        confirmLabel: 'Reabrir',
        onConfirm: () => router.post(route('bank-reconciliations.reopen', r.id)),
    });
}

function destroyReconciliation() {
    const r = selected.value;

    confirmAction({
        title: 'Eliminar conciliación',
        message: `La conciliación del corte ${r.cutoff_date} se elimina de forma definitiva. El asiento contable no se ve afectado, solo se pierde el enlace de conciliación.`,
        confirmLabel: 'Eliminar',
        danger: true,
        onConfirm: () => router.delete(route('bank-reconciliations.destroy', r.id)),
    });
}

// «Crear nuevo» abre la conciliación en un modal (CLAUDE.md secc. 21); al
// guardarla el servidor lleva a su pantalla de trabajo.
const creating = ref(false);
const form = useForm({ cutoff_date: '', bank_balance: '' });

function openCreate() {
    form.clearErrors();
    form.cutoff_date = new Date().toISOString().slice(0, 10);
    form.bank_balance = '';
    creating.value = true;
}

function submit() {
    form.post(route('bank-reconciliations.store', props.bankAccount.id), {
        onSuccess: () => { creating.value = false; },
    });
}

const selectedTitle = computed(() => (selected.value ? `Conciliación al ${selected.value.cutoff_date}` : ''));
</script>

<template>
    <Head :title="`Conciliaciones — ${bankAccount.bank_name}`" />

    <AppLayout :title="`Conciliaciones — ${bankAccount.bank_name}`">
        <div class="view-toolbar">
            <Link :href="route('bank-reconciliations.hub')" class="btn btn-ghost"><ArrowLeftIcon /> Conciliaciones bancarias</Link>
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Corte</th>
                            <th class="num">Saldo banco</th>
                            <th class="num">Saldo libros</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in reconciliations"
                            :key="r.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(r)"
                            @keydown.enter="openDetail(r)"
                            @keydown.space.prevent="openDetail(r)"
                        >
                            <td>{{ r.cutoff_date }}</td>
                            <td data-label="Saldo banco" class="num">{{ formatMoney(r.bank_balance) }}</td>
                            <td data-label="Saldo libros" class="num">{{ formatMoney(r.book_balance) }}</td>
                            <td data-label="Estado">
                                <span class="badge" :class="r.status === 'completed' ? 'badge-success' : 'badge-warning'">
                                    {{ r.status === 'completed' ? 'Cerrada' : 'En proceso' }}
                                </span>
                            </td>
                        </tr>
                        <tr v-if="!reconciliations.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay conciliaciones para esta cuenta.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="!!selected" :title="selectedTitle" @close="closeDetail">
            <template #badge>
                <span v-if="selected" class="badge" :class="selected.status === 'completed' ? 'badge-success' : 'badge-warning'">
                    {{ selected.status === 'completed' ? 'Cerrada' : 'En proceso' }}
                </span>
            </template>

            <dl v-if="selected" class="detail-list">
                <div>
                    <dt>Saldo según banco</dt>
                    <dd>{{ formatMoney(selected.bank_balance) }}</dd>
                </div>
                <div>
                    <dt>Saldo de libros</dt>
                    <dd>{{ formatMoney(selected.book_balance) }}</dd>
                </div>
            </dl>

            <template #actions>
                <template v-if="selected">
                    <button type="button" class="btn btn-ghost btn-danger-text" @click="destroyReconciliation">Eliminar</button>
                    <button v-if="selected.status === 'completed'" type="button" class="btn btn-ghost" @click="reopen"><RotateCcwIcon /> Reabrir</button>
                    <Link :href="route('bank-reconciliations.show', selected.id)" class="btn btn-primary">
                        <ArrowRightIcon /> {{ selected.status === 'completed' ? 'Ver' : 'Continuar' }}
                    </Link>
                </template>
            </template>
        </DetailModal>

        <DetailModal :open="creating" title="Nueva conciliación" @close="creating = false">
            <form id="reconciliation-form" @submit.prevent="submit">
                <p class="hint">
                    El saldo de libros se calcula automáticamente desde la cuenta contable
                    <strong>{{ bankAccount.gl_account?.code }}</strong> hasta la fecha de corte.
                </p>

                <div class="field-row">
                    <div class="field">
                        <label for="cutoff_date">Fecha de corte</label>
                        <input id="cutoff_date" v-model="form.cutoff_date" type="date" required>
                        <span v-if="form.errors.cutoff_date" class="error">{{ form.errors.cutoff_date }}</span>
                    </div>
                    <div class="field">
                        <label for="bank_balance">Saldo según el banco</label>
                        <MoneyInput id="bank_balance" v-model="form.bank_balance" required />
                        <span v-if="form.errors.bank_balance" class="error">{{ form.errors.bank_balance }}</span>
                    </div>
                </div>
            </form>

            <template #actions>
                <button type="button" class="btn btn-ghost" @click="creating = false">Cancelar</button>
                <button type="submit" form="reconciliation-form" class="btn btn-primary" :disabled="form.processing">Abrir conciliación</button>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
</style>
