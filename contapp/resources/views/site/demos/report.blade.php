{{-- Un estado de resultados de ejemplo: las barras crecen al entrar en
     pantalla, y los botones de exportar son los de cada reporte. --}}
@php
    $lines = [
        ['Ingresos por ventas', '48.250.000,00', 100, 'is-income'],
        ['Costo de ventas', '26.410.000,00', 55, 'is-cost'],
        ['Utilidad bruta', '21.840.000,00', 45, 'is-subtotal'],
        ['Gastos de operación', '12.375.000,00', 26, 'is-cost'],
        ['Utilidad neta', '9.465.000,00', 20, 'is-total'],
    ];
@endphp

<div class="demo report-demo" data-demo="report" aria-hidden="true">
    <div class="rd-card">
        <div class="rd-head">
            <span>
                <b>Estado de resultados</b>
                <small>Distribuidora La Sabana · enero a octubre</small>
            </span>
            <span class="rd-exports">
                <span class="rd-export"><x-site.icon name="file-spreadsheet" /> XLSX</span>
                <span class="rd-export"><x-site.icon name="file-text" /> PDF</span>
            </span>
        </div>

        <div class="rd-lines">
            @foreach ($lines as $index => [$label, $amount, $width, $kind])
                <div class="rd-line {{ $kind }}" style="--w: {{ $width }}%; --i: {{ $index }}">
                    <span class="rd-label">{{ $label }}</span>
                    <span class="rd-amount">₡{{ $amount }}</span>
                    <span class="rd-bar"><i></i></span>
                </div>
            @endforeach
        </div>

        <div class="rd-foot">
            <span><x-site.icon name="trending-up" /> Margen neto 19,6 %</span>
            <span>Generado por Ana Mora · hoy</span>
        </div>
    </div>
</div>
