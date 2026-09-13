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
        .is-header td { font-weight: bold; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <table class="data">
        <thead>
            <tr>
                <th>Código</th>
                <th>Descripción</th>
                <th>Tipo</th>
                <th class="num">Saldo inicial</th>
                <th class="num">Débito</th>
                <th class="num">Crédito</th>
                <th class="num">Neto del periodo</th>
                <th class="num">Saldo final</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($result->rows as $row)
                <tr class="{{ $row->isHeader ? 'is-header' : '' }}">
                    <td>{{ $row->code }}</td>
                    <td style="padding-left: {{ 6 + $row->depth * 10 }}px">{{ $row->description }}</td>
                    <td>{{ $row->accountType }}</td>
                    <td class="num">{{ $row->openingBalance }}</td>
                    <td class="num">{{ $row->periodDebit }}</td>
                    <td class="num">{{ $row->periodCredit }}</td>
                    <td class="num">{{ $row->periodNet }}</td>
                    <td class="num">{{ $row->closingBalance }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Totales</td>
                <td></td>
                <td class="num">{{ $result->totalDebit }}</td>
                <td class="num">{{ $result->totalCredit }}</td>
                <td></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
