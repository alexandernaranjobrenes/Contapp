<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        h2.group-title { font-size: 12px; background: #E9EDF5; padding: 5px 6px; margin: 14px 0 6px; }
        h3.section-title { font-size: 10px; font-weight: bold; margin: 8px 0 3px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 6px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 4px 6px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        .is-header td { font-weight: bold; }
        p.periods { font-weight: bold; margin: 10px 0; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <p class="periods">Periodo 1: {{ $result->from1 }} al {{ $result->to1 }} &nbsp;·&nbsp; Periodo 2: {{ $result->from2 }} al {{ $result->to2 }}</p>

    <h2 class="group-title">Balance general</h2>
    @include('reports.partials.period-comparison-section', ['label' => 'Activo', 'lines' => $result->assets, 'total' => $result->assetsTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Pasivo', 'lines' => $result->liabilities, 'total' => $result->liabilitiesTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Patrimonio', 'lines' => $result->equity, 'total' => $result->equityTotal])

    <h2 class="group-title">Estado de resultados</h2>
    @include('reports.partials.period-comparison-section', ['label' => 'Ventas', 'lines' => $result->sales, 'total' => $result->salesTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Costo de ventas', 'lines' => $result->costOfSales, 'total' => $result->costOfSalesTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Gastos operativos', 'lines' => $result->operatingExpenses, 'total' => $result->operatingExpensesTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Otros ingresos', 'lines' => $result->otherIncome, 'total' => $result->otherIncomeTotal])
    @include('reports.partials.period-comparison-section', ['label' => 'Otros gastos', 'lines' => $result->otherExpense, 'total' => $result->otherExpenseTotal])

    <table class="data">
        <tbody>
            <tr>
                <td>Utilidad neta</td>
                <td class="num">{{ $result->netProfit->period1 }}</td>
                <td class="num">{{ $result->netProfit->period2 }}</td>
                <td class="num">{{ $result->netProfit->variance }}</td>
                <td class="num">{{ $result->netProfit->variancePercent ?? '—' }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>
