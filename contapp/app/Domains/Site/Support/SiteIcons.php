<?php

namespace App\Domains\Site\Support;

use InvalidArgumentException;

/**
 * Los íconos Lucide del sitio público (CLAUDE.md secc. 23 y 40), dibujados en
 * el servidor: las páginas Blade del sitio no dependen de JavaScript para
 * mostrar un ícono, y un buscador ve la página completa.
 *
 * Los trazos vienen de resources/site/lucide-icons.php, que genera
 * scripts/site-icons.mjs desde @lucide/vue: los mismos de la aplicación. El
 * <svg> lleva las clases «lucide lucide-{nombre}», así le llegan los estilos
 * globales de app.scss (svg.lucide).
 */
final class SiteIcons
{
    /**
     * Los que el backoffice puede elegir para una función de los planes
     * (Planes y precios). La página de precios los recibe ya dibujados.
     */
    public const PICKABLE = [
        'book-open', 'tags', 'handshake', 'landmark', 'percent', 'package', 'truck', 'factory', 'boxes', 'warehouse',
        'receipt', 'file-check', 'users', 'bot', 'scan-line', 'scan-barcode', 'building-2', 'user-plus', 'users-round',
        'shield-check', 'hard-drive-download', 'library', 'headset', 'graduation-cap', 'chart-column', 'calculator',
        'wallet', 'coins', 'banknote', 'clipboard-list', 'globe', 'sparkles', 'zap', 'star', 'layers', 'briefcase',
        'file-spreadsheet', 'history', 'palette', 'messages-square', 'settings', 'key-round',
    ];

    /** @var array<string, string>|null */
    private static ?array $icons = null;

    /**
     * El <svg> del ícono. Sin $label es decorativo (aria-hidden), como los de
     * @lucide/vue; con $label se lee como imagen.
     */
    public static function svg(string $name, int $size = 16, string $class = '', ?string $label = null): string
    {
        $paths = self::icons()[$name] ?? throw new InvalidArgumentException(
            "El ícono «{$name}» no está en resources/site/lucide-icons.php: agregalo a scripts/site-icons.mjs y corré `npm run vendor:site-icons`."
        );

        $classes = trim("lucide lucide-{$name} {$class}");
        $accessibility = $label === null
            ? 'aria-hidden="true"'
            : 'role="img" aria-label="'.e($label).'"';

        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$size.'" height="'.$size.'" viewBox="0 0 24 24" fill="none"'
            .' stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
            .' class="'.e($classes).'" '.$accessibility.'>'.$paths.'</svg>';
    }

    public static function exists(string $name): bool
    {
        return isset(self::icons()[$name]);
    }

    /**
     * Los íconos elegibles, con su dibujo, para el selector del backoffice.
     *
     * @return list<array{name: string, svg: string}>
     */
    public static function pickable(): array
    {
        return array_map(fn (string $name) => ['name' => $name, 'svg' => self::svg($name, 20)], self::PICKABLE);
    }

    /** @return array<string, string> */
    private static function icons(): array
    {
        return self::$icons ??= require resource_path('site/lucide-icons.php');
    }
}
