<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    bankAccounts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

// --- editar ---

const editing = ref(null);

const editForm = useForm({
    bank_name: '',
    account_number: '',
    gl_account_id: null,
    currency_id: null,
});

function openEdit(account) {
    editForm.clearErrors();
    editForm.bank_name = account.bank_name;
    editForm.account_number = account.account_number;
    editForm.gl_account_id = account.gl_account_id;
    editForm.currency_id = account.currency_id;
    editing.value = account;
}

function closeEdit() {
    editing.value = null;
}

function submitEdit() {
    editForm.put(route('bank-accounts.update', editing.value.id), { onSuccess: closeEdit, preserveScroll: true });
}
</script>

<template>
    <Head title="Bancos" />

    <AppLayout title="Cuentas bancarias">
        <template #actions>
            <Link :href="route('bank-accounts.create')" class="btn btn-primary">+ Nueva cuenta</Link>
        </template>

        <DocumentToolbar :new-href="route('bank-accounts.create')" />

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Banco</th>
                        <th>Número</th>
                        <th>Cuenta contable</th>
                        <th>Moneda</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="account in bankAccounts" :key="account.id">
                        <td>{{ account.bank_name }}</td>
                        <td class="num code-cell">{{ account.account_number }}</td>
                        <td>{{ account.gl_account?.code }} — {{ account.gl_account?.description_es }}</td>
                        <td>{{ account.currency?.code }}</td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-ghost" @click="openEdit(account)">Editar</button>
                            <Link :href="route('bank-reconciliations.index', account.id)" class="btn btn-ghost">
                                Conciliaciones
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!bankAccounts.length">
                        <td colspan="5" class="muted empty-row">Todavía no hay cuentas bancarias registradas.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Editar -->
        <div v-if="editing" class="modal-backdrop" @click.self="closeEdit">
            <form class="modal-card card" @submit.prevent="submitEdit">
                <h2>Editar {{ editing.bank_name }}</h2>

                <div class="field">
                    <label for="edit_bank_name">Banco</label>
                    <input id="edit_bank_name" v-model="editForm.bank_name" type="text" required>
                    <span v-if="editForm.errors.bank_name" class="error">{{ editForm.errors.bank_name }}</span>
                </div>

                <div class="field">
                    <label for="edit_account_number">Número de cuenta</label>
                    <input id="edit_account_number" v-model="editForm.account_number" type="text" required>
                    <span v-if="editForm.errors.account_number" class="error">{{ editForm.errors.account_number }}</span>
                </div>

                <div class="field">
                    <label for="edit_gl_account_id">Cuenta contable (debe ser distinta por cada cuenta bancaria)</label>
                    <select id="edit_gl_account_id" v-model="editForm.gl_account_id" required>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                    </select>
                    <span v-if="editForm.errors.gl_account_id" class="error">{{ editForm.errors.gl_account_id }}</span>
                </div>

                <div class="field">
                    <label for="edit_currency_id">Moneda</label>
                    <select id="edit_currency_id" v-model="editForm.currency_id" required>
                        <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
                    </select>
                    <span v-if="editForm.errors.currency_id" class="error">{{ editForm.errors.currency_id }}</span>
                </div>

                <div class="modal-actions">
                    <button type="submit" class="btn btn-primary" :disabled="editForm.processing">Guardar</button>
                    <button type="button" class="btn btn-ghost" @click="closeEdit">Cancelar</button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
table { font-size: 0.85rem; }
th, td { text-align: left; padding: 0.5rem 1rem; border-top: 1px solid var(--color-border); }
.code-cell { text-align: left; font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.empty-row { text-align: center; padding: 1.5rem; }
.actions-cell { display: flex; gap: 0.4rem; }

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

.modal-actions { display: flex; gap: 0.6rem; }
</style>
