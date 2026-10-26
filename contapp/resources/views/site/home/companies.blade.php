<section id="multiempresa" class="site-section is-alt" aria-labelledby="multiempresa-title">
    <div class="site-container site-split">
        <div class="site-split-copy" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="building-2" /> Multiempresa</p>
            <h2 id="multiempresa-title" class="site-h2">Varias compañías, una sola cuenta</h2>
            <p class="site-lead">
                Pasás de una compañía a otra desde la barra superior. Cada una tiene su catálogo, su moneda y su propio
                tema visual, así nunca trabajás en la equivocada.
            </p>

            <ul class="site-feature-list">
                <li>
                    <span class="site-feature-icon"><x-site.icon name="palette" :size="18" /></span>
                    <span><b>Diez temas, en modo claro y oscuro</b>Cada compañía con sus colores y su tipografía. Probalos en la vista previa.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="file-text" :size="18" /></span>
                    <span><b>El logo de la compañía en cada reporte</b>Encabezado con razón social y cédula jurídica, en PDF y en Excel.</span>
                </li>
                <li>
                    <span class="site-feature-icon"><x-site.icon name="users" :size="18" /></span>
                    <span><b>Tu contador y tu equipo, invitados</b>Cada persona entra con su correo y los permisos que le des en cada compañía.</span>
                </li>
            </ul>
        </div>

        <div class="site-split-visual" data-reveal>
            @include('site.demos.themes')
        </div>
    </div>
</section>
