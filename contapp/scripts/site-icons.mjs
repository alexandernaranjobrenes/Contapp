/**
 * Los íconos Lucide del sitio público (CLAUDE.md secc. 40).
 *
 * Las páginas del sitio son Blade y dibujan sus íconos en el servidor, sin
 * JavaScript: `<x-site.icon name="bot" />`. Este script copia los trazos de
 * @lucide/vue —la misma versión que usa la aplicación— a
 * resources/site/lucide-icons.php, así un ícono se ve igual en la aplicación
 * y en el sitio.
 *
 * Los nombres son los de los componentes de la aplicación en kebab-case
 * (Building2Icon → building-2). Lucide renombra íconos y deja el nombre
 * anterior como alias (building-2 es building-complex): por eso se buscan en
 * las exportaciones del paquete, como los resuelve un `import` de la
 * aplicación.
 *
 * Para usar un ícono nuevo en el sitio: se agrega a ICONS y se corre
 * `npm run vendor:site-icons`.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const esm = join(root, 'node_modules/@lucide/vue/dist/esm');

const ICONS = [
    // Interfaz del sitio.
    'menu', 'x', 'moon', 'sun', 'arrow-right', 'arrow-left', 'chevron-right', 'chevron-down', 'check', 'circle-check',
    'external-link', 'mail', 'log-in', 'layout-dashboard', 'key-round', 'info', 'triangle-alert', 'loader-circle',
    'search', 'plus', 'minus', 'calendar', 'clock', 'share-2', 'link', 'copy', 'rss', 'list', 'tag', 'newspaper',
    'message-circle', 'printer', 'download', 'eye', 'sparkles', 'user-round', 'pen-line', 'inbox', 'image',

    // Módulos y funciones de Pivox (los mismos íconos del menú lateral).
    'book-open', 'tags', 'package', 'receipt', 'users', 'handshake', 'landmark', 'percent', 'library', 'settings',
    'bot', 'scan-line', 'scan-barcode', 'messages-square', 'mic', 'send', 'shield-check', 'lock', 'history',
    'hard-drive-download', 'building-2', 'palette', 'file-spreadsheet', 'file-text', 'file-check', 'signature',
    'hourglass', 'badge-check', 'qr-code', 'smartphone', 'laptop', 'camera', 'chart-column', 'trending-up',
    'wallet', 'coins', 'calculator', 'banknote', 'factory', 'truck', 'boxes', 'warehouse', 'clipboard-list',
    'user-plus', 'users-round', 'briefcase', 'globe', 'zap', 'refresh-cw', 'arrow-left-right', 'scale',
    'list-checks', 'gauge', 'headset', 'graduation-cap', 'star', 'layers', 'monitor-smartphone',
];

const barrel = readFileSync(join(esm, 'lucide-vue.mjs'), 'utf8');

function pascal(name) {
    return name.split('-').map((part) => part.charAt(0).toUpperCase() + part.slice(1)).join('');
}

// «export { default as Building2, … } from './icons/building-complex.mjs';»
function fileFor(name) {
    const component = pascal(name);

    for (const line of barrel.split('\n')) {
        const match = line.match(/^export \{ (.+) \} from '\.\/icons\/(.+)\.mjs';$/);
        if (match && match[1].split(', ').includes(`default as ${component}`)) return match[2];
    }

    throw new Error(`Lucide no tiene el ícono «${name}» (${component}).`);
}

const escape = (value) => String(value).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');

const lines = [];

for (const name of ICONS) {
    const { __iconData } = await import(pathToFileURL(join(esm, 'icons', `${fileFor(name)}.mjs`)).href);

    const svg = __iconData.node
        .map(([tag, attributes]) => {
            const attrs = Object.entries(attributes)
                .filter(([key]) => key !== 'key')
                .map(([key, value]) => `${key}="${escape(value)}"`)
                .join(' ');

            return `<${tag} ${attrs}/>`;
        })
        .join('');

    lines.push(`    '${name}' => '${svg.replace(/'/g, "\\'")}',`);
}

const version = JSON.parse(readFileSync(join(root, 'node_modules/@lucide/vue/package.json'), 'utf8')).version;

writeFileSync(join(root, 'resources/site/lucide-icons.php'), `<?php

/*
 * Generado por scripts/site-icons.mjs desde @lucide/vue ${version}. No se
 * edita a mano: para un ícono nuevo, se agrega a ICONS en ese script y se
 * corre \`npm run vendor:site-icons\`.
 */

return [
${lines.join('\n')}
];
`);

console.log(`${ICONS.length} íconos en resources/site/lucide-icons.php (@lucide/vue ${version}).`);
