<?php

namespace App\Domains\Site\Support;

/**
 * Lo que una página del sitio público le dice a un buscador y a una red
 * social (CLAUDE.md secc. 40): el título, la descripción, la URL canónica, la
 * imagen para compartir y los datos estructurados (JSON-LD). Lo arma cada
 * controlador del sitio y lo dibuja site/partials/seo.blade.php.
 *
 * Las URL son siempre del dominio del sitio (APP_URL), sin importar desde qué
 * dirección llegó el pedido: una canónica con otro host le diría a Google que
 * la página es de otro sitio.
 */
final class Seo
{
    public const SITE_NAME = 'Pivox';

    /** Lo que un buscador muestra de un título y de una descripción. */
    public const TITLE_LIMIT = 60;

    public const DESCRIPTION_LIMIT = 160;

    /** La imagen para compartir de las páginas que no traen una propia. */
    public const DEFAULT_IMAGE = 'images/og/pivox.png';

    public const DEFAULT_IMAGE_WIDTH = 1200;

    public const DEFAULT_IMAGE_HEIGHT = 630;

    /**
     * @param  string  $title  el <title> completo
     * @param  array{published_time?: string, modified_time?: string, section?: ?string, author?: ?string, tags?: list<string>}  $article
     * @param  list<array<string, mixed>>  $schema  nodos de schema.org, que van juntos en un @graph
     */
    public function __construct(
        public readonly string $title,
        public readonly string $description,
        public readonly string $canonical,
        public readonly ?string $image = null,
        public readonly ?int $imageWidth = null,
        public readonly ?int $imageHeight = null,
        public readonly ?string $imageAlt = null,
        public readonly string $type = 'website',
        public readonly bool $index = true,
        public readonly array $article = [],
        public readonly array $schema = [],
        public readonly ?string $socialTitle = null,
        public readonly ?string $prev = null,
        public readonly ?string $next = null,
    ) {}

    /** «Blog» → «Blog | Pivox». */
    public static function title(string $page): string
    {
        return str_contains($page, self::SITE_NAME) ? $page : $page.' | '.self::SITE_NAME;
    }

    /** Una dirección absoluta del sitio: «/blog» → «https://pivoxcr.com/blog». */
    public static function url(string $path = '/'): string
    {
        // Una URL completa (la de una imagen del bucket) se deja como está.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /** La URL canónica de una ruta con nombre. */
    public static function route(string $name, mixed $parameters = []): string
    {
        return self::url(route($name, $parameters, false));
    }

    /** La imagen para compartir (og:image), con sus medidas. */
    public function imageUrl(): string
    {
        return self::url($this->image ?? self::DEFAULT_IMAGE);
    }

    public function imageWidth(): int
    {
        return $this->image === null ? self::DEFAULT_IMAGE_WIDTH : (int) $this->imageWidth;
    }

    public function imageHeight(): int
    {
        return $this->image === null ? self::DEFAULT_IMAGE_HEIGHT : (int) $this->imageHeight;
    }

    public function imageAlt(): string
    {
        return $this->image === null ? 'Pivox: contabilidad, facturación electrónica y planillas' : (string) $this->imageAlt;
    }

    /** El JSON-LD de la página, listo para un <script>, o null si no trae. */
    public function jsonLd(): ?string
    {
        if ($this->schema === []) {
            return null;
        }

        // JSON_HEX_TAG: un «</script>» en un título no puede cerrar la etiqueta.
        return json_encode(
            ['@context' => 'https://schema.org', '@graph' => $this->schema],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP,
        );
    }

    // ── Nodos de schema.org que repiten varias páginas ─────────────────────

    /** @return array<string, mixed> */
    public static function organization(): array
    {
        return [
            '@type' => 'Organization',
            '@id' => self::url('/#organizacion'),
            'name' => self::SITE_NAME,
            'url' => self::url('/'),
            'logo' => [
                '@type' => 'ImageObject',
                'url' => self::url('images/logos/logo_pivox.png'),
                'width' => 554,
                'height' => 160,
            ],
            'email' => config('site.contact_email'),
            'areaServed' => ['@type' => 'Country', 'name' => 'Costa Rica'],
        ];
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        return [
            '@type' => 'WebSite',
            '@id' => self::url('/#sitio'),
            'url' => self::url('/'),
            'name' => self::SITE_NAME,
            'inLanguage' => 'es-CR',
            'publisher' => ['@id' => self::url('/#organizacion')],
        ];
    }

    /**
     * Las migas de pan: [[nombre, url], …], de la portada a la página.
     *
     * @param  list<array{0: string, 1: string}>  $items
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $items): array
    {
        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn (array $item, int $index) => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $item[0],
                'item' => $item[1],
            ], $items, array_keys($items)),
        ];
    }
}
