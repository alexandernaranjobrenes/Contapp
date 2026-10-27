<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {{--
        Un documento del Repositorio (RepositoryPdf). $body es el Markdown ya
        pasado a HTML, sin HTML crudo ni imágenes. El número de página lo
        dibuja RepositoryPdf sobre cada hoja.
    --}}
    <style>
        @page { margin: 54px 58px 66px 58px; }
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; font-size: 10.5px; line-height: 1.55; }

        .masthead { width: 100%; border-collapse: collapse; }
        .masthead td { padding: 0 0 8px; vertical-align: bottom; }
        /* La línea va aparte: como borde de las celdas, dompdf la corta entre una y otra. */
        .masthead-rule { margin: 0 0 18px; border-top: 1px solid #dde3ea; }
        .masthead .brand { font-size: 15px; font-weight: bold; color: #0b1f3a; }
        .masthead .section { text-align: right; font-size: 8.5px; color: #5b6b80; text-transform: uppercase; letter-spacing: 0.6px; }

        .doc-title { margin: 0 0 4px; font-size: 20px; line-height: 1.25; color: #0b1f3a; }
        .doc-meta { margin: 0 0 18px; font-size: 8.5px; color: #5b6b80; }

        .content h1 { margin: 18px 0 8px; font-size: 17px; line-height: 1.3; color: #0b1f3a; }
        .content h2 { margin: 16px 0 7px; padding-bottom: 3px; font-size: 14px; line-height: 1.3; color: #0b1f3a; border-bottom: 1px solid #dde3ea; }
        .content h3 { margin: 14px 0 5px; font-size: 12px; color: #0b1f3a; }
        .content h4, .content h5, .content h6 { margin: 12px 0 4px; font-size: 10.5px; color: #26364d; }
        .content p { margin: 0 0 8px; text-align: justify; }
        .content ul, .content ol { margin: 0 0 8px 0; padding-left: 18px; }
        .content li { margin-bottom: 3px; }
        .content blockquote { margin: 8px 0 10px; padding: 6px 10px; border-left: 3px solid #1d4ed8; background: #f4f7fb; color: #33415c; }
        .content blockquote p:last-child { margin-bottom: 0; }
        .content table { width: 100%; margin: 8px 0 10px; border-collapse: collapse; font-size: 9.5px; }
        .content th, .content td { padding: 4px 6px; border: 1px solid #cfd8e3; text-align: left; vertical-align: top; }
        .content th { background: #eef2f7; }
        .content code { font-family: 'DejaVu Sans Mono', monospace; font-size: 9px; background: #f1f4f8; padding: 1px 3px; }
        .content pre { margin: 8px 0 10px; padding: 8px; background: #f1f4f8; white-space: pre-wrap; font-size: 9px; }
        .content pre code { padding: 0; background: none; }
        .content hr { margin: 12px 0; border: 0; border-top: 1px solid #dde3ea; }
        .content a { color: #1d4ed8; text-decoration: none; }

        .footer { position: fixed; bottom: -40px; left: 0; right: 120px; font-size: 7px; color: #737373; }
    </style>
</head>
<body>
    <div class="footer">Pivox · Biblioteca — {{ \Illuminate\Support\Str::limit($title, 90) }}</div>

    <table class="masthead">
        <tr>
            <td>
                @if ($logo)
                    <img src="{{ $logo }}" alt="Pivox" style="height: 24px;">
                @else
                    <span class="brand">Pivox</span>
                @endif
            </td>
            <td class="section">Biblioteca{{ $folder ? ' · '.$folder : '' }}</td>
        </tr>
    </table>
    <div class="masthead-rule"></div>

    <h1 class="doc-title">{{ $title }}</h1>
    @if ($updatedAt)
        <p class="doc-meta">Actualizado el {{ $updatedAt->locale('es')->translatedFormat('j \d\e F \d\e Y') }}</p>
    @endif

    <div class="content">{!! $body !!}</div>
</body>
</html>
