<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 8px; margin-top: 10px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 5px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        .formula { font-size: 9px; color: #444; margin-top: 8px; }
        .empty { font-style: italic; color: #666; }
        .not-purchase { color: #a04000; }
        .inherited { color: #666; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <p class="formula">Disponible = existencia − apartado + en camino.</p>

    <table class="data">
        <thead>
            <tr>
                <th>Artículo</th>
                <th>Descripción</th>
                <th>Almacén</th>
                <th>U/M</th>
                <th class="num">Exist.</th>
                <th class="num">Apart.</th>
                <th class="num">Camino</th>
                <th class="num">Dispon.</th>
                <th class="num">Mínimo</th>
                <th class="num">Sugerido</th>
                <th class="num">Costo estimado</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suggestions as $s)
                <tr>
                    <td>
                        {{ $s['item_code'] }}
                        @if (! $s['is_purchase_item'])
                            <span class="not-purchase">(se fabrica)</span>
                        @endif
                    </td>
                    <td>{{ $s['item_name'] }}</td>
                    <td>{{ $s['warehouse_code'] }}</td>
                    <td>{{ $s['uom'] ?? '—' }}</td>
                    <td class="num">{{ $s['on_hand'] }}</td>
                    <td class="num">{{ $s['reserved'] }}</td>
                    <td class="num">{{ $s['ordered'] }}</td>
                    <td class="num">{{ $s['available'] }}</td>
                    <td class="num">
                        {{ $s['minimum_stock'] }}
                        @if (! $s['minimum_is_override'])
                            <span class="inherited">(ficha)</span>
                        @endif
                    </td>
                    <td class="num">{{ $s['suggested_quantity'] }}</td>
                    <td class="num">{{ $s['estimated_cost'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="11" class="empty">
                        No hay nada que comprar: ningún artículo con mínimo configurado cayó a su nivel.
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="10">Total estimado</td>
                <td class="num">{{ $estimatedTotal }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
