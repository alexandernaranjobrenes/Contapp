<?php

namespace App\Domains\Blog\Support;

use App\Domains\Blog\Models\BlogImage;
use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * El HTML de un artículo del blog (CLAUDE.md secc. 41).
 *
 * Al guardar, clean() arma un documento nuevo copiando del que mandó el
 * editor solo lo que está en la lista blanca: las etiquetas de ALLOWED, sus
 * atributos permitidos con el valor revisado, y el texto como texto. Todo lo
 * demás se cae. No se confía en el editor: un artículo se muestra a
 * cualquiera, así que un <script> o un onerror que llegara (una cuenta del
 * backoffice robada, algo pegado de otro sitio) sería un XSS para todos los
 * visitantes. El parser es el HTML5 nativo de PHP 8.4 (Dom\HTMLDocument): lee
 * el HTML como lo lee un navegador.
 *
 * Una imagen se guarda como <img data-image-id="12" alt="…">, sin dirección:
 * render() y forEditor() le ponen la de su archivo en el bucket al mostrarla.
 * Así se muestran solo imágenes propias, y un cambio de dominio del bucket
 * no rompe ningún artículo.
 */
final class BlogHtml
{
    /** Las etiquetas que quedan, con los atributos que pueden llevar. */
    private const ALLOWED = [
        'p' => ['class'],
        'h2' => ['class'],
        'h3' => ['class'],
        'h4' => ['class'],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'mark' => [],
        'code' => [],
        'sub' => [],
        'sup' => [],
        'br' => [],
        'hr' => [],
        'blockquote' => [],
        'pre' => [],
        'ul' => [],
        'ol' => ['start'],
        'li' => [],
        'a' => ['href', 'target', 'title'],
        'figure' => ['class'],
        'figcaption' => [],
        'img' => ['data-image-id', 'alt'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /** Las que se van con todo lo que tienen adentro (no solo la etiqueta). */
    private const DROP = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'form', 'input',
        'button', 'select', 'option', 'textarea', 'link', 'meta', 'title', 'head', 'frame', 'frameset', 'video',
        'audio', 'canvas', 'colgroup', 'col', 'dialog',
    ];

    /** La misma etiqueta con su nombre de siempre. */
    private const RENAME = ['b' => 'strong', 'i' => 'em'];

    /** Las que no llevan contenido. */
    private const VOID = ['br', 'hr', 'img'];

    /** Las clases que acepta el editor: alineación del texto y tamaño de una imagen. */
    public const CLASSES = ['text-center', 'text-right', 'text-justify', 'image-wide', 'image-medium', 'image-small'];

    /** Hasta dónde se anida: más que esto no es un artículo. */
    private const MAX_DEPTH = 40;

    // ── Al guardar ───────────────────────────────────────────────────────

    /**
     * El HTML limpio. Con $allowedImages, una imagen que no esté en la lista
     * se quita (la de otro artículo, una que ya no existe).
     *
     * @param  list<int>|null  $allowedImages
     */
    public static function clean(string $html, ?array $allowedImages = null): string
    {
        $source = self::parse($html);
        $target = HTMLDocument::createEmpty();
        $root = $target->createElement('div');

        self::copy($source->body, $root, $target, $allowedImages, 0);
        self::dropEmptyFigures($root);

        return self::inner($target, $root);
    }

    /**
     * Los ids de las imágenes que usa el HTML, en orden y sin repetir.
     *
     * @return list<int>
     */
    public static function imageIds(string $html): array
    {
        $ids = [];

        foreach (self::parse($html)->body->querySelectorAll('img[data-image-id]') as $image) {
            $ids[] = (int) $image->getAttribute('data-image-id');
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * El texto, sin etiquetas y con los espacios juntos. Entre dos bloques va
     * un espacio: textContent pegaría el final de un título con el comienzo
     * del párrafo siguiente («cierreConciliá»).
     */
    public static function text(string $html): string
    {
        $parts = [];
        self::collectText(self::parse($html)->body, $parts);

        return trim(preg_replace('/\s+/u', ' ', implode('', $parts)));
    }

    public static function wordCount(string $html): int
    {
        $text = self::text($html);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    // ── Al mostrar ───────────────────────────────────────────────────────

    /**
     * El HTML para el sitio, con el índice de sus títulos:
     * - cada imagen con su dirección, sus medidas (sin saltos al cargar) y
     *   carga diferida; una que ya no está, se quita;
     * - cada título h2 y h3 con un id, para el índice y para enlazarlo;
     * - cada tabla dentro de una caja que se desplaza de lado si no cabe.
     *
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public static function render(string $html): array
    {
        $document = self::parse($html);
        $body = $document->body;

        self::fillImages($document, lazy: true);

        $toc = [];
        $used = [];

        foreach ($body->querySelectorAll('h2, h3') as $heading) {
            $text = trim(preg_replace('/\s+/u', ' ', $heading->textContent ?? ''));
            if ($text === '') {
                continue;
            }

            $base = Str::slug($text) ?: 'seccion';
            $id = $base;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = "{$base}-{$n}";
            }
            $used[$id] = true;

            $heading->setAttribute('id', $id);
            $toc[] = ['id' => $id, 'text' => $text, 'level' => $heading->localName === 'h2' ? 2 : 3];
        }

        foreach ($body->querySelectorAll('table') as $table) {
            $wrapper = $document->createElement('div');
            $wrapper->setAttribute('class', 'article-table');
            $table->before($wrapper);
            $wrapper->append($table);
        }

        foreach ($body->querySelectorAll('a[target="_blank"]') as $link) {
            $link->setAttribute('rel', 'noopener noreferrer');
        }

        return ['html' => self::inner($document, $body), 'toc' => $toc];
    }

    /** El HTML para el editor del backoffice: las imágenes con su dirección. */
    public static function forEditor(string $html): string
    {
        $document = self::parse($html);
        self::fillImages($document, lazy: false);

        return self::inner($document, $document->body);
    }

    // ── Por dentro ───────────────────────────────────────────────────────

    private static function parse(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<!DOCTYPE html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);
    }

    /** Las que separan su texto del de al lado. */
    private const BLOCKS = [
        'p', 'h2', 'h3', 'h4', 'li', 'blockquote', 'pre', 'figure', 'figcaption', 'table', 'tr', 'th', 'td',
        'ul', 'ol', 'br', 'hr', 'div',
    ];

    /** @param  list<string>  $parts */
    private static function collectText(Node $node, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof Text) {
                $parts[] = $child->textContent;

                continue;
            }

            if (! $child instanceof Element) {
                continue;
            }

            $block = in_array(strtolower($child->localName), self::BLOCKS, true);

            if ($block) {
                $parts[] = ' ';
            }

            self::collectText($child, $parts);

            if ($block) {
                $parts[] = ' ';
            }
        }
    }

    /** @param  list<int>|null  $allowedImages */
    private static function copy(Node $from, Element $to, HTMLDocument $document, ?array $allowedImages, int $depth): void
    {
        foreach ($from->childNodes as $child) {
            if ($child instanceof Text) {
                $to->append($document->createTextNode($child->textContent));

                continue;
            }

            // Comentarios e instrucciones de procesamiento, fuera.
            if (! $child instanceof Element) {
                continue;
            }

            $tag = strtolower($child->localName);

            if (in_array($tag, self::DROP, true) || $depth >= self::MAX_DEPTH) {
                continue;
            }

            // Una etiqueta que no está en la lista se va, pero su texto queda
            // (un <span> o un <div> de algo pegado de Word).
            if (! isset(self::ALLOWED[$tag])) {
                self::copy($child, $to, $document, $allowedImages, $depth + 1);

                continue;
            }

            $element = $document->createElement(self::RENAME[$tag] ?? $tag);

            foreach (self::ALLOWED[$tag] as $attribute) {
                $value = $child->getAttribute($attribute);
                $clean = $value === null ? null : self::attribute($attribute, $value);

                if ($clean !== null) {
                    $element->setAttribute($attribute, $clean);
                }
            }

            if ($tag === 'img') {
                $id = (int) $element->getAttribute('data-image-id');

                if ($id === 0 || ($allowedImages !== null && ! in_array($id, $allowedImages, true))) {
                    continue;
                }
            }

            // Un enlace sin una dirección aceptable deja de ser enlace: queda su texto.
            if ($tag === 'a') {
                if (! $element->hasAttribute('href')) {
                    self::copy($child, $to, $document, $allowedImages, $depth + 1);

                    continue;
                }

                if ($element->getAttribute('target') === '_blank') {
                    $element->setAttribute('rel', 'noopener noreferrer');
                }
            }

            if (! in_array($tag, self::VOID, true)) {
                self::copy($child, $element, $document, $allowedImages, $depth + 1);
            }

            $to->append($element);
        }
    }

    /** El valor revisado de un atributo, o null si no se acepta. */
    private static function attribute(string $name, string $value): ?string
    {
        return match ($name) {
            'class' => self::classes($value),
            'href' => self::href($value),
            'target' => strtolower(trim($value)) === '_blank' ? '_blank' : null,
            'title', 'alt' => mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, 250),
            'data-image-id' => ctype_digit(trim($value)) && (int) $value > 0 ? (string) (int) $value : null,
            'start' => ctype_digit(trim($value)) && (int) $value > 0 && (int) $value < 100000 ? (string) (int) $value : null,
            'colspan', 'rowspan' => ctype_digit(trim($value)) && (int) $value >= 1 && (int) $value <= 50 ? (string) (int) $value : null,
            default => null,
        };
    }

    private static function classes(string $value): ?string
    {
        $classes = array_values(array_intersect(preg_split('/\s+/', trim($value)) ?: [], self::CLASSES));

        return $classes === [] ? null : implode(' ', $classes);
    }

    /**
     * Una dirección completa (http, https), un correo, un teléfono, una página
     * del sitio («/precios») o un ancla del artículo («#iva»). El navegador
     * ignora los espacios y los caracteres de control dentro del esquema
     * («java&#9;script:»), así que se revisa sin ellos.
     */
    private static function href(string $value): ?string
    {
        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $value);

        if ($compact === '' || str_contains($compact, '\\')) {
            return null;
        }

        $relative = (str_starts_with($compact, '/') && ! str_starts_with($compact, '//')) || str_starts_with($compact, '#');

        if ($relative || preg_match('#^(https?://[^/?\#]+|mailto:|tel:)#i', $compact)) {
            return mb_substr($compact, 0, 2000);
        }

        return null;
    }

    /** Una figura que se quedó sin su imagen ya no tiene qué mostrar. */
    private static function dropEmptyFigures(Element $root): void
    {
        foreach ($root->querySelectorAll('figure') as $figure) {
            if ($figure->querySelector('img') === null) {
                $figure->remove();
            }
        }
    }

    /** Le pone a cada imagen su dirección y sus medidas; quita la que ya no está. */
    private static function fillImages(HTMLDocument $document, bool $lazy): void
    {
        $nodes = iterator_to_array($document->body->querySelectorAll('img[data-image-id]'));
        if ($nodes === []) {
            return;
        }

        /** @var Collection<int, BlogImage> $images */
        $images = BlogImage::query()
            ->whereIn('id', array_map(fn (Element $node) => (int) $node->getAttribute('data-image-id'), $nodes))
            ->whereNotNull('path')
            ->get()
            ->keyBy('id');

        foreach ($nodes as $node) {
            $image = $images->get((int) $node->getAttribute('data-image-id'));

            if ($image === null) {
                $figure = $node->closest('figure');
                ($figure ?? $node)->remove();

                continue;
            }

            $node->setAttribute('src', (string) $image->url());

            if ($image->width && $image->height) {
                $node->setAttribute('width', (string) $image->width);
                $node->setAttribute('height', (string) $image->height);
            }

            if ($lazy) {
                $node->setAttribute('loading', 'lazy');
                $node->setAttribute('decoding', 'async');
            }
        }
    }

    private static function inner(HTMLDocument $document, Element $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $document->saveHtml($child);
        }

        return trim($html);
    }
}
