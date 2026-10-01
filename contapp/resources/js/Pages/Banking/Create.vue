<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { ArrowLeftIcon } from '@lucide/vue';

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
        <div class="view-toolbar">
            <Link :href="route('bank-accounts.index')" class="btn btn-ghost"><ArrowLeftIcon /> Cuentas bancarias</Link>
        </div>

        <form class="card form-card" @submit.prevent="submit">
            <div class="form-grid">
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
</style>
