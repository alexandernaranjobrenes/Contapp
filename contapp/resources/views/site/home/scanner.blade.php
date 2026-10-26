<section id="escanear" class="site-section" aria-labelledby="escanear-title">
    <div class="site-container site-split is-reversed">
        <div class="site-split-copy" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="scan-line" /> Escanear con Conti</p>
            <h2 id="escanear-title" class="site-h2">Una foto de la factura, y el registro listo para revisar</h2>
            <p class="site-lead">
                En la computadora aparece un código QR: lo escaneás con el teléfono, tomás la foto y Conti llena
                el formulario con lo que leyó del documento.
            </p>

            <ul class="site-feature-list">
                <li>
                    <span class="site-feature-icon"><x-site.icon name="receipt" :size="18" /></span>
                    <span><b>Facturas, cotizaciones, proveedores y pagos</b>Una factura de gasto o compra, la cotización de un proveedor, los datos de un cliente nuevo o un comprobante de pago.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="qr-code" :size="18" /></span>
                    <span><b>Sin iniciar sesión en el teléfono</b>El código sirve una sola vez y durante 10 minutos.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="shield-check" :size="18" /></span>
                    <span><b>Las fotos no se guardan</b>Se leen y se descartan. Lo leído llega marcado «Del documento», para que lo revisés antes de enviarlo.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="scan-barcode" :size="18" /></span>
                    <span><b>También lee códigos de barras</b>Con la cámara del teléfono o con un lector USB, para encontrar un artículo o completar su ficha.</span>
                </li>
            </ul>

            @include('site.demos.barcode')
        </div>

        <div class="site-split-visual" data-reveal>
            @include('site.demos.scanner')
        </div>
    </div>
</section>
