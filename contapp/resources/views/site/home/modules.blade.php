{{-- Los módulos, con los mismos íconos del menú lateral de la aplicación
     (CLAUDE.md secc. 23). Cada punto es algo que el sistema ya hace (ver el
     manual de Conti, resources/conti/manual.md). --}}
@php
    $modules = [
        ['icon' => 'book-open', 'name' => 'Contabilidad', 'points' => [
            'Catálogo de cuentas en 8 clases, configurable por compañía',
            'Asientos con tres fechas y montos en tres monedas',
            'Asientos recurrentes, saldos iniciales desde Excel y cierre de períodos',
            'Balance de comprobación, estado de resultados, balance general y comparativos',
        ]],
        ['icon' => 'tags', 'name' => 'Centros de costo y cambiario', 'points' => [
            'Tipo de cambio del BCCR todos los días, sin que nadie lo cargue',
            'Diferencial cambiario de clientes, proveedores y cuentas',
            'Normas de reparto por centro de costo, con su reporte de cumplimiento',
        ]],
        ['icon' => 'handshake', 'name' => 'Socios de negocio', 'points' => [
            'Clientes y proveedores con su cuenta de control',
            'Partidas abiertas, cobros, pagos y reconciliación entre documentos',
            'Antigüedad de saldos con los cortes que elijás',
            'Consulta de la cédula en Hacienda desde la ficha',
        ]],
        ['icon' => 'landmark', 'name' => 'Bancos', 'points' => [
            'Conciliaciones bancarias con el saldo de libros calculado solo',
            'Reporte de conciliaciones por cuenta y por mes',
            'Proyección de cobros y pagos según los vencimientos',
        ]],
        ['icon' => 'package', 'name' => 'Inventario', 'points' => [
            'Costo promedio ponderado, con un kardex que no se edita',
            'Lotes, series, ubicaciones, tomas físicas y códigos de barras',
            'Órdenes de compra, importaciones con DUA y rubros de nacionalización',
            'Producción con listas de materiales y merma',
        ]],
        ['icon' => 'receipt', 'name' => 'Facturación', 'points' => [
            'Facturas, tiquetes, notas de crédito y débito, y exportación',
            'Firmadas y enviadas a Hacienda, comprobantes versión 4.4',
            'Catálogo CAByS completo y actividades económicas oficiales',
            'Un precio fuera de lista solo con la autorización de un administrador',
        ]],
        ['icon' => 'users', 'name' => 'Planillas', 'points' => [
            'Cargas sociales de la CCSS e impuesto al salario',
            'Vacaciones, aguinaldo, préstamos y deducciones',
            'Liquidaciones laborales según el Código de Trabajo',
            'Comprobantes de pago por correo y archivo para el banco',
        ]],
        ['icon' => 'percent', 'name' => 'Impuestos', 'points' => [
            'Indicadores de IVA nacionales y propios, con sus vigencias',
            'Reporte de IVA por clasificación, base gravable e impuesto',
            'Un período ya declarado no cambia por una corrección posterior',
        ]],
        ['icon' => 'library', 'name' => 'Biblioteca', 'points' => [
            'Leyes, reglamentos y resoluciones de Hacienda, en PDF',
            'Agrupados en carpetas y con buscador',
            'Conti los consulta y cita de dónde sale cada respuesta',
        ]],
    ];
@endphp

<section id="funciones" class="site-section" aria-labelledby="funciones-title">
    <div class="site-container">
        <div class="site-section-head" data-reveal>
            <p class="site-eyebrow">Funciones</p>
            <h2 id="funciones-title" class="site-h2">Todo lo que tu empresa necesita, conectado</h2>
            <p class="site-lead">
                Cada movimiento genera su asiento en el momento: una factura, una entrada de mercancía o una planilla
                llegan a la contabilidad sin volver a digitarlas.
            </p>
        </div>

        <div class="module-grid">
            @foreach ($modules as $module)
                <article class="module-card" data-reveal>
                    <span class="module-icon"><x-site.icon :name="$module['icon']" :size="22" /></span>
                    <h3>{{ $module['name'] }}</h3>
                    <ul>
                        @foreach ($module['points'] as $point)
                            <li><x-site.icon name="check" />{{ $point }}</li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </div>
</section>
