<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 8px; margin-top: 6px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 5px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        .badge-draft { color: #92600a; }
        .badge-posted { color: #1f7a4d; }
        .badge-voided { color: #666; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @php($statusLabels = ['draft' => 'Preliminar', 'posted' => 'Contabilizado', 'voided' => 'Anulado'])
    @php($totalDebit = 0)
    @php($totalCredit = 0)

    <table class="data">
        <thead>
            <tr>
                <th>Documento</th>
                <th>Serie</th>
                <th>Fecha</th>
                <th>Descripción</th>
                <th>Estado</th>
                <th class="num">Débito</th>
                <th class="num">Crédito</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td>{{ $entry->document_number ? "{$entry->documentType->code}-{$entry->document_number}" : "{$entry->documentType->code} — preliminar" }}</td>
                    <td>{{ $entry->numberSeries ? "{$entry->numberSeries->name} #{$entry->series_number}" : '' }}</td>
                    <td>{{ $entry->posting_date->format('Y-m-d') }}</td>
                    <td>{{ $entry->description }}</td>
                    <td class="badge-{{ $entry->status }}">{{ $statusLabels[$entry->status] ?? $entry->status }}</td>
                    <td class="num">{{ number_format((float) $entry->total_debit, 2) }}</td>
                    <td class="num">{{ number_format((float) $entry->total_credit, 2) }}</td>
                </tr>
                @php($totalDebit += (float) $entry->total_debit)
                @php($totalCredit += (float) $entry->total_credit)
            @endforeach
            @if ($entries->isEmpty())
                <tr><td colspan="7">Sin asientos para los filtros seleccionados.</td></tr>
            @endif
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5">Total</td>
                <td class="num">{{ number_format($totalDebit, 2) }}</td>
                <td class="num">{{ number_format($totalCredit, 2) }}</td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
