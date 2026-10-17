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
        .currency-label { font-weight: bold; font-size: 11px; margin-top: 12px; }
        table.documents { width: 100%; border-collapse: collapse; font-size: 7px; margin: 2px 0 4px; }
        table.documents th, table.documents td { border: 1px solid #ddd; padding: 2px 4px; }
        table.documents th { background: #E8EDF5; text-align: left; }
        table.documents td.num { text-align: right; }
        .documents-cell { padding: 0 !important; border: none !important; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @foreach ($result->groups as $group)
        <div class="currency-label">Moneda: {{ $group->currencyCode }}</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Socio</th>
                    <th>Nombre</th>
                    @foreach ($result->bucketLabels as $label)
                        <th class="num">{{ $label }}</th>
                    @endforeach
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($group->rows as $row)
                    <tr>
                        <td>{{ $row->partnerCode }}</td>
                        <td>{{ $row->partnerName }}</td>
                        @foreach ($result->bucketLabels as $key => $label)
                            <td class="num">{{ $row->buckets[$key] }}</td>
                        @endforeach
                        <td class="num">{{ $row->total }}</td>
                    </tr>
                    @if ($includeDocuments && count($row->documents))
                        <tr>
                            <td class="documents-cell" colspan="{{ count($result->bucketLabels) + 3 }}">
                                <table class="documents">
                                    <thead>
                                        <tr>
                                            <th>Documento</th>
                                            <th>Vencimiento</th>
                                            <th class="num">Monto original</th>
                                            <th class="num">Saldo pendiente</th>
                                            <th>Tramo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($row->documents as $doc)
                                            <tr>
                                                <td>{{ $doc->documentLabel }}</td>
                                                <td>{{ $doc->dueDate ?? '—' }}</td>
                                                <td class="num">{{ $doc->originalAmount }}</td>
                                                <td class="num">{{ $doc->balance }}</td>
                                                <td>{{ $result->bucketLabels[$doc->bucket] ?? $doc->bucket }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Totales</td>
                    @foreach ($result->bucketLabels as $key => $label)
                        <td class="num">{{ $group->bucketTotals[$key] }}</td>
                    @endforeach
                    <td class="num">{{ $group->total }}</td>
                </tr>
            </tfoot>
        </table>
    @endforeach
</body>
</html>
