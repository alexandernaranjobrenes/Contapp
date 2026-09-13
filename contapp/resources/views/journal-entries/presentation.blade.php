<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.meta { width: 100%; border-collapse: collapse; font-size: 9px; margin-bottom: 10px; }
        table.meta td { padding: 2px 0; }
        table.meta td.label { color: #555; width: 160px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 9px; margin-top: 6px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; padding: 4px 6px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #eee; }
        table.data td.num { text-align: right; }
        .total-row td { font-weight: bold; border-top: 1px solid #999; border-bottom: none; }
        .description-block { margin-bottom: 10px; padding: 6px 8px; background: #f5f5f5; border-left: 3px solid #0B1F3A; }
        .description-label { display: block; font-size: 7px; font-weight: bold; letter-spacing: 0.5px; text-transform: uppercase; color: #555; margin-bottom: 2px; }
        .description { font-size: 10px; font-weight: bold; color: #1a1a1a; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @if ($entry->description)
        <div class="description-block">
            <span class="description-label">Detalle del registro</span>
            <div class="description">{{ $entry->description }}</div>
        </div>
    @endif

    <table class="meta">
        <tr><td class="label">Fecha de contabilización</td><td>{{ $entry->posting_date->format('Y-m-d') }}</td></tr>
        <tr><td class="label">Fecha de documento</td><td>{{ $entry->document_date->format('Y-m-d') }}</td></tr>
    </table>

    <table class="data">
        <tr>
            <th>Cuenta / socio</th>
            <th>Centro de costo</th>
            <th>Moneda</th>
            <th style="text-align: right;">Débito</th>
            <th style="text-align: right;">Crédito</th>
            <th>Descripción</th>
        </tr>
        @php($totalDebit = 0)
        @php($totalCredit = 0)
        @foreach ($entry->details as $detail)
            @php($owner = $detail->businessPartner ? "{$detail->businessPartner->code} — {$detail->businessPartner->name}" : "{$detail->account?->code} — {$detail->account?->description_es}")
            <tr>
                <td>{{ $owner }}</td>
                <td>{{ $detail->costCenter ? "{$detail->costCenter->code} — {$detail->costCenter->name}" : '—' }}</td>
                <td>{{ $detail->currency?->code }}</td>
                <td class="num">{{ number_format((float) $detail->debit_local, 2) }}</td>
                <td class="num">{{ number_format((float) $detail->credit_local, 2) }}</td>
                <td>{{ $detail->description }}</td>
            </tr>
            @php($totalDebit += (float) $detail->debit_local)
            @php($totalCredit += (float) $detail->credit_local)
        @endforeach
        <tr class="total-row">
            <td colspan="3">Total</td>
            <td class="num">{{ number_format($totalDebit, 2) }}</td>
            <td class="num">{{ number_format($totalCredit, 2) }}</td>
            <td></td>
        </tr>
    </table>
</body>
</html>
