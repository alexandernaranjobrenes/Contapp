<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
});

const form = useForm({
    bank_name: '',
    account_number: '',
    gl_account_id: props.accounts[0]?.id ?? null,
    currency_id: props.currencies[0]?.id ?? null,
});

function submit() {
    form.post(route('bank-accounts.store'));
}
</script>

<template>
    <Head title="Nueva cuenta bancaria" />

    <AppLayout title="Nueva cuenta bancaria">
        <DocumentToolbar :new-href="route('bank-accounts.create')" can-save :saving="form.processing" @save="submit" />

        <form class="card form-card" @submit.prevent="submit">
            <div class="grid">
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

                <div class="field span-2">
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
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Guardar</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }
.grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0 1.25rem; }
.span-2 { grid-column: span 2; }
select, input {
    width: 100%;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-sm);
    padding: 0.45rem 0.6rem;
    font-size: 0.85rem;
    color: var(--color-text);
}
.form-actions { margin-top: 0.5rem; }
</style>
