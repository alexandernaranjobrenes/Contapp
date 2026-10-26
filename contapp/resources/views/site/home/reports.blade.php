@php
    $reports = [
        'Balance general', 'Estado de resultados', 'Balance de comprobación', 'Libros mayor y diario',
        'Comparativo entre períodos', 'Comparativo de empresas', 'Antigüedad de saldos', 'Proyección de cobros y pagos',
        'Reporte de IVA', 'Planilla de la CCSS', 'Existencias valorizadas', 'Análisis ABC',
    ];
@endphp

<section id="reportes" class="site-section" aria-labelledby="reportes-title">
    <div class="site-container site-split is-reversed">
        <div class="site-split-copy" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="chart-column" /> Reportes</p>
            <h2 id="reportes-title" class="site-h2">Tus números, listos para presentar</h2>
            <p class="site-lead">
                Cada reporte vive en su módulo, se consulta con los filtros que necesités y sale a Excel o a PDF con el
                logo y los datos de tu compañía.
            </p>

            <ul class="site-chip-list">
                @foreach ($reports as $report)
                    <li>{{ $report }}</li>
                @endforeach
            </ul>
        </div>

        <div class="site-split-visual" data-reveal>
            @include('site.demos.report')
        </div>
    </div>
</section>
