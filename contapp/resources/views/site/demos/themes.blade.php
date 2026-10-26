{{-- Los temas de compañía (CLAUDE.md secc. 31), con las paletas reales de
     app.scss: data-company-theme en un elemento lo pinta con ese tema. Solo
     y en orden, la maqueta pasa de compañía en compañía con la transición
     del cambio de compañía (secc. 30); tocar un tema lo aplica y frena el
     recorrido. --}}
@php
    $themes = \App\Domains\Core\Support\CompanyTheme::cases();
    $defaultTheme = \App\Domains\Core\Support\CompanyTheme::default();

    $companies = [
        ['name' => 'Distribuidora La Sabana', 'theme' => 'marino'],
        ['name' => 'Agrícola Volcán Azul', 'theme' => 'esmeralda'],
        ['name' => 'Cerámica del Valle', 'theme' => 'terracota'],
        ['name' => 'Estudio Índigo', 'theme' => 'indigo'],
    ];
@endphp

<div class="demo themes-demo" data-demo="themes" data-companies='@json($companies)'>
    <div class="td-preview" data-company-theme="marino" data-theme-preview aria-hidden="true">
        <div class="td-sidebar">
            <span class="td-brand"><img src="{{ asset('images/logos/logo_pivox_corto.png') }}" alt="" width="256" height="256"></span>
            @foreach (['layout-dashboard', 'book-open', 'package', 'receipt', 'users'] as $index => $icon)
                <span @class(['td-nav', 'is-active' => $index === 1])><x-site.icon :name="$icon" :size="14" /></span>
            @endforeach
        </div>

        <div class="td-main">
            <div class="td-topbar">
                <b>Registros</b>
                <span class="td-company"><x-site.icon name="building-2" :size="12" /> <span data-company-name>Distribuidora La Sabana</span></span>
            </div>
            <div class="td-body">
                <div class="td-cards">
                    <span class="td-card"><small>Utilidad del mes</small><b>₡4.825.300,00</b><span class="td-progress"><i></i></span></span>
                    <span class="td-card"><small>Por cobrar</small><b>₡12.140.000,00</b><span class="td-progress is-short"><i></i></span></span>
                </div>
                <div class="td-table">
                    <span class="td-row"><b>AS-0128</b><span>Pago de alquiler</span><em>Contabilizado</em></span>
                    <span class="td-row"><b>AS-0129</b><span>Venta de contado</span><em>Contabilizado</em></span>
                    <span class="td-row"><b>AS-0130</b><span>Compra de mercadería</span><em class="is-draft">Preliminar</em></span>
                    <span class="td-row"><b>AS-0131</b><span>Cobro a cliente</span><em>Contabilizado</em></span>
                </div>
                <span class="td-button">Crear nuevo</span>
            </div>
        </div>

        <div class="td-switch" data-theme-switch>
            <span class="td-switch-mark"><img src="{{ asset('images/logos/logo_pivox_corto.png') }}" alt="" width="256" height="256"></span>
            <small>Cambiando a</small>
            <b data-switch-name>Agrícola Volcán Azul</b>
            <span class="td-switch-bar"><i></i></span>
        </div>
    </div>

    <div class="td-swatches" role="group" aria-label="Probá los temas de Pivox">
        @foreach ($themes as $theme)
            <button
                type="button"
                class="td-swatch"
                data-company-theme="{{ $theme->value }}"
                data-theme-swatch="{{ $theme->value }}"
                aria-pressed="{{ $theme === $defaultTheme ? 'true' : 'false' }}"
                title="{{ $theme->description() }}"
            >
                <span class="td-swatch-colors"><i class="is-primary"></i><i class="is-accent"></i></span>
                <span class="td-swatch-name">{{ $theme->label() }}</span>
            </button>
        @endforeach
    </div>
</div>
