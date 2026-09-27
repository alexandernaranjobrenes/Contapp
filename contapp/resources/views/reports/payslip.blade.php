{{--
    El comprobante de pago en PDF.

    Los mismos datos que la pantalla, porque vienen del mismo
    PayslipDocument::payload(). Si esta maqueta se armara con datos propios, el
    comprobante que el trabajador recibe por correo podría no coincidir con el
    que firma en papel.
--}}
@php
    $money = fn ($value) => '₡'.number_format((float) $value, 2, ',', '.');
@endphp

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; font-size: 9px; }
        .head { border-bottom: 2px solid #0B1F3A; padding-bottom: 8px; margin-bottom: 10px; }
        .company { font-size: 13px; font-weight: bold; color: #0B1F3A; }
        .muted { color: #555; }
        .title { font-size: 12px; font-weight: bold; margin-top: 8px; }

        table.facts { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.facts td { padding: 1px 4px; vertical-align: top; }
        table.facts td.label { color: #555; width: 22%; }

        table.data { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 5px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; font-size: 8.5px; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }

        .block-title { font-size: 10px; font-weight: bold; background: #E9EDF5; padding: 3px 5px; margin: 10px 0 4px; }

        .net { border: 2px solid #0B1F3A; padding: 6px 8px; margin: 10px 0; }
        .net .label { font-size: 10px; }
        .net .value { font-size: 16px; font-weight: bold; }

        .note { font-size: 7.5px; color: #555; margin-top: 4px; }
        .sign { margin-top: 28px; font-size: 8px; }
        .sign td { padding-top: 18px; border-top: 1px solid #888; width: 45%; }
    </style>
</head>
<body>
    <div class="head">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="width: 70px; vertical-align: top;">
                    @if ($company['logo_path'])
                        <img src="{{ 'data:image/png;base64,'.base64_encode(file_get_contents($company['logo_path'])) }}"
                            style="max-width: 60px; max-height: 60px;">
                    @endif
                </td>
                <td style="vertical-align: top;">
                    <div class="company">{{ $company['name'] }}</div>
                    @if ($company['tax_id'])
                        <div class="muted">Cédula jurídica: {{ $company['tax_id'] }}</div>
                    @endif
                </td>
            </tr>
        </table>

        <div class="title">Comprobante de pago de salario — {{ $period['name'] }}</div>
        <div class="muted">
            Del {{ $period['start_date'] }} al {{ $period['end_date'] }} · Pago: {{ $period['payment_date'] }}
        </div>
    </div>

    <table class="facts">
        <tr>
            <td class="label">Trabajador</td>
            <td><strong>{{ $employee['name'] }}</strong> ({{ $employee['code'] }})</td>
            <td class="label">Identificación</td>
            <td>{{ $employee['identification'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Puesto</td>
            <td>{{ $employee['position'] ?? '—' }}</td>
            <td class="label">N.º asegurado</td>
            <td>{{ $employee['ccss_number'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Departamento</td>
            <td>{{ $employee['department'] ?? '—' }}</td>
            <td class="label">Centro de costo</td>
            <td>{{ $employee['cost_center'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Ingreso</td>
            <td>{{ $employee['hire_date'] ?? '—' }}</td>
            <td class="label">Días del período</td>
            <td>{{ number_format($entry['days_worked'], 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Forma de pago</td>
            <td>{{ $employee['payment_method'] ?? '—' }}</td>
            <td class="label">Cuenta</td>
            <td>{{ $employee['bank_account'] ?? '—' }}</td>
        </tr>
    </table>

    <div class="block-title">Ingresos</div>
    <table class="data">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>Descripción</th>
                <th class="num">Cantidad</th>
                <th class="num">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($earnings as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="num">{{ $line['quantity'] !== null ? number_format($line['quantity'], 2, ',', '.') : '—' }}</td>
                    <td class="num">{{ $money($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">Sin ingresos registrados.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total devengado</td>
                <td class="num">{{ $money($entry['total_earnings']) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="block-title">Deducciones</div>
    <table class="data">
        <thead>
            <tr>
                <th>Concepto</th>
                <th>Descripción</th>
                {{-- La base y la tasa son la razón de ser del comprobante: sin
                     ellas el trabajador no puede comprobar su propio rebajo. --}}
                <th class="num">Base</th>
                <th class="num">Tasa</th>
                <th class="num">Monto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($deductions as $line)
                <tr>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['name'] }}</td>
                    <td class="num">{{ $line['base_amount'] !== null ? $money($line['base_amount']) : '—' }}</td>
                    <td class="num">{{ $line['rate'] !== null ? number_format($line['rate'], 2, ',', '.').' %' : '—' }}</td>
                    <td class="num">{{ $money($line['amount']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Sin deducciones.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">Total deducciones</td>
                <td class="num">{{ $money($entry['total_deductions']) }}</td>
            </tr>
        </tfoot>
    </table>

    <div class="net">
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td class="label">Neto a pagar</td>
                <td class="value" style="text-align: right;">{{ $money($entry['net_pay']) }}</td>
            </tr>
        </table>
    </div>

    @if (count($employerLines))
        <div class="block-title">Aportes del patrono — NO se rebajan de su salario</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th>Descripción</th>
                    <th class="num">Base</th>
                    <th class="num">Tasa</th>
                    <th class="num">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($employerLines as $line)
                    <tr>
                        <td>{{ $line['code'] }}</td>
                        <td>{{ $line['name'] }}</td>
                        <td class="num">{{ $line['base_amount'] !== null ? $money($line['base_amount']) : '—' }}</td>
                        <td class="num">{{ $line['rate'] !== null ? number_format($line['rate'], 2, ',', '.').' %' : '—' }}</td>
                        <td class="num">{{ $money($line['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4">Costo total del puesto para la empresa</td>
                    <td class="num">{{ $money($entry['employer_cost']) }}</td>
                </tr>
            </tfoot>
        </table>

        <p class="note">
            Este bloque es informativo: son las cargas y provisiones que paga la empresa por encima de su
            salario. No afectan el neto.
        </p>
    @endif

    <table class="sign" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="text-align: center;">Recibí conforme — {{ $employee['name'] }}</td>
            <td style="width: 10%;"></td>
            <td style="text-align: center;">Por la empresa</td>
        </tr>
    </table>

    <p class="note">
        Generado el {{ now()->format('d/m/Y H:i') }}. Cualquier diferencia debe reclamarse ante el departamento
        de recursos humanos.
    </p>
</body>
</html>
