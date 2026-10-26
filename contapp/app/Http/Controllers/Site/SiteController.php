<?php

namespace App\Http\Controllers\Site;

use App\Domains\Site\Services\SitemapBuilder;
use App\Domains\Site\Support\Seo;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Lo que el sitio público le ofrece a un buscador (robots.txt y sitemap.xml)
 * y su página de privacidad (CLAUDE.md secc. 40).
 */
class SiteController extends Controller
{
    public function privacy(): View
    {
        return view('site.privacy', [
            'seo' => new Seo(
                title: Seo::title('Privacidad'),
                description: 'Qué datos recoge el sitio de Pivox, para qué los usa y cómo pedir que los corrijamos o los borremos.',
                canonical: Seo::route('privacy'),
                schema: [Seo::breadcrumbs([['Inicio', Seo::url('/')], ['Privacidad', Seo::route('privacy')]])],
            ),
            'updatedAt' => '25 de octubre de 2026',
        ]);
    }

    /**
     * Fuera de producción el sitio no se indexa: una copia de prueba en un
     * buscador competiría con el sitio de verdad. En producción, todo menos el
     * backoffice y las páginas de un solo uso (las que abre un enlace con
     * pase), con la dirección del sitemap.
     */
    public function robots(): Response
    {
        $lines = ['User-agent: *'];

        if (app()->isProduction()) {
            array_push(
                $lines,
                'Disallow: /backoffice',
                'Disallow: /conti/',
                'Disallow: /escanear-codigo/',
                'Disallow: /license-invitation/',
                'Disallow: /company-invitation/',
                'Disallow: /reset-password/',
                '',
                'Sitemap: '.Seo::route('sitemap'),
            );
        } else {
            $lines[] = 'Disallow: /';
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(SitemapBuilder $sitemap): Response
    {
        return response($sitemap->xml(), 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
