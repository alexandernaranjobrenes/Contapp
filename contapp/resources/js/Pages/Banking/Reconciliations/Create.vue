<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';
import MoneyInput from '../../../Components/MoneyInput.vue';
import { ArrowLeftIcon } from '@lucide/vue';

const props = defineProps({
    bankAccount: { type: Object, required: true },
});

const form = useForm({
    cutoff_date: new Date().toISOString().slice(0, 10),
    bank_balance: '',
});

function submit() {
    form.post(route('bank-reconciliations.store', props.bankAccount.id));
}
</script>

<template>
    <Head title="Nueva conciliación" />

    <AppLayout :title="`Nueva conciliación — ${bankAccount.bank_name}`">
        <div class="view-toolbar">
            <Link :href="route('bank-reconciliations.index', bankAccount.id)" class="btn btn-ghost"><ArrowLeftIcon /> Historial de la cuenta</Link>
        </div>

        <form class="card form-card" @submit.prevent="submit">
            <p class="hint">
                El saldo de libros se calcula automáticamente desde la cuenta contable
                <strong>{{ bankAccount.gl_account?.code }}</strong> hasta la fecha de corte.
            </p>

            <div class="form-grid">
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

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Abrir conciliación</button>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.form-card { padding: 1.25rem; }
</style>
