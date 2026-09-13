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
        .warning { color: #cc0000; font-weight: bold; }
        .is-header td { font-weight: bold; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    <table class="data">
        <tr class="section-label"><td colspan="2">Activo</td><td class="num"></td></tr>
        @foreach ($result->assets as $line)
            <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
        @endforeach
        <tr class="section-total"><td colspan="2">Total activo</td><td class="num">{{ $result->assetsTotal }}</td></tr>
    </table>

    <table class="data">
        <tr class="section-label"><td colspan="2">Pasivo</td><td class="num"></td></tr>
        @foreach ($result->liabilities as $line)
            <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
        @endforeach
        <tr class="section-total"><td colspan="2">Total pasivo</td><td class="num">{{ $result->liabilitiesTotal }}</td></tr>
    </table>

    <table class="data">
        <tr class="section-label"><td colspan="2">Patrimonio</td><td class="num"></td></tr>
        @foreach ($result->equity as $line)
            <tr class="{{ $line->isHeader ? 'is-header' : '' }}"><td>{{ $line->code }}</td><td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td><td class="num">{{ $line->amount }}</td></tr>
        @endforeach
        <tr><td colspan="2">Utilidad (pérdida) del ejercicio</td><td class="num">{{ $result->currentYearEarnings }}</td></tr>
        <tr class="section-total"><td colspan="2">Total patrimonio</td><td class="num">{{ $result->totalEquityAndEarnings }}</td></tr>
    </table>

    <table class="data">
        <tr class="final-total"><td colspan="2">Total pasivo + patrimonio</td><td class="num">{{ $result->totalLiabilitiesAndEquity }}</td></tr>
        @unless ($result->isBalanced)
            <tr><td colspan="3" class="warning">⚠ El activo no cuadra contra pasivo + patrimonio — revisar.</td></tr>
        @endunless
    </table>
</body>
</html>
