<?php

namespace App\Domains\Conti\Agent;

use Illuminate\Support\Str;

/**
 * El manual de CONTAPP para Conti (resources/conti/manual.md), por secciones.
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

    private const STOPWORDS = [
        'que', 'como', 'con', 'para', 'por', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'sus', 'mis', 'esta', 'este',
        'esto', 'ese', 'esa', 'hay', 'hace', 'hago', 'puedo', 'donde', 'cual', 'cuales', 'cuando', 'sobre', 'entre', 'sin',
        'mas', 'muy', 'pero', 'porque', 'quiero', 'tengo', 'tiene', 'son', 'ser', 'esta', 'estan', 'contapp',
    ];

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
        $terms = $this->terms($topic);

        if ($terms === []) {
            return ['error' => 'Indicá el tema a buscar con una o más palabras clave.', 'codigo' => 422];
        }

        $scored = [];

        foreach ($this->sections() as $section) {
            $title = $this->normalize($section['path']);
            $body = $this->normalize($section['body']);
            $score = 0;

            foreach ($terms as $term) {
                $score += substr_count($title, $term) * 6 + min(substr_count($body, $term), 8);
            }

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

    /** @return list<string> */
    private function terms(string $topic): array
    {
        $words = preg_split('/[^a-z0-9ñ]+/u', $this->normalize($topic), -1, PREG_SPLIT_NO_EMPTY);

        $words = array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true));

        // Una raíz y no la palabra entera: «anulación» encuentra «anular» y
        // «socios», «socio».
        return array_values(array_unique(array_map(fn (string $w) => match (true) {
            mb_strlen($w) >= 8 => mb_substr($w, 0, 5),
            mb_strlen($w) >= 5 => mb_substr($w, 0, max(4, mb_strlen($w) - 2)),
            default => $w,
        }, $words)));
    }

    private function normalize(string $text): string
    {
        // Sin tildes y en minúscula: «anulación» encuentra «anulacion».
        return Str::lower(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    }
}
