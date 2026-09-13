<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 8px; margin-top: 6px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 5px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        .section-title { font-weight: bold; font-size: 13px; margin-top: 16px; }
        .currency-label { font-weight: bold; font-size: 11px; margin-top: 10px; }
        .empty { color: #666; font-style: italic; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @foreach (['collections' => 'Cobros esperados (clientes)', 'payments' => 'Pagos esperados (proveedores)'] as $key => $label)
        <div class="section-title">{{ $label }}</div>

        @if (count($result->{$key}) === 0)
            <p class="empty">Sin partidas pendientes.</p>
        @endif

        @foreach ($result->{$key} as $group)
            <div class="currency-label">Moneda: {{ $group->currencyCode }}</div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Socio</th>
                        <th>Nombre</th>
                        @foreach ($result->bucketLabels as $bucketLabel)
                            <th class="num">{{ $bucketLabel }}</th>
                        @endforeach
                        <th class="num">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($group->rows as $row)
                        <tr>
                            <td>{{ $row->partnerCode }}</td>
                            <td>{{ $row->partnerName }}</td>
                            @foreach ($result->bucketLabels as $bucketKey => $bucketLabel)
                                <td class="num">{{ $row->buckets[$bucketKey] }}</td>
                            @endforeach
                            <td class="num">{{ $row->total }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2">Totales</td>
                        @foreach ($result->bucketLabels as $bucketKey => $bucketLabel)
                            <td class="num">{{ $group->bucketTotals[$bucketKey] }}</td>
                        @endforeach
                        <td class="num">{{ $group->total }}</td>
                    </tr>
                </tfoot>
            </table>
        @endforeach
    @endforeach
</body>
</html>
