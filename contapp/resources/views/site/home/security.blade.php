@php
    $items = [
        ['users', 'Roles que no se saltan', 'Superusuario, Administrador y Usuario: nadie puede dar un permiso que no tiene.'],
        ['list-checks', 'Permisos por pantalla', 'Sin acceso, lectura, o lectura y escritura en cada opción del menú.'],
        ['history', 'Bitácora de todo', 'Qué se creó, cambió o eliminó, quién lo hizo, cuándo y desde dónde.'],
        ['lock', 'Lo contabilizado no se borra', 'Se anula con su asiento de reversión: el historial queda completo.'],
        ['hard-drive-download', 'Respaldos cifrados', 'Toda una compañía en un archivo cifrado, para guardarla aparte o moverla de licencia.'],
        ['key-round', 'Nunca perdés tus datos', 'Si la licencia vence, podés seguir consultando y exportando hasta renovarla.'],
    ];
@endphp

<section id="seguridad" class="site-section" aria-labelledby="seguridad-title">
    <div class="site-container">
        <div class="site-section-head" data-reveal>
            <p class="site-eyebrow"><x-site.icon name="shield-check" /> Control y seguridad</p>
            <h2 id="seguridad-title" class="site-h2">Cada quien ve y hace solo lo que le corresponde</h2>
        </div>

        <div class="security-grid">
            @foreach ($items as [$icon, $title, $text])
                <div class="security-item" data-reveal>
                    <span class="security-icon"><x-site.icon :name="$icon" :size="20" /></span>
                    <h3>{{ $title }}</h3>
                    <p>{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>
