<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DetailModal from '../../Components/DetailModal.vue';
import { useCrudModal } from '../../Utils/crudModal';
import { ListChecksIcon, PencilIcon, PlusIcon } from '@lucide/vue';

const props = defineProps({
    bankAccounts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

// Ficha, alta y edición en un solo modal (CLAUDE.md secc. 20 y 21).
const { mode, selected, modalOpen, form, openCreate, openDetail, close, startEdit, cancelForm, submit } = useCrudModal({
    records: () => props.bankAccounts,
    defaults: () => ({
        bank_name: '',
        account_number: '',
        gl_account_id: props.accounts[0]?.id ?? null,
        currency_id: props.currencies[0]?.id ?? null,
    }),
    toForm: (a) => ({
        bank_name: a.bank_name,
        account_number: a.account_number,
        gl_account_id: a.gl_account_id,
        currency_id: a.currency_id,
    }),
    store: () => route('bank-accounts.store'),
    update: (a) => route('bank-accounts.update', a.id),
});

const modalTitle = computed(() => {
    if (mode.value === 'create') return 'Nueva cuenta bancaria';
    return selected.value ? `${selected.value.bank_name} — ${selected.value.account_number}` : '';
});
</script>

<template>
    <Head title="Bancos" />

    <AppLayout title="Cuentas bancarias">
        <div class="view-toolbar">
            <div class="view-actions">
                <button type="button" class="btn btn-primary" @click="openCreate()"><PlusIcon /> Crear nuevo</button>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table>
                    <thead>
                        <tr>
                            <th>Banco</th>
                            <th>Número</th>
                            <th>Cuenta contable</th>
                            <th>Moneda</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="account in bankAccounts"
                            :key="account.id"
                            class="clickable-row"
                            tabindex="0"
                            @click="openDetail(account)"
                            @keydown.enter="openDetail(account)"
                            @keydown.space.prevent="openDetail(account)"
                        >
                            <td>{{ account.bank_name }}</td>
                            <td data-label="Número" class="code-cell">{{ account.account_number }}</td>
                            <td data-label="Cuenta contable">{{ account.gl_account?.code }} — {{ account.gl_account?.description_es }}</td>
                            <td data-label="Moneda">{{ account.currency?.code }}</td>
                        </tr>
                        <tr v-if="!bankAccounts.length">
                            <td colspan="4" class="muted empty-row">Todavía no hay cuentas bancarias registradas.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <DetailModal :open="modalOpen" :title="modalTitle" @close="close">
            <dl v-if="selected && mode === 'details'" class="detail-list">
                <div>
                    <dt>Banco</dt>
                    <dd>{{ selected.bank_name }}</dd>
                </div>
                <div>
                    <dt>Número de cuenta</dt>
                    <dd>{{ selected.account_number }}</dd>
                </div>
                <div>
                    <dt>Cuenta contable</dt>
                    <dd>{{ selected.gl_account?.code }} — {{ selected.gl_account?.description_es }}</dd>
                </div>
                <div>
                    <dt>Moneda</dt>
                    <dd>{{ selected.currency?.code }}</dd>
                </div>
            </dl>

            <form v-if="mode === 'create' || (selected && mode === 'edit')" id="bank-account-form" @submit.prevent="submit">
                <div class="field-row">
                    <div class="field">
                        <label for="bank_name">Banco</label>
                        <input id="bank_name" v-model="form.bank_name" type="text" required>
                        <span v-if="form.errors.bank_name" class="error">{{ form.errors.bank_name }}</span>
                    </div>
                    <div class="field">
                        <label for="account_number">Número de cuenta</label>
                        <input id="account_number" v-model="form.account_number" type="text" required>
                        <span v-if="form.errors.account_number" class="error">{{ form.errors.account_number }}</span>
                    </div>
                </div>

                <div class="field">
                    <label for="gl_account_id">Cuenta contable (debe ser distinta por cada cuenta bancaria)</label>
                    <select id="gl_account_id" v-model="form.gl_account_id" required>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="form.errors.gl_account_id" class="error">{{ form.errors.gl_account_id }}</span>
                </div>

                <div class="field">
                    <label for="currency_id">Moneda</label>
                    <select id="currency_id" v-model="form.currency_id" required>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                    </select>
                    <span v-if="form.errors.currency_id" class="error">{{ form.errors.currency_id }}</span>
                </div>
            </form>

            <template #actions>
                <template v-if="selected && mode === 'details'">
                    <Link :href="route('bank-reconciliations.index', selected.id)" class="btn btn-ghost"><ListChecksIcon /> Conciliaciones</Link>
                    <button type="button" class="btn btn-primary" @click="startEdit"><PencilIcon /> Editar</button>
                </template>
                <template v-else-if="modalOpen">
                    <button type="button" class="btn btn-ghost" @click="cancelForm">Cancelar</button>
                    <button type="submit" form="bank-account-form" class="btn btn-primary" :disabled="form.processing">
                        {{ mode === 'create' ? 'Crear' : 'Guardar' }}
                    </button>
                </template>
            </template>
        </DetailModal>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
.code-cell { font-variant-numeric: tabular-nums; }
</style>
