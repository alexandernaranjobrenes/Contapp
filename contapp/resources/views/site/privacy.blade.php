@extends('site.layout')

@php($contact = config('site.contact_email'))

@section('content')
    <section class="site-section legal-page">
        <div class="site-container legal-container">
            <nav class="site-breadcrumbs" aria-label="Migas de pan">
                <a href="{{ route('home') }}">Inicio</a>
                <x-site.icon name="chevron-right" />
                <span aria-current="page">Privacidad</span>
            </nav>

            <h1 class="site-h1">Privacidad</h1>
            <p class="legal-updated">Última actualización: {{ $updatedAt }}</p>

            <div class="article-content">
                <p>
                    Esta página explica qué datos recoge el sitio de Pivox, para qué los usamos y cómo podés pedir que
                    los corrijamos o los borremos, según la Ley de Protección de la Persona frente al Tratamiento de sus
                    Datos Personales (Ley 8968).
                </p>

                <h2>Qué datos recogemos</h2>
                <ul>
                    <li>
                        <strong>Los del formulario de solicitud de licencia:</strong> tu nombre, el de tu compañía, la
                        cédula (si la escribís), tu correo, tu teléfono, tu mensaje y las funciones que elegiste.
                    </li>
                    <li>
                        <strong>Datos técnicos de la visita:</strong> la dirección IP y el navegador desde el que enviás
                        el formulario, para proteger el sitio de abusos.
                    </li>
                </ul>

                <h2>Para qué los usamos</h2>
                <p>
                    Solo para responder tu solicitud y, si seguimos adelante, preparar tu licencia. No los vendemos, no
                    los compartimos con terceros para publicidad y no te enviamos correos que no hayas pedido.
                </p>

                <h2>Cuánto tiempo los guardamos</h2>
                <p>
                    Mientras atendemos tu solicitud y el tiempo que exija la ley. Podés pedir que los borremos antes,
                    si no hay una licencia de por medio.
                </p>

                <h2>Tus derechos</h2>
                <p>
                    Podés pedirnos ver los datos que tenemos de vos, corregirlos o borrarlos. Escribinos a
                    <a href="mailto:{{ $contact }}">{{ $contact }}</a> desde el correo con el que enviaste la
                    solicitud.
                </p>

                <h2>Cookies y almacenamiento del navegador</h2>
                <p>
                    El sitio usa solo las cookies necesarias para funcionar: la de la sesión y la que protege el
                    formulario. Además guarda en tu navegador si preferís el modo claro o el oscuro. No usamos cookies
                    de publicidad ni de seguimiento.
                </p>

                <h2>La información que registrás en Pivox</h2>
                <p>
                    Los datos contables que tu empresa registra dentro de Pivox son de tu empresa, y se tratan según las
                    condiciones de tu licencia. Esta página trata solo del sitio público.
                </p>
            </div>
        </div>
    </section>
@endsection
