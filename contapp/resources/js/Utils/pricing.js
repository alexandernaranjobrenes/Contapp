/**
 * La cotización de un plan, en el navegador (CLAUDE.md secc. 42): el mismo
 * cálculo que App\Domains\Pricing\Support\PricingCalculator, sobre el mismo
 * catálogo (PricingCatalog), para mostrar el precio al instante. El que vale
 * es el del servidor, que vuelve a calcularlo al recibir la solicitud: si
 * cambian estas reglas, cambian en los dos lados.
 *
 * Los montos van en céntimos enteros.
 */

const minimum = (feature) => (feature.kind === 'quantity' ? Math.max(1, feature.included || 0) : 1);

const billable = (feature, quantity) => (feature.kind === 'quantity' ? Math.max(0, quantity - (feature.included || 0)) : 1);

/**
 * Las cantidades válidas: con su tope, con las obligatorias y con lo que
 * cada una necesita. Devuelve también lo que se sumó solo.
 */
export function normalizeSelection(features, selection) {
    const quantities = {};

    for (const feature of features) {
        const raw = Math.max(0, Number.parseInt(selection[feature.key] ?? 0, 10) || 0);
        const quantity = feature.kind === 'quantity' ? Math.min(raw, feature.max ?? Number.MAX_SAFE_INTEGER) : (raw > 0 ? 1 : 0);
        quantities[feature.key] = Math.max(quantity, feature.required ? minimum(feature) : 0);
    }

    const byKey = Object.fromEntries(features.map((feature) => [feature.key, feature]));
    const added = [];
    let changed = true;

    while (changed) {
        changed = false;

        for (const feature of features) {
            if (quantities[feature.key] === 0) continue;

            for (const needed of feature.requires) {
                if (needed in quantities && quantities[needed] === 0) {
                    quantities[needed] = minimum(byKey[needed]);
                    added.push(needed);
                    changed = true;
                }
            }
        }
    }

    return { quantities, added: [...new Set(added)] };
}

/** Las que dejan de tener sentido si se quita $key: las que la necesitan, aunque sea por otras. */
export function dependentsOf(features, key) {
    const result = new Set();
    let pending = [key];

    while (pending.length) {
        const current = pending.pop();

        for (const feature of features) {
            if (feature.requires.includes(current) && !result.has(feature.key)) {
                result.add(feature.key);
                pending = [...pending, feature.key];
            }
        }
    }

    return [...result];
}

export function quote(catalog, packageSlug, selection, cycle, currency) {
    const pkg = catalog.packages.find((candidate) => candidate.slug === packageSlug) ?? null;
    const effectiveCycle = pkg && pkg.billing !== 'both' ? pkg.billing : (cycle === 'annual' ? 'annual' : 'monthly');
    const effectiveCurrency = ['CRC', 'USD'].includes(currency) ? currency : catalog.settings.currency;
    const { quantities, added } = normalizeSelection(catalog.features, selection);

    const lines = [];
    let subtotal = 0;

    for (const feature of catalog.features) {
        const quantity = quantities[feature.key];
        if (quantity <= 0) continue;

        const unit = feature.prices[effectiveCurrency][effectiveCycle];
        const count = billable(feature, quantity);
        const amount = unit * count;
        subtotal += amount;

        lines.push({
            key: feature.key,
            name: feature.name,
            kind: feature.kind,
            quantity,
            billable: count,
            unit,
            amount,
            unit_label: feature.kind === 'quantity' ? (quantity === 1 ? feature.unit_singular : feature.unit_plural) : null,
        });
    }

    const packageFeatures = pkg ? Object.entries(pkg.features ?? {}) : [];
    const intact = pkg !== null && packageFeatures.every(([key, quantity]) => (quantities[key] ?? 0) >= quantity);
    let discount = 0;

    if (intact && pkg.discount_bp > 0) {
        const byKey = Object.fromEntries(catalog.features.map((feature) => [feature.key, feature]));
        let base = 0;

        for (const [key, quantity] of packageFeatures) {
            if (byKey[key]) base += byKey[key].prices[effectiveCurrency][effectiveCycle] * billable(byKey[key], quantity);
        }

        discount = Math.round((base * pkg.discount_bp) / 10000);
    }

    const net = subtotal - discount;
    const taxBp = catalog.settings.tax_bp;
    let tax;
    let total;

    if (catalog.settings.prices_include_tax) {
        tax = net - Math.round((net * 10000) / (10000 + taxBp));
        total = net;
    } else {
        tax = Math.round((net * taxBp) / 10000);
        total = net + tax;
    }

    return {
        package: pkg ? { slug: pkg.slug, name: pkg.name, discount_bp: pkg.discount_bp } : null,
        intact,
        cycle: effectiveCycle,
        currency: effectiveCurrency,
        lines,
        added,
        subtotal,
        discount,
        net,
        tax,
        tax_rate: catalog.settings.tax_rate,
        prices_include_tax: catalog.settings.prices_include_tax,
        total,
        quantities,
    };
}

/** Lo que trae un paquete, como selección para empezar a cotizar. */
export function packageSelection(catalog, slug) {
    const pkg = catalog.packages.find((candidate) => candidate.slug === slug);

    return pkg ? { ...pkg.features } : {};
}

/**
 * «₡25.000», «US$49», «₡12.345,50»: punto de millares y coma decimal, como
 * en la aplicación, y sin decimales si es redondo (PricingMoney::format).
 */
export function formatPrice(cents, currency) {
    const whole = cents % 100 === 0;
    const value = Math.abs(cents) / 100;
    const [integer, decimals] = value.toFixed(whole ? 0 : 2).split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const symbol = currency === 'USD' ? 'US$' : '₡';

    return `${cents < 0 ? '−' : ''}${symbol}${grouped}${decimals ? `,${decimals}` : ''}`;
}

export function cycleLabel(cycle) {
    return cycle === 'annual' ? 'por año' : 'por mes';
}

/** «13» o «13,5»: la tasa del IVA como se lee. */
export function formatRate(rate) {
    return String(Number(rate)).replace('.', ',');
}
