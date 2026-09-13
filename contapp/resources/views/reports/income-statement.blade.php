<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 6px; }
        table.data td { padding: 3px 6px; }
        table.data td.num { text-align: right; }
        .section-label { font-weight: bold; background: #eee; }
        .section-total { font-weight: bold; border-top: 1px solid #999; }
        .final-total { font-weight: bold; background: #0B1F3A; color: #fff; font-size: 11px; }
        .is-header td { font-weight: bold; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @php
        $sections = [
            ['label' => 'Ventas', 'lines' => $result->sales, 'total' => $result->salesTotal],
            ['label' => 'Costo de ventas', 'lines' => $result->costOfSales, 'total' => $result->costOfSalesTotal],
        ];
        $sections2 = [
            ['label' => 'Gastos operativos', 'lines' => $result->operatingExpenses, 'total' => $result->operatingExpensesTotal],
        ];
        $sections3 = [
            ['label' => 'Otros ingresos', 'lines' => $result->otherIncome, 'total' => $result->otherIncomeTotal],
            ['label' => 'Otros gastos', 'lines' => $result->otherExpense, 'total' => $result->otherExpenseTotal],
        ];
    @endphp

    <table class="data">
        @foreach ($sections as $section)
            <tr class="section-label"><td colspan="2">{{ $section['label'] }}</td><td class="num"></td></tr>
            @foreach ($section['lines'] as $line)
                <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
            @endforeach
            <tr class="section-total"><td colspan="2">Total {{ mb_strtolower($section['label']) }}</td><td class="num">{{ $section['total'] }}</td></tr>
        @endforeach

        <tr class="section-total"><td colspan="2">Utilidad bruta</td><td class="num">{{ $result->grossProfit }}</td></tr>

        @foreach ($sections2 as $section)
            <tr class="section-label"><td colspan="2">{{ $section['label'] }}</td><td class="num"></td></tr>
            @foreach ($section['lines'] as $line)
                <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
            @endforeach
            <tr class="section-total"><td colspan="2">Total {{ mb_strtolower($section['label']) }}</td><td class="num">{{ $section['total'] }}</td></tr>
        @endforeach

        <tr class="section-total"><td colspan="2">Utilidad operativa</td><td class="num">{{ $result->operatingProfit }}</td></tr>

        @foreach ($sections3 as $section)
            <tr class="section-label"><td colspan="2">{{ $section['label'] }}</td><td class="num"></td></tr>
            @foreach ($section['lines'] as $line)
                <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
            @endforeach
            <tr class="section-total"><td colspan="2">Total {{ mb_strtolower($section['label']) }}</td><td class="num">{{ $section['total'] }}</td></tr>
        @endforeach

        <tr class="final-total"><td colspan="2">Utilidad neta del período</td><td class="num">{{ $result->netProfit }}</td></tr>
    </table>
</body>
</html>
