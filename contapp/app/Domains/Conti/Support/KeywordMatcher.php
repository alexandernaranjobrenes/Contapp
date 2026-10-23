<?php

namespace App\Domains\Conti\Support;

use Illuminate\Support\Str;

/**
 * La búsqueda por palabras de Conti: la del manual (ContiManual) y la del
 * Repositorio (ContiRepository). Sin tildes, en minúscula, sin palabras
 * vacías y por raíz: «anulación» encuentra «anular», y «socios», «socio».
 */
class KeywordMatcher
{
    private const STOPWORDS = [
        'que', 'como', 'con', 'para', 'por', 'los', 'las', 'del', 'una', 'uno', 'unos', 'unas', 'sus', 'mis', 'esta', 'este',
        'esto', 'ese', 'esa', 'hay', 'hace', 'hago', 'puedo', 'donde', 'cual', 'cuales', 'cuando', 'sobre', 'entre', 'sin',
        'mas', 'muy', 'pero', 'porque', 'quiero', 'tengo', 'tiene', 'son', 'ser', 'esta', 'estan', 'pivox',
    ];

    /** @return list<string> las raíces a buscar en un texto ya normalizado */
    public static function terms(string $topic): array
    {
        $words = preg_split('/[^a-z0-9ñ]+/u', self::normalize($topic), -1, PREG_SPLIT_NO_EMPTY);

        $words = array_filter($words, fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true));

        // Una raíz y no la palabra entera: «anulación» encuentra «anular» y
        // «socios», «socio».
        return array_values(array_unique(array_map(fn (string $w) => match (true) {
            mb_strlen($w) >= 8 => mb_substr($w, 0, 5),
            mb_strlen($w) >= 5 => mb_substr($w, 0, max(4, mb_strlen($w) - 2)),
            default => $w,
        }, $words)));
    }

    public static function normalize(string $text): string
    {
        // Sin tildes y en minúscula: «anulación» encuentra «anulacion».
        return Str::lower(strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    }

    /**
     * Cuánto responde un texto a los términos: lo que está en el título pesa
     * más que lo del cuerpo, y un término repetido en el cuerpo suma hasta 8
     * veces.
     *
     * @param  list<string>  $terms
     */
    public static function score(array $terms, string $title, string $body, int $titleWeight = 6): int
    {
        $title = self::normalize($title);
        $body = self::normalize($body);
        $score = 0;

        foreach ($terms as $term) {
            $score += substr_count($title, $term) * $titleWeight + min(substr_count($body, $term), 8);
        }

        return $score;
    }
}
