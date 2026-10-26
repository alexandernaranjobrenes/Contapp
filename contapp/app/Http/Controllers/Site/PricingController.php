<?php

namespace App\Http\Controllers\Site;

use App\Domains\Pricing\Models\PricingSetting;
use App\Domains\Pricing\Support\PricingCalculator;
use App\Domains\Pricing\Support\PricingCatalog;
use App\Domains\Pricing\Support\PricingMoney;
use App\Domains\Site\Support\Seo;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * Planes y precios (CLAUDE.md secc. 42): los paquetes que armó el backoffice
 * y el configurador para ajustarlos. Las tarjetas de los paquetes y las
 * preguntas frecuentes salen del servidor (las lee un buscador); el
 * configurador es una isla de Vue que recibe el catálogo en JSON.
 */
class PricingController extends Controller
{
    public function index(Request $request): View
    {
        $settings = PricingSetting::current();
        $catalog = PricingCatalog::forSite($settings);
        $features = collect($catalog['features'])->keyBy('key');
        $currency = $settings->default_currency;

        $cards = collect($catalog['packages'])->map(function (array $package) use ($catalog, $features, $currency, $settings) {
            $cycle = $package['billing'] === 'annual' ? 'annual' : 'monthly';
            $quote = PricingCalculator::quote($catalog, $package['slug'], (array) $package['features'], $cycle, $currency);

            // Lo que cuesta el paquete: sin IVA si los precios no lo incluyen.
            $price = $settings->prices_include_tax ? $quote['total'] : $quote['net'];

            return $package + [
                'cycle' => $cycle,
                'price' => PricingMoney::format($price, $currency),
                'price_cents' => $price,
                'items' => collect($quote['lines'])->map(fn (array $line) => $line['kind'] === 'quantity'
                    ? "{$line['quantity']} {$line['unit_label']}"
                    : $line['name'])->all(),
            ];
        });

        $faq = $this->faq($settings);

        $schema = [Seo::organization(), Seo::breadcrumbs([['Inicio', Seo::url('/')], ['Planes y precios', Seo::route('pricing.index')]])];

        if ($faq !== []) {
            $schema[] = [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn (array $item) => [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
                ], $faq),
            ];
        }

        if ($cards->isNotEmpty()) {
            $schema[] = [
                '@type' => 'SoftwareApplication',
                'name' => Seo::SITE_NAME,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'offers' => [
                    '@type' => 'AggregateOffer',
                    'priceCurrency' => $currency,
                    'lowPrice' => round($cards->min('price_cents') / 100, 2),
                    'highPrice' => round($cards->max('price_cents') / 100, 2),
                    'offerCount' => $cards->count(),
                ],
            ];
        }

        return view('site.pricing', [
            'seo' => new Seo(
                title: Seo::title('Planes y precios'),
                description: 'Armá tu plan de Pivox: elegí un paquete y agregá o quitá funciones para ajustarlo a tu presupuesto. Por mes o por año, en colones o en dólares.',
                canonical: Seo::route('pricing.index'),
                schema: $schema,
            ),
            'catalog' => $catalog,
            'cards' => $cards,
            'settings' => $settings,
            'faq' => $faq,
            'preselect' => is_string($request->query('paquete')) ? $request->query('paquete') : null,
            // El momento en que se abrió la página, cifrado: un robot envía el
            // formulario en menos de un segundo (LicenseRequestController).
            'started' => Crypt::encryptString((string) now()->timestamp),
        ]);
    }

    /** @return list<array{question: string, answer: string}> */
    private function faq(PricingSetting $settings): array
    {
        $rate = rtrim(rtrim(number_format((float) $settings->tax_rate, 2, ',', '.'), '0'), ',');

        return [
            [
                'question' => '¿Cómo se paga?',
                'answer' => 'Por mes o por año, según el paquete. Después de enviar tu solicitud te escribimos para coordinar el pago y la activación de tu licencia.',
            ],
            [
                'question' => '¿Los precios incluyen el IVA?',
                'answer' => $settings->prices_include_tax
                    ? "Sí: los precios ya incluyen el IVA ({$rate} %)."
                    : "No: al precio se le suma el IVA ({$rate} %). La cotización lo muestra aparte.",
            ],
            [
                'question' => '¿Puedo cambiar mi plan después?',
                'answer' => 'Sí. Si necesitás más compañías, más usuarios u otra función, escribinos y ajustamos tu licencia.',
            ],
            [
                'question' => '¿Qué pasa si se vence mi licencia?',
                'answer' => 'Nunca perdés tus datos: entrás en modo de gracia, donde podés consultar y exportar toda tu información, pero no registrar nada nuevo hasta renovarla.',
            ],
            [
                'question' => '¿Cómo activo mi licencia?',
                'answer' => 'Te enviamos un código de licencia. Con él creás tu cuenta y tu primera compañía en pivoxcr.com/activate, o aceptás el enlace que te llega al correo.',
            ],
        ];
    }
}
