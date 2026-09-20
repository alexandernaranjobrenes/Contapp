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
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        .empty { font-style: italic; color: #666; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <table class="data">
        <thead>
            <tr>
                <th>Artículo</th>
                <th>Descripción</th>
                <th>Grupo</th>
                <th>Almacén</th>
                <th>Unidad</th>
                <th class="num">Existencia</th>
                <th class="num">Costo unitario</th>
                <th class="num">Valor local</th>
                <th class="num">Valor USD</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result->rows as $row)
                <tr>
                    <td>{{ $row->itemCode }}</td>
                    <td>{{ $row->itemName }}</td>
                    <td>{{ $row->itemGroup ?? '—' }}</td>
                    <td>{{ $row->warehouseCode }}</td>
                    <td>{{ $row->uom ?? '—' }}</td>
                    <td class="num">{{ $row->quantity }}</td>
                    <td class="num">{{ $row->unitCostLocal }}</td>
                    <td class="num">{{ $row->valueLocal }}</td>
                    <td class="num">{{ $row->valueForeign }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="empty">Sin existencias al {{ $result->asOf }}.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="7">Total al {{ $result->asOf }}</td>
                <td class="num">{{ $result->totalValueLocal }}</td>
                <td class="num">{{ $result->totalValueForeign }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
