<?php

namespace App\Domains\Repository\Services;

use App\Domains\Conti\Support\KeywordMatcher;
use App\Domains\Repository\Models\RepositoryDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * El PDF de un documento del Repositorio: su Markdown, con el encabezado de
 * Pivox, la carpeta y la fecha, y el número de página.
 *
 * Armar el PDF de un texto largo (un código entero) tarda: se guarda en el
 * disco local (repository-pdfs/{id}-{huella}.pdf) y se vuelve a armar solo
 * cuando cambia algo de lo que se ve —el texto, el título, la carpeta, la
 * fecha—. Por eso un archivo editado a mano en public/repositories también
 * sale bien la próxima vez.
 *
 * Del Markdown se toma el formato, nunca HTML crudo ni imágenes: el HTML se
 * descarta y una imagen queda como su texto alternativo. Así el PDF no pide
 * nada afuera del documento.
 */
class RepositoryPdf
{
    /** Subirlo cuando cambia la plantilla: los PDF guardados se rehacen. */
    private const TEMPLATE_VERSION = 2;

    private const CACHE_DIR = 'repository-pdfs';

    /** El PDF, del guardado si nada cambió. */
    public function output(RepositoryDocument $document): string
    {
        $markdown = $document->markdown();

        if ($markdown === null) {
            throw new NotFoundHttpException('El archivo de este documento ya no está en el servidor. Avisale al equipo de Pivox.');
        }

        $document->loadMissing('folder');

        $fingerprint = sha1(implode("\0", [
            self::TEMPLATE_VERSION,
            $markdown,
            $document->title,
            $document->folder?->name,
            $document->file_updated_at?->toIso8601String(),
        ]));

        $path = self::CACHE_DIR."/{$document->id}-{$fingerprint}.pdf";
        $cache = Storage::disk('local');

        if (($cached = $cache->get($path)) !== null) {
            return $cached;
        }

        $pdf = $this->render($document, $markdown);

        $this->forget($document);
        $cache->put($path, $pdf);

        return $pdf;
    }

    public function filename(RepositoryDocument $document): string
    {
        return (Str::slug($document->title) ?: 'documento').'.pdf';
    }

    /** Borra los PDF guardados del documento (cambió, o se eliminó). */
    public function forget(RepositoryDocument $document): void
    {
        $cache = Storage::disk('local');

        foreach ($cache->files(self::CACHE_DIR) as $file) {
            if (str_starts_with(basename($file), "{$document->id}-")) {
                $cache->delete($file);
            }
        }
    }

    /** El cuerpo del documento en HTML, para la plantilla del PDF. */
    public function html(string $markdown): string
    {
        $html = (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);

        // Las imágenes no se piden (ni a otro sitio ni al disco): queda su
        // texto alternativo, si tiene.
        return preg_replace_callback('/<img\b[^>]*>/i', function (array $m) {
            return preg_match('/\balt="([^"]*)"/i', $m[0], $alt) && trim($alt[1]) !== ''
                ? '<em>['.$alt[1].']</em>'
                : '';
        }, $html);
    }

    private function render(RepositoryDocument $document, string $markdown): string
    {
        // Un código entero son cientos de páginas.
        @set_time_limit(180);

        $pdf = Pdf::loadView('repository.document', [
            'title' => $document->title,
            'folder' => $document->folder?->name,
            // La hora de Costa Rica, como en el resto de Pivox (la base está en UTC).
            'updatedAt' => ($document->file_updated_at ?? $document->updated_at)?->copy()->timezone('America/Costa_Rica'),
            'body' => $this->withoutRepeatedTitle($this->html($markdown), $document->title),
            'logo' => $this->logo(),
        ])->setPaper('letter', 'portrait')
            // Solo las letras que se usan: sin esto, cada PDF carga las
            // fuentes enteras (más de 1 MB aunque sea una página).
            ->setOption('isFontSubsettingEnabled', true);

        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 118, $canvas->get_height() - 30, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.45, 0.45, 0.45]);

        return $pdf->output();
    }

    /**
     * Casi todo documento empieza con su nombre («# Ley del IVA»), que es
     * también su título: el encabezado del PDF ya lo dice, y repetido justo
     * debajo se lee como un error. Se quita solo ese primer encabezado.
     */
    private function withoutRepeatedTitle(string $html, string $title): string
    {
        if (! preg_match('/^\s*<h1>(.*?)<\/h1>\s*/s', $html, $m)) {
            return $html;
        }

        $heading = KeywordMatcher::normalize(trim(html_entity_decode(strip_tags($m[1]))));
        $title = KeywordMatcher::normalize(trim($title));

        return $heading !== '' && (str_contains($title, $heading) || str_contains($heading, $title))
            ? substr($html, strlen($m[0]))
            : $html;
    }

    /**
     * El logo de Pivox para el encabezado. dompdf necesita GD para dibujar un
     * PNG: sin GD el PDF sale igual, con el nombre en texto.
     */
    private function logo(): ?string
    {
        $path = public_path('images/logos/logo_pivox.png');

        if (! extension_loaded('gd') || ! is_file($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
    }
}
