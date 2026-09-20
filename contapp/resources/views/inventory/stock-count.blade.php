<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 10px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 4px 6px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        /* La columna donde se escribe a mano: alta y en blanco, para que se
           pueda anotar con lapicero sobre el papel. */
        table.data td.write { height: 20px; background: #fffdf2; }
        .aviso { font-size: 9px; color: #555; margin-top: 8px; }
        .firmas { margin-top: 28px; font-size: 9px; width: 100%; }
        .firmas td { padding-top: 26px; border-top: 1px solid #555; width: 33%; text-align: center; }
        .firmas .sep { border: none; width: 20px; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <table class="data">
        <thead>
            <tr>
                <th style="width: 26px;">#</th>
                <th>Artículo</th>
                @if ($count->lines->contains(fn ($line) => $line->warehouse_bin_id !== null))
                    <th style="width: 60px;">Ubicación</th>
                @endif
                @unless ($count->blind)
                    <th class="num" style="width: 70px;">Existencia<br>del sistema</th>
                @endunless
                <th class="num" style="width: 90px;">Cantidad contada</th>
                <th style="width: 120px;">Observaciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($count->lines as $line)
                <tr>
                    <td class="num">{{ $line->line_number }}</td>
                    <td>{{ $line->item?->code }} — {{ $line->item?->name }}</td>
                    @if ($count->lines->contains(fn ($l) => $l->warehouse_bin_id !== null))
                        <td>{{ $line->warehouseBin?->code }}</td>
                    @endif
                    @unless ($count->blind)
                        <td class="num">{{ rtrim(rtrim(number_format((float) $line->theoretical_quantity, 6, '.', ''), '0'), '.') ?: '0' }}</td>
                    @endunless
                    {{-- Si ya se capturó, la hoja sirve de respaldo de lo contado;
                         si no, sale en blanco para escribir encima. --}}
                    <td class="num write">
                        {{ $line->counted_quantity === null ? '' : rtrim(rtrim(number_format((float) $line->counted_quantity, 6, '.', ''), '0'), '.') }}
                    </td>
                    <td class="write"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="aviso">
        @if ($count->blind)
            Conteo a ciegas: esta hoja no muestra la existencia del sistema a propósito. Anote lo que realmente
            encuentre, incluso si es cero.
        @else
            Anote la cantidad que realmente encuentre, incluso si es cero. La diferencia contra la existencia del
            sistema se calcula al capturar el conteo.
        @endif
        Total de líneas por contar: {{ $count->lines->count() }}.
    </p>

    <table class="firmas">
        <tr>
            <td>Contó</td>
            <td class="sep"></td>
            <td>Revisó</td>
            <td class="sep"></td>
            <td>Autorizó</td>
        </tr>
    </table>
</body>
</html>
