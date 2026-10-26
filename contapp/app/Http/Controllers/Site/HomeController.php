<?php

namespace App\Http\Controllers\Site;

use App\Domains\Blog\Models\BlogPost;
use App\Domains\Site\Support\Seo;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * La landing del sitio público (CLAUDE.md secc. 40): qué es Pivox y todo lo
 * que hace, con sus demostraciones animadas.
 */
class HomeController extends Controller
{
    public function show(): View
    {
        $seo = new Seo(
            title: 'Pivox: software contable y ERP para empresas de Costa Rica',
            description: 'Contabilidad, facturación electrónica v4.4, inventario, planillas y bancos en un solo sistema, con Conti, un asistente con IA. Pensado para Costa Rica.',
            canonical: Seo::url('/'),
            schema: [Seo::organization(), Seo::website(), $this->software()],
        );

        return view('site.home', [
            'seo' => $seo,
            // Los últimos del blog, si hay: más enlaces internos para un buscador.
            'posts' => BlogPost::query()->published()->with(['category', 'cover'])->orderByDesc('published_at')->limit(3)->get(),
        ]);
    }

    /** @return array<string, mixed> */
    private function software(): array
    {
        return [
            '@type' => 'SoftwareApplication',
            '@id' => Seo::url('/#software'),
            'name' => Seo::SITE_NAME,
            'url' => Seo::url('/'),
            'applicationCategory' => 'BusinessApplication',
            'applicationSubCategory' => 'Contabilidad y ERP',
            'operatingSystem' => 'Web',
            'inLanguage' => 'es-CR',
            'description' => 'Sistema contable y ERP para empresas de Costa Rica: contabilidad NIIF, facturación electrónica ante Hacienda, inventario, planillas con la CCSS, bancos e impuestos, con un asistente de inteligencia artificial.',
            'featureList' => [
                'Contabilidad con catálogo configurable y tres monedas',
                'Facturación electrónica ante Hacienda, comprobantes versión 4.4',
                'Inventario con costo promedio, lotes, series y producción',
                'Planillas con cargas sociales de la CCSS e impuesto al salario',
                'Conciliaciones bancarias y proyección de cobros y pagos',
                'Reporte de IVA',
                'Asistente con inteligencia artificial que escanea documentos',
            ],
            'publisher' => ['@id' => Seo::url('/#organizacion')],
        ];
    }
}
