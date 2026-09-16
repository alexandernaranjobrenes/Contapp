<script setup>
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../../Layouts/AppLayout.vue';

const props = defineProps({
    activities: { type: Array, default: () => [] },
    taxAccounts: { type: Array, default: () => [] },
    paymentAccounts: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    taxRates: { type: Array, default: () => [] },
    catalogs: { type: Object, required: true },
    hacienda: { type: Object, default: () => ({}) },
});

const page = usePage();

const activityForm = useForm({ code: '', name: '', revenue_account_id: '', is_default: false });
const taxForm = useForm({ iva_rate_code: '08', account_id: '', tax_rate_id: '' });
const paymentForm = useForm({ method_code: '01', account_id: '' });

function submitActivity() {
    activityForm.post(route('billing-settings.activities.store'), {
        onSuccess: () => activityForm.reset(), preserveScroll: true,
    });
}

function submitTax() {
    taxForm
        .transform((data) => ({ ...data, tax_rate_id: data.tax_rate_id === '' ? null : data.tax_rate_id }))
        .post(route('billing-settings.tax-accounts.store'), {
            onSuccess: () => taxForm.reset(), preserveScroll: true,
        });
}

function submitPayment() {
    paymentForm.post(route('billing-settings.payment-accounts.store'), {
        onSuccess: () => paymentForm.reset(), preserveScroll: true,
    });
}

function remove(routeName, id, message) {
    if (! confirm(message)) return;

    router.delete(route(routeName, id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Configuración de facturación" />

    <AppLayout title="Configuración de facturación">
        <div v-for="(message, key) in page.props.errors" :key="key" class="flash flash-error">{{ message }}</div>

        <div class="card hacienda" :class="hacienda.signer_configured ? '' : 'pending'">
            <div>
                <strong>Firma electrónica</strong>
                <p class="muted small">
                    {{ hacienda.signer_configured
                        ? 'Certificado cargado: los comprobantes se pueden firmar.'
                        : 'Sin certificado. Se pueden emitir comprobantes y registrarlos en el ERP, y descargar su XML, pero no firmarlos.' }}
                </p>
            </div>
            <div>
                <strong>Envío a la DGT</strong>
                <p class="muted small">
                    {{ hacienda.transport_configured
                        ? 'Credenciales del ATV cargadas.'
                        : 'Sin credenciales del ATV. Los comprobantes no se envían a Hacienda todavía.' }}
                </p>
            </div>
        </div>

        <!-- Actividades económicas -->
        <section class="card panel">
            <h2>Actividades económicas del emisor</h2>
            <p class="hint">De cuál se factura define a qué cuenta de ingresos va la venta.</p>

            <table>
                <thead>
                    <tr><th>Código</th><th>Nombre</th><th>Cuenta de ingresos</th><th>Por defecto</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in activities" :key="a.id">
                        <td class="num code-cell">{{ a.code }}</td>
                        <td>{{ a.name }}</td>
                        <td class="muted small">{{ a.revenue_account?.code }} — {{ a.revenue_account?.description_es }}</td>
                        <td><span v-if="a.is_default" class="badge badge-success">Sí</span></td>
                        <td>
                            <button type="button" class="btn btn-ghost" @click="remove('billing-settings.activities.destroy', a.id, `¿Eliminar la actividad ${a.code}?`)">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!activities.length"><td colspan="5" class="muted empty-row">Sin actividades registradas.</td></tr>
                </tbody>
            </table>

            <form class="inline-form" @submit.prevent="submitActivity">
                <input v-model="activityForm.code" type="text" maxlength="6" placeholder="Código (6 dígitos)" required>
                <input v-model="activityForm.name" type="text" placeholder="Nombre de la actividad" required>
                <select v-model="activityForm.revenue_account_id" required>
                    <option value="" disabled>— Cuenta de ingresos —</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                </select>
                <label class="check"><input v-model="activityForm.is_default" type="checkbox"> Por defecto</label>
                <button type="submit" class="btn btn-primary" :disabled="activityForm.processing">Agregar</button>
            </form>
        </section>

        <!-- IVA débito fiscal -->
        <section class="card panel">
            <h2>Cuentas de IVA débito fiscal</h2>
            <p class="hint">
                Contra qué cuenta se acredita el impuesto de cada tarifa. El indicador de impuesto es opcional pero
                recomendado: es lo que hace que estas ventas aparezcan en el reporte de IVA existente.
            </p>

            <table>
                <thead>
                    <tr><th>Tarifa</th><th>Cuenta</th><th>Indicador de impuesto</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="t in taxAccounts" :key="t.id">
                        <td>{{ t.iva_rate_code }} — {{ catalogs.ivaRates[t.iva_rate_code]?.label }}</td>
                        <td class="muted small">{{ t.account?.code }} — {{ t.account?.description_es }}</td>
                        <td class="muted small">{{ t.tax_rate ? `${t.tax_rate.code} (${t.tax_rate.percentage}%)` : '—' }}</td>
                        <td>
                            <button type="button" class="btn btn-ghost" @click="remove('billing-settings.tax-accounts.destroy', t.id, '¿Eliminar esta configuración de IVA?')">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!taxAccounts.length"><td colspan="4" class="muted empty-row">Sin tarifas configuradas.</td></tr>
                </tbody>
            </table>

            <form class="inline-form" @submit.prevent="submitTax">
                <select v-model="taxForm.iva_rate_code" required>
                    <option v-for="(rate, code) in catalogs.ivaRates" :key="code" :value="code">{{ code }} — {{ rate.label }}</option>
                </select>
                <select v-model="taxForm.account_id" required>
                    <option value="" disabled>— Cuenta de IVA por pagar —</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                </select>
                <select v-model="taxForm.tax_rate_id">
                    <option value="">— Sin indicador —</option>
                    <option v-for="r in taxRates" :key="r.id" :value="r.id">{{ r.code }} — {{ r.percentage }}%</option>
                </select>
                <button type="submit" class="btn btn-primary" :disabled="taxForm.processing">Agregar</button>
            </form>
        </section>

        <!-- Medios de pago -->
        <section class="card panel">
            <h2>Cuentas por medio de pago</h2>
            <p class="hint">Contra qué cuenta se debita una venta de contado según cómo se cobró.</p>

            <table>
                <thead>
                    <tr><th>Medio de pago</th><th>Cuenta</th><th></th></tr>
                </thead>
                <tbody>
                    <tr v-for="p in paymentAccounts" :key="p.id">
                        <td>{{ p.method_code }} — {{ catalogs.paymentMethods[p.method_code] }}</td>
                        <td class="muted small">{{ p.account?.code }} — {{ p.account?.description_es }}</td>
                        <td>
                            <button type="button" class="btn btn-ghost" @click="remove('billing-settings.payment-accounts.destroy', p.id, '¿Eliminar esta configuración?')">
                                Eliminar
                            </button>
                        </td>
                    </tr>
                    <tr v-if="!paymentAccounts.length"><td colspan="3" class="muted empty-row">Sin medios de pago configurados.</td></tr>
                </tbody>
            </table>

            <form class="inline-form" @submit.prevent="submitPayment">
                <select v-model="paymentForm.method_code" required>
                    <option v-for="(label, code) in catalogs.paymentMethods" :key="code" :value="code">{{ code }} — {{ label }}</option>
                </select>
                <select v-model="paymentForm.account_id" required>
                    <option value="" disabled>— Cuenta —</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.description_es }}</option>
                </select>
                <button type="submit" class="btn btn-primary" :disabled="paymentForm.processing">Agregar</button>
            </form>
        </section>
    </AppLayout>
</template>

<style scoped>
.panel { padding: 1.1rem 1.25rem; margin-bottom: 0.9rem; }
.panel h2 { font-size: 0.92rem; margin: 0 0 0.35rem; }

.hacienda { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; padding: 1rem 1.25rem; margin-bottom: 0.9rem; }
.hacienda.pending { border-left: 3px solid var(--color-warning); }
.hacienda p { margin: 0.2rem 0 0; }

.flash { margin-bottom: 0.75rem; padding: 0.6rem 0.9rem; border-radius: var(--radius-sm); font-size: 0.85rem; }
.flash-error { background: var(--color-danger-soft); color: var(--color-danger); }

.hint { font-size: 0.8rem; color: var(--color-text-muted); margin: 0 0 0.9rem; }

table { font-size: 0.85rem; width: 100%; margin-bottom: 0.8rem; }
th, td { text-align: left; padding: 0.45rem 0.6rem; border-top: 1px solid var(--color-border); }
.code-cell, .num { font-variant-numeric: tabular-nums; }
.muted { color: var(--color-text-muted); }
.small { font-size: 0.76rem; }
.empty-row { text-align: center; padding: 1rem; }

.inline-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }

.inline-form input, .inline-form select {
    background: var(--color-surface); border: 1px solid var(--color-border);
    border-radius: var(--radius-sm); padding: 0.42rem 0.55rem; font-size: 0.84rem;
    color: var(--color-text); min-width: 170px;
}

.check { display: flex; align-items: center; gap: 0.35rem; font-size: 0.82rem; }
</style>
