<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import DocumentToolbar from '../../Components/DocumentToolbar.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import MoneyInput from '../../Components/MoneyInput.vue';

const props = defineProps({
    accounts: { type: Array, default: () => [] },
    currencies: { type: Array, default: () => [] },
    categories: { type: Array, default: () => [] },
    costCenters: { type: Array, default: () => [] },
    priceLists: { type: Array, default: () => [] },
    today: { type: String, required: true },
});

const form = useForm({
    code: '',
    name: '',
    type: 'client',
    tax_id: '',
    email: '',
    economic_activity_code: '',
    phone: '',
    contact_name: '',
    partner_since: props.today,
    category_id: null,
    cost_center_id: null,
    gl_account_id: props.accounts[0]?.id ?? null,
    currency_id: props.currencies[0]?.id ?? null,
    price_list_id: null,
    credit_limit: '',
    payment_terms_days: '',
});

function submit() {
    form.post(route('business-partners.store'));
}
</script>

<template>
    <Head title="Nuevo socio de negocio" />

    <AppLayout title="Nuevo socio de negocio">
        <DocumentToolbar can-save :saving="form.processing" @save="submit" />

        <form class="card form-card" @submit.prevent="submit">
            <div class="grid">
                <div class="field">
                    <label for="code">Código (x-xxx)</label>
                    <input id="code" v-model="form.code" type="text" placeholder="C-001" required>
                    <span v-if="form.errors.code" class="error">{{ form.errors.code }}</span>
                </div>

                <div class="field">
                    <label for="type">Tipo</label>
                    <select id="type" v-model="form.type" required>
                        <option value="client">Cliente</option>
                        <option value="supplier">Proveedor</option>
                        <option value="both">Ambos</option>
                    </select>
                </div>

                <div class="field span-2">
                    <label for="name">Nombre</label>
                    <input id="name" v-model="form.name" type="text" required>
                    <span v-if="form.errors.name" class="error">{{ form.errors.name }}</span>
                </div>

                <div class="field">
                    <label for="tax_id">Cédula</label>
                    <input id="tax_id" v-model="form.tax_id" type="text">
                </div>

                <div class="field">
                    <label for="email">Correo electrónico</label>
                    <input id="email" v-model="form.email" type="email">
                    <span v-if="form.errors.email" class="error">{{ form.errors.email }}</span>
                </div>

                <div class="field">
                    <label for="phone">Teléfono</label>
                    <input id="phone" v-model="form.phone" type="text">
                </div>

                <div class="field">
                    <label for="contact_name">Nombre del encargado</label>
                    <input id="contact_name" v-model="form.contact_name" type="text">
                </div>

                <div class="field">
                    <label for="economic_activity_code">Código de actividad económica</label>
                    <input id="economic_activity_code" v-model="form.economic_activity_code" type="text" placeholder="Hacienda">
                </div>

                <div class="field">
                    <label for="partner_since">Cliente/proveedor desde</label>
                    <input id="partner_since" v-model="form.partner_since" type="date">
                    <span v-if="form.errors.partner_since" class="error">{{ form.errors.partner_since }}</span>
                </div>

                <div class="field">
                    <label for="category_id">Categoría (opcional)</label>
                    <select id="category_id" v-model="form.category_id">
                        <option :value="null">— Sin categoría —</option>
                        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.code }} — {{ c.name }}</option>
                    </select>
                    <span class="hint">Para agrupar reportes de ventas. Se administran en <a :href="route('bp-categories.index')" target="_blank">Categorías de socios</a>.</span>
                    <span v-if="form.errors.category_id" class="error">{{ form.errors.category_id }}</span>
                </div>

                <div class="field">
                    <label for="cost_center_id">Centro de costo (opcional)</label>
                    <select id="cost_center_id" v-model="form.cost_center_id">
                        <option :value="null">— Sin centro de costo —</option>
                        <option v-for="cc in costCenters" :key="cc.id" :value="cc.id">{{ cc.code }} — {{ cc.name }}</option>
                    </select>
                    <span class="hint">A qué centro de costo pertenece este socio, para reportes y análisis por centro.</span>
                    <span v-if="form.errors.cost_center_id" class="error">{{ form.errors.cost_center_id }}</span>
                </div>

                <div class="field">
                    <label for="gl_account_id">Cuenta contable</label>
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

                <div class="field">
                    <label for="price_list_id">Lista de precios</label>
                    <select id="price_list_id" v-model="form.price_list_id">
                        <option :value="null">Predeterminada de la compañía</option>
                        <option v-for="p in priceLists" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <span class="hint">
                        Dejala en predeterminada salvo que este cliente tenga precios propios (mayorista,
                        distribuidor, convenio). Si se le asigna una lista y un artículo no tiene precio ahí,
                        la línea de la factura llega vacía: <strong>no</strong> se cae a la lista general,
                        porque eso le cobraría el precio de mostrador sin avisar.
                    </span>
                </div>

                <div class="field">
                    <label for="credit_limit">Límite de crédito</label>
                    <MoneyInput id="credit_limit" v-model="form.credit_limit" />
                </div>

                <div class="field">
                    <label for="payment_terms_days">Plazo de pago (días)</label>
                    <input id="payment_terms_days" v-model="form.payment_terms_days" type="number" min="0">
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
.error { display: block; color: var(--color-danger); font-size: 0.76rem; margin-top: 0.2rem; }
.hint { display: block; font-size: 0.74rem; color: var(--color-text-muted); margin-top: 0.2rem; }
.hint a { color: var(--color-primary); }
</style>
