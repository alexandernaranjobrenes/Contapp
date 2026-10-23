<?php

namespace App\Domains\Conti\Agent;

use App\Domains\Conti\Support\KeywordMatcher;
use App\Domains\Repository\Models\RepositoryDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Los documentos del Repositorio que el backoffice activó para Conti
 * (CLAUDE.md secc. 39): leyes, reglamentos, resoluciones, artículos. El
 * modelo ve la lista en el mensaje de sistema y pide los pasajes con la
 * herramienta «repositorio», como con el manual.
 *
 * Cada documento se parte en pasajes: por sus encabezados, y lo largo, por
 * párrafos, hasta unos 2500 caracteres. Un pasaje lleva el camino de
 * encabezados y, si empieza en un artículo («Artículo 12.-»), su número: así
 * Conti puede citar de dónde sale lo que dice.
 */
class ContiRepository
{
    private const MAX_RESULTS = 4;

    private const CHUNK_CHARS = 2500;

    private const MAX_INDEX = 80;

    /** @var Collection<int, RepositoryDocument>|null */
    private ?Collection $documents = null;

    /** @return Collection<int, RepositoryDocument> */
    public function documents(): Collection
    {
        return $this->documents ??= RepositoryDocument::query()
            ->forConti()
            ->with('folder:id,name')
            ->get()
            ->sortBy(fn (RepositoryDocument $d) => [$d->folder?->name, $d->title])
            ->values();
    }

    /** ¿Hay algo para consultar? Sin documentos, la herramienta no se ofrece. */
    public function available(): bool
    {
        return $this->documents()->isNotEmpty();
    }

    /** La lista para el mensaje de sistema: «Carpeta › Título». */
    public function index(): string
    {
        $documents = $this->documents();
        $lines = $documents->take(self::MAX_INDEX)
            ->map(fn (RepositoryDocument $d) => '- '.$this->label($d))
            ->all();

        if ($documents->count() > self::MAX_INDEX) {
            $lines[] = '- … y '.($documents->count() - self::MAX_INDEX).' más: buscalos por tema.';
        }

        return implode("\n", $lines);
    }

    /**
     * Los pasajes que mejor responden a un tema, de todos los documentos o de
     * uno ($document: su título, o parte de él).
     */
    public function search(string $topic, ?string $document = null): array
    {
        $terms = KeywordMatcher::terms($topic);

        if ($terms === []) {
            return ['error' => 'Indicá el tema a buscar con una o más palabras clave.', 'codigo' => 422];
        }

        $documents = $this->documents();

        if ($document !== null && trim($document) !== '') {
            $wanted = KeywordMatcher::normalize(trim($document));
            $documents = $documents->filter(fn (RepositoryDocument $d) => str_contains(KeywordMatcher::normalize($d->title), $wanted))->values();

            if ($documents->isEmpty()) {
                return ['error' => "No hay ningún documento del repositorio que se llame «{$document}». Revisá la lista de documentos.", 'codigo' => 404];
            }
        }

        if ($documents->isEmpty()) {
            return ['tema' => $topic, 'resultado' => 'El repositorio no tiene documentos para consultar.'];
        }

        $scored = [];

        foreach ($documents as $doc) {
            $markdown = $doc->markdown();

            if ($markdown === null) {
                continue;
            }

            foreach ($this->chunks($markdown) as $chunk) {
                $score = KeywordMatcher::score($terms, $chunk['path'], $chunk['text'])
                    + KeywordMatcher::score($terms, $doc->title, '', 3);

                if ($score > 0) {
                    $scored[] = ['score' => $score, 'document' => $doc, 'chunk' => $chunk];
                }
            }
        }

        if ($scored === []) {
            return [
                'tema' => $topic,
                'resultado' => 'No encontré ese tema en los documentos del repositorio. Probá con otras palabras; si no aparece, decí que no está en los documentos de Pivox y no lo cites de memoria.',
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'tema' => $topic,
            'pasajes' => array_map(fn (array $s) => array_filter([
                'documento' => $s['document']->title,
                'carpeta' => $s['document']->folder?->name,
                'seccion' => $s['chunk']['path'] !== '' ? $s['chunk']['path'] : null,
                'articulo' => $s['chunk']['article'],
                'texto' => $s['chunk']['text'],
                // Solo si las cuentas lo pueden abrir: la pantalla del
                // Repositorio, que lo marca. El PDF no es una pantalla.
                'enlace' => $s['document']->public_enabled ? '/repository?document='.$s['document']->id : null,
            ], fn ($value) => $value !== null), array_slice($scored, 0, self::MAX_RESULTS)),
        ];
    }

    /**
     * El documento en pasajes, con el camino de encabezados de cada uno.
     *
     * @return list<array{path: string, article: ?string, text: string}>
     */
    public function chunks(string $markdown): array
    {
        $chunks = [];
        $headings = [];
        $paragraphs = [];

        $flush = function () use (&$chunks, &$headings, &$paragraphs) {
            $path = implode(' › ', array_filter($headings));
            $current = '';
            $article = null;

            foreach ($paragraphs as $paragraph) {
                if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) > self::CHUNK_CHARS) {
                    $chunks[] = ['path' => $path, 'article' => $article, 'text' => trim($current)];
                    $current = '';
                    $article = null;
                }

                $article ??= $this->article($paragraph);
                $current .= $paragraph."\n\n";
            }

            if (trim($current) !== '') {
                $chunks[] = ['path' => $path, 'article' => $article, 'text' => trim($current)];
            }

            $paragraphs = [];
        };

        $buffer = '';

        foreach (preg_split('/\R/', $markdown) as $line) {
            if (preg_match('/^\s{0,3}(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m)) {
                if (trim($buffer) !== '') {
                    $paragraphs[] = trim($buffer);
                }
                $buffer = '';
                $flush();

                $level = strlen($m[1]);
                $headings = array_slice($headings, 0, $level - 1);
                $headings = array_pad($headings, $level - 1, '');
                $headings[] = trim(str_replace(['**', '__'], '', $m[2]));

                continue;
            }

            if (trim($line) === '') {
                if (trim($buffer) !== '') {
                    $paragraphs[] = trim($buffer);
                }
                $buffer = '';

                continue;
            }

            $buffer .= $line."\n";
        }

        if (trim($buffer) !== '') {
            $paragraphs[] = trim($buffer);
        }

        $flush();

        // Un párrafo enorme (un texto sin líneas en blanco) se corta igual.
        return array_merge(...array_map(fn (array $chunk) => mb_strlen($chunk['text']) <= self::CHUNK_CHARS * 2
            ? [$chunk]
            : array_map(fn (string $piece) => [...$chunk, 'text' => $piece], mb_str_split($chunk['text'], self::CHUNK_CHARS)), $chunks));
    }

    private function label(RepositoryDocument $document): string
    {
        return ($document->folder?->name ? $document->folder->name.' › ' : '').$document->title;
    }

    /** «Artículo 12», si el párrafo empieza con uno. */
    private function article(string $paragraph): ?string
    {
        $plain = ltrim(str_replace(['**', '__', '*', '_'], '', $paragraph), " \t-");

        return preg_match('/^(art[íi]culo\s+\d+[\w°º]*(?:\s+bis)?)/iu', $plain, $m)
            ? Str::ucfirst(Str::lower(preg_replace('/\s+/', ' ', $m[1])))
            : null;
    }
}
