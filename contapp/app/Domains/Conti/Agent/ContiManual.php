<?php

namespace App\Domains\Conti\Agent;

use App\Domains\Conti\Support\KeywordMatcher;
use Illuminate\Support\Str;

/**
 * El manual de Pivox para Conti (resources/conti/manual.md), por secciones.
 *
 * No va entero en cada mensaje (son miles de tokens que se pagarían en cada
 * llamada al modelo): el modelo ve la lista de temas y pide las secciones
 * que necesita con la herramienta «manual». La búsqueda es por palabras: el
 * título pesa más que el cuerpo, y se devuelven las tres mejores.
 */
class ContiManual
{
    private const MAX_RESULTS = 3;

    private const MAX_SECTION_CHARS = 6000;

    /** @var list<array{title: string, path: string, body: string}>|null */
    private ?array $sections = null;

    /** @return list<array{title: string, path: string, body: string}> */
    public function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $sections = [];
        $parent = null;
        $current = null;

        foreach (preg_split('/\R/', (string) file_get_contents(resource_path('conti/manual.md'))) as $line) {
            if (preg_match('/^(#{2,3})\s+(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $title = trim($m[2]);
                $parent = $m[1] === '##' ? $title : $parent;
                $current = ['title' => $title, 'path' => $m[1] === '##' ? $title : "{$parent} › {$title}", 'body' => ''];

                continue;
            }

            if ($current !== null) {
                $current['body'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        // Un capítulo («## 2. Contabilidad») sin texto propio es solo el
        // título de sus secciones: no se ofrece como resultado.
        return $this->sections = array_values(array_filter($sections, fn (array $s) => trim($s['body']) !== ''));
    }

    /** La lista de temas, para el mensaje de sistema. */
    public function tableOfContents(): string
    {
        return implode("\n", array_map(fn (array $s) => '- '.$s['path'], $this->sections()));
    }

    /** Las secciones que mejor responden a un tema. */
    public function search(string $topic): array
    {
        $terms = KeywordMatcher::terms($topic);

        if ($terms === []) {
            return ['error' => 'Indicá el tema a buscar con una o más palabras clave.', 'codigo' => 422];
        }

        $scored = [];

        foreach ($this->sections() as $section) {
            $score = KeywordMatcher::score($terms, $section['path'], $section['body']);

            if ($score > 0) {
                $scored[] = ['score' => $score, 'section' => $section];
            }
        }

        if ($scored === []) {
            return ['tema' => $topic, 'resultado' => 'No encontré ese tema en el manual. Probá con otras palabras, o decí que no tenés esa información.'];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'tema' => $topic,
            'secciones' => array_map(fn (array $s) => [
                'seccion' => $s['section']['path'],
                'texto' => Str::limit(trim($s['section']['body']), self::MAX_SECTION_CHARS),
            ], array_slice($scored, 0, self::MAX_RESULTS)),
        ];
    }
}
