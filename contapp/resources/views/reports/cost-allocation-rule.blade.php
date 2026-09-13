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
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @forelse ($result->groups as $group)
        <h2 class="group-title">{{ $group->ruleCode }} — {{ $group->ruleName }}</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Centro de costo</th>
                    <th class="num">% definido</th>
                    <th class="num">Monto real</th>
                    <th class="num">% real</th>
                    <th class="num">Variación (p.p.)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group->lines as $line)
                    <tr>
                        <td>{{ $line->costCenterCode }} — {{ $line->costCenterName }}</td>
                        <td class="num">{{ $line->definedPercentage }}</td>
                        <td class="num">{{ $line->actualAmount }}</td>
                        <td class="num">{{ $line->actualPercentage ?? '—' }}</td>
                        <td class="num">{{ $line->variancePercentagePoints ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total distribuido</td>
                    <td class="num">{{ $group->totalAmount }}</td>
                    <td></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    @empty
        <p>Sin movimientos generados por normas de reparto para el período seleccionado.</p>
    @endforelse
</body>
</html>
