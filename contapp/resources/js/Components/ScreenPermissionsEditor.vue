<script setup>
import { computed, ref } from 'vue';
import { ChevronDownIcon } from '@lucide/vue';

/**
 * Los permisos de una persona por pantalla del menú lateral, agrupados como
 * el menú: una sección por opción (Contabilidad, Inventario…) y adentro sus
 * subopciones, por categoría (Operación, Reportes, Catálogos…). Ver
 * App\Domains\Core\Support\ScreenCatalog.
 *
 * - Cada sección se abre y se cierra, y tiene «Toda la sección» para darle
 *   el mismo nivel a todas sus pantallas de una vez.
 * - Cada pantalla ofrece hasta lo que puede dar quien edita (max_level). Los
 *   reportes, hasta Lectura.
 * - Si la persona ya tiene en una pantalla más de lo que quien edita puede
 *   dar, ese nivel se muestra y no se puede cambiar: guardar no se lo quita.
 *
 * v-model: { clave de pantalla: 'none' | 'read' | 'read_write' }.
 */
const props = defineProps({
    sections: { type: Array, required: true },
    modelValue: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue']);

const LEVELS = ['none', 'read', 'read_write'];
const LEVEL_LABELS = { none: 'Sin acceso', read: 'Lectura', read_write: 'Lectura y escritura' };
const rank = (level) => LEVELS.indexOf(level);

// Abiertas de entrada: las que ya tienen algún acceso.
const open = ref(Object.fromEntries(props.sections.map((s) => [s.key, s.screens.some((sc) => (props.modelValue[sc.key] ?? 'none') !== 'none')])));

function levelOf(screen) {
    return props.modelValue[screen.key] ?? 'none';
}

/** ¿Tiene más de lo que quien edita puede dar? Entonces no se toca. */
function locked(screen) {
    return rank(screen.current_level ?? 'none') > rank(screen.max_level) && levelOf(screen) === screen.current_level;
}

function optionsFor(screen) {
    if (locked(screen)) return [screen.current_level];
    return LEVELS.filter((level) => rank(level) <= rank(screen.max_level));
}

function setLevel(screen, level) {
    emit('update:modelValue', { ...props.modelValue, [screen.key]: level });
}

function groupsOf(section) {
    const groups = [];
    for (const screen of section.screens) {
        let group = groups.find((g) => g.label === screen.group);
        if (!group) {
            group = { label: screen.group, screens: [] };
            groups.push(group);
        }
        group.screens.push(screen);
    }
    return groups;
}

// «Toda la sección»: cada pantalla toma el nivel pedido, o el más alto que
// admite (un reporte con «Lectura y escritura» queda en Lectura).
function setSection(section, level) {
    if (!level) return;

    const next = { ...props.modelValue };
    for (const screen of section.screens) {
        if (locked(screen)) continue;
        next[screen.key] = LEVELS[Math.min(rank(level), rank(screen.max_level))];
    }
    emit('update:modelValue', next);
}

// Las opciones de «Toda la sección»: hasta el nivel más alto que se puede
// dar en alguna de sus pantallas.
function sectionOptions(section) {
    const top = Math.max(...section.screens.map((s) => rank(s.max_level)));
    return LEVELS.filter((level) => rank(level) <= top);
}

// El valor del selector de la sección: el nivel de todas, si es el mismo.
function sectionValue(section) {
    const levels = new Set(section.screens.map(levelOf));
    return levels.size === 1 ? [...levels][0] : '';
}

function grantedCount(section) {
    return section.screens.filter((s) => levelOf(s) !== 'none').length;
}

const totalGranted = computed(() => props.sections.reduce((sum, s) => sum + grantedCount(s), 0));

// Se muestran solo las pantallas que quien edita puede dar (las que tiene
// él), más las que la persona ya tiene aunque él no pueda darlas (quedan
// bloqueadas). Un Administrador con acceso a una sola sección ve esa: no 66
// pantallas que solo ofrecen «Sin acceso».
const visibleSections = computed(() => props.sections
    .map((section) => ({
        ...section,
        screens: section.screens.filter((s) => s.max_level !== 'none' || (s.current_level ?? 'none') !== 'none'),
    }))
    .filter((section) => section.screens.length > 0));

const hiddenCount = computed(() => props.sections.reduce((sum, s) => sum + s.screens.length, 0)
    - visibleSections.value.reduce((sum, s) => sum + s.screens.length, 0));
</script>

<template>
    <div class="screen-permissions">
        <p v-if="!visibleSections.length" class="perm-empty">
            No tenés permisos que puedas dar: solo se da hasta el acceso que uno tiene. Pedile al Superusuario que te
            asigne las pantallas que esta persona necesita, y después se las das vos.
        </p>
        <template v-else>
            <p class="muted small total">{{ totalGranted }} {{ totalGranted === 1 ? 'pantalla' : 'pantallas' }} con acceso.</p>
            <p v-if="hiddenCount" class="perm-hidden-note">
                Ves solo las pantallas que tenés vos, porque son las únicas que podés dar. Si esta persona necesita otra,
                pedile al Superusuario que te la asigne primero.
            </p>
        </template>

        <section v-for="section in visibleSections" :key="section.key" class="perm-section" :class="{ 'is-open': open[section.key] }">
            <header class="perm-section-head">
                <button
                    type="button"
                    class="perm-toggle"
                    :aria-expanded="open[section.key]"
                    :aria-controls="`perm-${section.key}`"
                    @click="open[section.key] = !open[section.key]"
                >
                    <ChevronDownIcon class="perm-chevron" />
                    <span class="perm-section-label">{{ section.label }}</span>
                    <span class="perm-count" :class="{ 'has-access': grantedCount(section) }">
                        {{ grantedCount(section) }} de {{ section.screens.length }}
                    </span>
                </button>
                <label class="perm-all">
                    <span class="sr-only">Nivel de toda la sección {{ section.label }}</span>
                    <select
                        :value="sectionValue(section)"
                        :aria-label="`Nivel de toda la sección ${section.label}`"
                        @change="setSection(section, $event.target.value)"
                    >
                        <option value="" disabled>Toda la sección: mixto</option>
                        <option v-for="level in sectionOptions(section)" :key="level" :value="level">Toda la sección: {{ LEVEL_LABELS[level] }}</option>
                    </select>
                </label>
            </header>

            <div v-show="open[section.key]" :id="`perm-${section.key}`" class="perm-body">
                <div v-for="group in groupsOf(section)" :key="group.label" class="perm-group">
                    <h4 class="perm-group-label">{{ group.label }}</h4>
                    <div v-for="screen in group.screens" :key="screen.key" class="perm-row">
                        <label :for="`perm-${screen.key}`" class="perm-label">
                            {{ screen.label }}
                            <span v-if="screen.read_only" class="perm-tag">solo consulta</span>
                            <span v-if="locked(screen)" class="perm-tag is-locked">más de lo que podés dar</span>
                        </label>
                        <select
                            :id="`perm-${screen.key}`"
                            :value="levelOf(screen)"
                            :disabled="locked(screen)"
                            @change="setLevel(screen, $event.target.value)"
                        >
                            <option v-for="level in optionsFor(screen)" :key="level" :value="level">{{ LEVEL_LABELS[level] }}</option>
                        </select>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>

<style scoped>
.screen-permissions {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.total {
    margin: 0 0 0.25rem;
}

.perm-hidden-note,
.perm-empty {
    margin: 0 0 0.25rem;
    padding: 0.55rem 0.75rem;
    border-radius: var(--radius-sm);
    background: var(--color-info-soft);
    color: var(--color-text);
    font-size: 0.8rem;
    line-height: 1.45;
}

.perm-section {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
}

.perm-section-head {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.5rem 0.75rem;
    padding: 0.4rem 0.6rem 0.4rem 0.4rem;
}

/* El conmutador de la sección: la altura y la letra de los controles
   (CLAUDE.md secc. 26). */
.perm-toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1 1 14rem;
    min-width: 0;
    min-height: 2.25rem;
    padding: 0.3rem 0.5rem;
    border: none;
    border-radius: var(--radius-sm);
    background: transparent;
    font: inherit;
    text-align: left;
    color: var(--color-text);
    cursor: pointer;
}

.perm-toggle:hover {
    background: var(--color-surface-alt);
}

.perm-chevron {
    flex-shrink: 0;
    color: var(--color-text-muted);
    transform: rotate(-90deg);
    transition: transform .15s ease;
}

.is-open .perm-chevron {
    transform: none;
}

.perm-section-label {
    font-weight: 700;
    font-size: 0.88rem;
}

.perm-count {
    margin-left: auto;
    padding: 0.1rem 0.5rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--color-text-muted);
    white-space: nowrap;
}

.perm-count.has-access {
    background: var(--color-success-soft);
    color: var(--color-success);
}

.perm-all {
    flex: 0 1 15rem;
    min-width: 12rem;
}

.perm-all select {
    width: 100%;
}

.perm-body {
    padding: 0.25rem 0.9rem 0.75rem;
    border-top: 1px solid var(--color-border);
}

.perm-group-label {
    margin: 0.75rem 0 0.3rem;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--color-text-muted);
}

.perm-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(10rem, 15rem);
    align-items: center;
    gap: 0.75rem;
    padding: 0.25rem 0;
}

.perm-row + .perm-row {
    border-top: 1px dashed var(--color-border);
}

.perm-label {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.2rem 0.45rem;
    font-size: 0.85rem;
    overflow-wrap: anywhere;
}

.perm-row select {
    width: 100%;
}

.perm-tag {
    padding: 0.05rem 0.4rem;
    border-radius: 999px;
    background: var(--color-surface-alt);
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    color: var(--color-text-muted);
}

.perm-tag.is-locked {
    background: var(--color-warning-soft);
    color: var(--color-warning);
}

/* En un teléfono, el nivel debajo del nombre de la pantalla. */
@media (max-width: 640px) {
    .perm-row {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.3rem;
        padding: 0.45rem 0;
    }

    .perm-all {
        flex-basis: 100%;
    }
}
</style>
