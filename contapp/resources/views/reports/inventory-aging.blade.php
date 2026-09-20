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
        h2 { font-size: 11px; margin: 14px 0 0; }
        .empty { font-style: italic; color: #666; }
        .never { color: #a04000; font-weight: bold; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <h2>Resumen por antigüedad</h2>
    <table class="data">
        <thead>
            <tr>
                @foreach ($result->bucketLabels as $label)
                    <th class="num">{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr>
                @foreach ($result->bucketLabels as $key => $label)
                    <td class="num">{{ $result->bucketTotals[$key] ?? '0.00' }}</td>
                @endforeach
            </tr>
        </tbody>
    </table>

    <h2>Detalle</h2>
    <table class="data">
        <thead>
            <tr>
                <th>Artículo</th>
                <th>Descripción</th>
                <th>Grupo</th>
                <th>Almacén</th>
                <th class="num">Existencia</th>
                <th class="num">Valor local</th>
                <th>Sin rotar desde</th>
                <th class="num">Días</th>
                <th>Tramo</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result->rows as $row)
                <tr>
                    <td>{{ $row->itemCode }}</td>
                    <td>{{ $row->itemName }}</td>
                    <td>{{ $row->itemGroup ?? '—' }}</td>
                    <td>{{ $row->warehouseCode }}</td>
                    <td class="num">{{ $row->quantity }}</td>
                    <td class="num">{{ $row->valueLocal }}</td>
                    <td>
                        {{ $row->sinceDate }}
                        @if ($row->neverIssued)
                            <span class="never">(nunca ha salido)</span>
                        @endif
                    </td>
                    <td class="num">{{ $row->daysIdle }}</td>
                    <td>{{ $result->bucketLabels[$row->bucket] ?? $row->bucket }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="empty">Sin existencias al {{ $result->asOf }}.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total al {{ $result->asOf }}</td>
                <td class="num">{{ $result->totalValueLocal }}</td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
