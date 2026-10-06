/**
 * Las respuestas de Conti vienen en Markdown sencillo: párrafos, negritas,
 * listas, enlaces, código y alguna tabla. Esto las pasa a HTML para el chat
 * (ContiPanel.vue).
 *
 * Primero se escapa TODO el texto y después se arman solo las etiquetas de
 * acá: nada de lo que escriba el agente llega como HTML. Un enlace solo vale
 * si es http(s) o una ruta de CONTAPP («/…»); los de CONTAPP se marcan con
 * data-internal para abrirlos sin recargar la aplicación.
 */

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

function isInternal(href) {
    if (href.startsWith('/')) return !href.startsWith('//');

    try {
        return new URL(href.replace(/&amp;/g, '&')).origin === window.location.origin;
    } catch {
        return false;
    }
}

function link(href, label) {
    if (!/^https?:\/\//i.test(href) && !(href.startsWith('/') && !href.startsWith('//'))) return label;

    return isInternal(href)
        ? `<a href="${href}" data-internal="1">${label}</a>`
        : `<a href="${href}" target="_blank" rel="noopener noreferrer">${label}</a>`;
}

/** Lo de adentro de un renglón: código, enlaces, negritas, cursivas. Recibe texto ya escapado. */
function inline(text) {
    const codes = [];
    let out = text.replace(/`([^`]+)`/g, (_, code) => {
        codes.push(code);
        return `\u0000${codes.length - 1}\u0000`;
    });

    out = out.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (_, label, href) => link(href, label));
    // Una dirección suelta también es un enlace (no la que ya está en un href).
    out = out.replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, (_, before, href) => `${before}${link(href, href)}`);
    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    // Cursiva solo con asterisco: el guion bajo aparece en claves como
    // «crear_socio» y no es formato.
    out = out.replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\w)/g, '$1<em>$2</em>');

    return out.replace(/\u0000(\d+)\u0000/g, (_, i) => `<code>${codes[Number(i)]}</code>`);
}

const BULLET = /^\s*[-*•]\s+(.*)$/;
const NUMBERED = /^\s*\d+[.)]\s+(.*)$/;
const HEADING = /^\s*#{1,6}\s+(.*)$/;
const TABLE_ROW = /^\s*\|.*\|\s*$/;
const TABLE_RULE = /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/;

function cells(row) {
    return row.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim());
}

function startsBlock(lines, i) {
    const line = lines[i];
    return BULLET.test(line) || NUMBERED.test(line) || HEADING.test(line)
        || (TABLE_ROW.test(line) && TABLE_RULE.test(lines[i + 1] ?? ''));
}

export function renderMarkdown(source) {
    const lines = escapeHtml(String(source ?? '').replace(/\r\n/g, '\n')).split('\n');
    let html = '';
    let i = 0;

    while (i < lines.length) {
        const line = lines[i];

        if (/^\s*$/.test(line)) {
            i++;
            continue;
        }

        if (TABLE_ROW.test(line) && TABLE_RULE.test(lines[i + 1] ?? '')) {
            const head = cells(line);
            i += 2;
            const body = [];
            while (i < lines.length && TABLE_ROW.test(lines[i])) body.push(cells(lines[i++]));

            html += '<div class="md-table"><table><thead><tr>'
                + head.map((c) => `<th>${inline(c)}</th>`).join('')
                + '</tr></thead><tbody>'
                + body.map((row) => `<tr>${row.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')
                + '</tbody></table></div>';
            continue;
        }

        const heading = line.match(HEADING);
        if (heading) {
            html += `<p class="md-heading">${inline(heading[1])}</p>`;
            i++;
            continue;
        }

        const list = BULLET.test(line) ? ['ul', BULLET] : (NUMBERED.test(line) ? ['ol', NUMBERED] : null);
        if (list) {
            const [tag, pattern] = list;
            const items = [];
            while (i < lines.length && pattern.test(lines[i])) items.push(lines[i++].match(pattern)[1]);
            html += `<${tag}>${items.map((item) => `<li>${inline(item)}</li>`).join('')}</${tag}>`;
            continue;
        }

        const paragraph = [];
        while (i < lines.length && !/^\s*$/.test(lines[i]) && (paragraph.length === 0 || !startsBlock(lines, i))) {
            paragraph.push(lines[i++]);
        }
        html += `<p>${paragraph.map(inline).join('<br>')}</p>`;
    }

    return html;
}
