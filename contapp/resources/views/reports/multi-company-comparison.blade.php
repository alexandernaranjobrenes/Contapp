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
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <table class="data">
        <thead>
            <tr>
                <th>Empresa</th>
                <th>Moneda</th>
                <th class="num">Activo</th>
                <th class="num">Pasivo</th>
                <th class="num">Patrimonio</th>
                <th class="num">Ventas del período</th>
                <th class="num">Utilidad neta del período</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result->rows as $row)
                <tr>
                    <td>{{ $row->companyName }}</td>
                    <td>{{ $row->currencyCode }}</td>
                    <td class="num">{{ $row->assetsTotal }}</td>
                    <td class="num">{{ $row->liabilitiesTotal }}</td>
                    <td class="num">{{ $row->equityTotal }}</td>
                    <td class="num">{{ $row->salesTotal }}</td>
                    <td class="num">{{ $row->netProfit }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
