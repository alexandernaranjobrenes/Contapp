<section id="conti" class="site-section is-alt" aria-labelledby="conti-title">
    <div class="site-container site-split">
        <div class="site-split-copy" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="bot" /> Conti, el asistente</p>
            <h2 id="conti-title" class="site-h2">Pedile a Conti lo que necesités, con tus palabras</h2>
            <p class="site-lead">
                Conti consulta tus datos, corre los reportes y prepara los registros por vos.
                Nada se guarda hasta que lo confirmás.
            </p>

            <ul class="site-feature-list">
                <li>
                    <span class="site-feature-icon"><x-site.icon name="messages-square" :size="18" /></span>
                    <span><b>Preguntá como le preguntarías a tu contador</b>Saldos, partidas abiertas, reportes y movimientos, en una conversación.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="sparkles" :size="18" /></span>
                    <span><b>Registra con formularios en el chat</b>Asientos, socios, pagos, órdenes de compra y movimientos de planilla, con campos sugeridos según cómo trabaja tu compañía.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="shield-check" :size="18" /></span>
                    <span><b>Ve solo lo que podés ver</b>Respeta tus permisos por pantalla y nunca ve correos, teléfonos ni datos personales.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="mic" :size="18" /></span>
                    <span><b>Dictale en vez de escribir</b>La voz se convierte en texto en tu navegador, y la revisás antes de enviarla.</span>
                </li>
            </ul>
        </div>

        <div class="site-split-visual" data-reveal>
            @include('site.demos.conti')
        </div>
    </div>
</section>
