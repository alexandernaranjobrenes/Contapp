<section id="facturacion-electronica" class="site-section is-alt" aria-labelledby="factura-title">
    <div class="site-container site-split">
        <div class="site-split-copy" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="receipt" /> Facturación electrónica</p>
            <h2 id="factura-title" class="site-h2">Emitís, y Pivox sigue el comprobante hasta que Hacienda responde</h2>
            <p class="site-lead">
                Cada comprobante se firma con la llave de tu compañía, se envía a Hacienda y se consulta solo, con esperas
                cada vez más largas. Cuando Hacienda lo acepta, al cliente le llegan el PDF y el XML.
            </p>

            <ul class="site-feature-list">
                <li>
                    <span class="site-feature-icon"><x-site.icon name="shield-check" :size="18" /></span>
                    <span><b>Nunca dos comprobantes por una venta</b>Ante una respuesta dudosa, primero se consulta si Hacienda ya lo tiene.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="list-checks" :size="18" /></span>
                    <span><b>Revisa antes de emitir</b>Un CAByS que no existe o una actividad no inscrita se corrigen antes de gastar el consecutivo.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="key-round" :size="18" /></span>
                    <span><b>Las credenciales de cada compañía, cifradas</b>Las carga solo su Superusuario: el usuario del ATV y la llave criptográfica.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="refresh-cw" :size="18" /></span>
                    <span><b>Seguí con tu numeración</b>Consecutivos por sucursal y terminal, para continuar los que traías de TribuCR.</span>
                </li>
            </ul>
        </div>

        <div class="site-split-visual" data-reveal>
            @include('site.demos.einvoice')
        </div>
    </div>
</section>
