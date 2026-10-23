{{--
    Representación gráfica de un comprobante electrónico (ElectronicDocumentPdf).

    Los mismos datos del comprobante guardado, que son los del XML firmado. Lo
    que vale ante Hacienda es el XML; este PDF es para leerlo.
--}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; font-size: 8.5px; }
        .head { border-bottom: 2px solid #0B1F3A; padding-bottom: 8px; margin-bottom: 10px; }
        .company { font-size: 13px; font-weight: bold; color: #0B1F3A; }
        .muted { color: #555; }
        .doc-type { font-size: 13px; font-weight: bold; text-align: right; color: #0B1F3A; }
        .doc-number { font-size: 10px; text-align: right; }
        .clave { font-family: 'DejaVu Sans Mono', monospace; font-size: 8px; letter-spacing: 0.3px; }
        .test { border: 2px solid #b42318; color: #b42318; font-weight: bold; text-align: center; padding: 4px; margin-bottom: 8px; font-size: 10px; }
        .pending { border: 1px solid #b54708; color: #b54708; text-align: center; padding: 3px; margin-bottom: 8px; }

        table.facts { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.facts td { padding: 1px 4px; vertical-align: top; }
        table.facts td.label { color: #555; width: 18%; }

        .box { border: 1px solid #ccc; padding: 5px 7px; margin-bottom: 8px; }
        .box-title { font-size: 9px; font-weight: bold; color: #0B1F3A; margin-bottom: 3px; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 4px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; font-size: 8px; }
        table.data td.num, table.data th.num { text-align: right; }

        table.totals { width: 48%; margin-left: 52%; border-collapse: collapse; }
        table.totals td { padding: 2px 4px; }
        table.totals td.num { text-align: right; }
        table.totals tr.grand td { border-top: 2px solid #0B1F3A; font-size: 11px; font-weight: bold; padding-top: 4px; }

        .legal { margin-top: 14px; font-size: 7.5px; color: #555; border-top: 1px solid #ccc; padding-top: 5px; }
    </style>
</head>
<body>
    @if ($hacienda['test'])
        <div class="test">COMPROBANTE DE PRUEBAS (SANDBOX DE HACIENDA) — SIN VALOR FISCAL</div>
    @elseif ($hacienda['electronic'] && ! $hacienda['accepted'])
        <div class="pending">Estado en Hacienda: {{ $hacienda['status'] }}. El comprobante vale cuando Hacienda lo acepta.</div>
    @endif

    <div class="head">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                @php
                    $logoSize = \App\Domains\Reporting\Support\ReportLogo::size($company['logo_data_uri']);
                @endphp
                @if ($company['logo_data_uri'])
                    <td style="width: {{ \App\Domains\Reporting\Support\ReportLogo::cellWidth($logoSize) }}px; vertical-align: middle;">
                        <img src="{{ $company['logo_data_uri'] }}" style="{{ \App\Domains\Reporting\Support\ReportLogo::style($logoSize) }}">
                    </td>
                @endif
                <td style="vertical-align: top;">
                    <div class="company">{{ $company['name'] }}</div>
                    @if ($company['trade_name'] && $company['trade_name'] !== $company['name'])
                        <div>{{ $company['trade_name'] }}</div>
                    @endif
                    <div class="muted">{{ $company['identification'] }}: {{ $company['tax_id'] }}</div>
                    @if ($company['location'])
                        <div class="muted">{{ $company['location'] }}</div>
                    @endif
                    <div class="muted">
                        {{ collect([$company['phone'] ? 'Tel. '.$company['phone'] : null, ...$company['emails']])->filter()->implode(' · ') }}
                    </div>
                </td>
                <td style="vertical-align: top; width: 38%;">
                    <div class="doc-type">{{ $document['type'] }}</div>
                    <div class="doc-number">N.º {{ $document['consecutive'] }}</div>
                    <div class="doc-number muted">{{ $document['issued_at'] }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="facts">
        <tr>
            <td class="label">Clave numérica</td>
            <td colspan="3" class="clave">{{ $document['clave'] }}</td>
        </tr>
        <tr>
            <td class="label">Condición de venta</td>
            <td>
                {{ $document['sale_condition'] }}{{ $document['sale_condition_other'] ? ' — '.$document['sale_condition_other'] : '' }}
                @if ($document['credit_term_days'])
                    ({{ $document['credit_term_days'] }} días{{ $document['due_date'] ? ', vence '.$document['due_date'] : '' }})
                @endif
            </td>
            <td class="label">Actividad económica</td>
            <td>{{ $document['activity'] }}</td>
        </tr>
        <tr>
            <td class="label">Moneda</td>
            <td>{{ $currency }}{{ $document['exchange_rate'] ? ' (tipo de cambio '.$document['exchange_rate'].')' : '' }}</td>
            <td class="label">Medio de pago</td>
            <td>{{ collect($payments)->pluck('label')->implode(', ') ?: '—' }}</td>
        </tr>
    </table>

    <div class="box">
        <div class="box-title">Receptor</div>
        @if ($receiver)
            <div><strong>{{ $receiver['name'] }}</strong></div>
            <div class="muted">
                {{ collect([$receiver['identification'], $receiver['email'], $receiver['phone']])->filter()->implode(' · ') }}
            </div>
        @else
            <div>Consumidor final</div>
        @endif
    </div>

    <table class="data">
        <thead>
            <tr>
                <th>#</th>
                <th>Código / CAByS</th>
                <th>Descripción</th>
                <th class="num">Cantidad</th>
                <th class="num">Precio</th>
                <th class="num">Descuento</th>
                <th class="num">IVA</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['number'] }}</td>
                    <td>{{ $line['code'] ? $line['code'].' · ' : '' }}<span class="muted">{{ $line['cabys'] }}</span></td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['quantity'] }} {{ $line['unit'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['discount'] ?? '—' }}</td>
                    <td class="num">{{ $line['tax'] }}</td>
                    <td class="num">{{ $line['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach ($totals as $label => $value)
            <tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>
        @endforeach
        @foreach ($breakdown as $item)
            <tr><td>{{ $item['label'] }}</td><td class="num">{{ $item['amount'] }}</td></tr>
        @endforeach
        <tr><td>Total impuesto</td><td class="num">{{ $total_tax }}</td></tr>
        <tr class="grand"><td>TOTAL {{ $currency }}</td><td class="num">{{ $total }}</td></tr>
    </table>

    @if (count($payments) > 1)
        <div class="box" style="margin-top: 8px;">
            <div class="box-title">Medios de pago</div>
            @foreach ($payments as $payment)
                <div>{{ $payment['label'] }}: {{ $payment['amount'] }}</div>
            @endforeach
        </div>
    @endif

    @if ($references)
        <div class="box" style="margin-top: 8px;">
            <div class="box-title">Documentos de referencia</div>
            @foreach ($references as $reference)
                <div>{{ $reference['type'] }} {{ $reference['number'] }}{{ $reference['date'] ? ' ('.$reference['date'].')' : '' }} — {{ $reference['reason'] }}</div>
            @endforeach
        </div>
    @endif

    @if ($document['notes'])
        <div class="box" style="margin-top: 8px;">
            <div class="box-title">Observaciones</div>
            <div>{{ $document['notes'] }}</div>
        </div>
    @endif

    <div class="legal">
        Comprobante electrónico versión 4.4, emitido conforme a la Resolución MH-DGT-RES-0027-2024 de la Dirección General
        de Tributación. El documento válido es el XML firmado, que se entrega junto con este PDF y con la respuesta de
        Hacienda. Generado por Pivox el {{ $generated_at }}.
    </div>
</body>
</html>
