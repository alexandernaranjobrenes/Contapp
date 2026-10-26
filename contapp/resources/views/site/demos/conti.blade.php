{{-- Cómo se registra algo con Conti, tal como pasa en la aplicación (CLAUDE.md
     secc. 32): el botón del robot se abre en «Chat» y «Escanear», el chat
     muestra el formulario con los campos «Sugerido», la ventana pide
     confirmar y recién ahí se guarda. Lo anima resources/js/site/demos.js,
     paso a paso (las clases s1, s2… de cada paso alcanzado). --}}
<div class="demo conti-demo" data-demo="conti" aria-hidden="true">
    <div class="demo-frame cd-frame">
        <div class="cd-page">
            <div class="cd-page-bar"><x-site.icon name="book-open" :size="13" /> <b>Registros</b></div>
            <div class="cd-rows">
                @foreach (['AS-0128 · Pago de alquiler', 'AS-0127 · Venta de contado', 'AS-0126 · Compra de suministros', 'AS-0125 · Planilla quincenal', 'AS-0124 · Cobro a cliente'] as $row)
                    <div class="cd-row"><span>{{ $row }}</span><i></i></div>
                @endforeach
            </div>
        </div>

        <div class="cd-dial">
            <span class="cd-option is-chat"><x-site.icon name="messages-square" /><em>Chat</em></span>
            <span class="cd-option is-scan"><x-site.icon name="scan-line" /><em>Escanear</em></span>
            <span class="cd-fab"><x-site.icon name="bot" :size="22" /></span>
        </div>

        <div class="cd-panel">
            <div class="cd-panel-head">
                <span class="cd-avatar"><x-site.icon name="bot" /></span>
                <span class="cd-panel-name"><b>Conti</b><small>Asistente con IA</small></span>
            </div>

            <div class="cd-messages">
                <p class="cd-msg is-bot">Hola, Ana. ¿Qué querés registrar hoy?</p>
                <p class="cd-msg is-user">Registrá el alquiler de octubre: ₡450 000, pagado desde el BCR.</p>
                <p class="cd-typing"><i></i><i></i><i></i></p>
                <p class="cd-msg is-bot is-reply">Listo, te preparé el asiento. Revisá los datos:</p>
                <div class="cd-form">
                    <div class="cd-field"><small>Fecha</small><b>{{ now('America/Costa_Rica')->format('d/m/Y') }}</b></div>
                    <div class="cd-field"><small>Cuenta de gasto</small><b>5-01-07 Alquileres</b><em>Sugerido</em></div>
                    <div class="cd-field"><small>Pagado desde</small><b>Banco BCR ••••4821</b><em>Sugerido</em></div>
                    <div class="cd-field"><small>Monto</small><b>₡450.000,00</b></div>
                    <span class="cd-button is-primary cd-prepare">Preparar</span>
                </div>
            </div>

            <div class="cd-composer">
                <span class="cd-input">
                    <span class="cd-input-text"><span data-type="Registrá el alquiler de octubre: ₡450 000, pagado desde el BCR."></span></span>
                    <span class="cd-placeholder">Escribile a Conti…</span>
                </span>
                <span class="cd-composer-icon"><x-site.icon name="mic" /></span>
                <span class="cd-send"><x-site.icon name="send" /></span>
            </div>
        </div>

        <div class="cd-confirm">
            <div class="cd-confirm-card">
                <b class="cd-confirm-title">Confirmá el asiento</b>
                <div class="cd-lines">
                    <div class="cd-line is-head"><span>Cuenta</span><span>Débito</span><span>Crédito</span></div>
                    <div class="cd-line"><span>5-01-07 Alquileres</span><span>450.000,00</span><span></span></div>
                    <div class="cd-line"><span>1-01-02 Banco BCR</span><span></span><span>450.000,00</span></div>
                </div>
                <div class="cd-confirm-actions">
                    <span class="cd-button is-ghost">Corregir</span>
                    <span class="cd-button is-primary cd-confirm-button">Confirmar y guardar</span>
                </div>
            </div>
        </div>

        <div class="cd-toast"><x-site.icon name="circle-check" /> Asiento AS-0129 contabilizado</div>
    </div>
</div>
