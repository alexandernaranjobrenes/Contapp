<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        h2.group-title { font-size: 11px; background: #E9EDF5; padding: 4px 6px; margin: 12px 0 4px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 4px 6px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        p.grand-total { text-align: right; font-weight: bold; margin-top: 10px; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @forelse ($result->groups as $group)
        <h2 class="group-title">{{ $group->costCenterCode }} — {{ $group->costCenterName }}</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Cuenta</th>
                    <th>Descripción</th>
                    <th class="num">Débito</th>
                    <th class="num">Crédito</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group->lines as $line)
                    <tr>
                        <td>{{ $line->accountCode }}</td>
                        <td>{{ $line->accountDescription }}</td>
                        <td class="num">{{ $line->debit }}</td>
                        <td class="num">{{ $line->credit }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Subtotal</td>
                    <td class="num">{{ $group->totalDebit }}</td>
                    <td class="num">{{ $group->totalCredit }}</td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p>Sin movimientos con centro de costo asignado para el período seleccionado.</p>
    @endforelse

    <p class="grand-total">Totales: débito {{ $result->grandTotalDebit }} — crédito {{ $result->grandTotalCredit }}</p>
</body>
</html>
