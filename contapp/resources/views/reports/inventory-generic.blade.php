<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; color: #1a1a1a; }
        table.data { width: 100%; border-collapse: collapse; font-size: 7.5px; margin-top: 8px; }
        table.data th, table.data td { border: 1px solid #ccc; padding: 3px 4px; }
        table.data th { background: #0B1F3A; color: #fff; text-align: left; }
        table.data td.num { text-align: right; }
        table.data tfoot td { font-weight: bold; background: #f2f2f2; }
        table.data tbody tr:nth-child(even) td { background: #fafafa; }
        .notes { font-size: 8px; color: #444; margin-top: 8px; }
        .notes li { margin-bottom: 2px; }
        .empty { font-style: italic; color: #666; }
        .signal { font-weight: bold; color: #a04000; }
    </style>
</head>
<body>
    @include('reports.partials.header', ['header' => $header])
    @include('reports.partials.footer', ['header' => $header])

    @if ($result->notes)
        {{-- Las notas van antes de la tabla: explican cómo leer los números,
             y al pie de varias páginas no las lee nadie. --}}
        <ul class="notes">
            @foreach ($result->notes as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($result->columns as $column)
                    <th @class(['num' => $column->isNumeric()])>{{ $column->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($result->rows as $row)
                <tr>
                    @foreach ($result->columns as $column)
                        <td @class([
                            'num' => $column->isNumeric(),
                            'signal' => $column->key === 'signal' || $column->key === 'flag',
                        ])>{{ $column->display($row[$column->key] ?? null) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($result->columns) }}" class="empty">
                        No hay datos para los filtros seleccionados.
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($totals && $result->rows)
            <tfoot>
                <tr>
                    @foreach ($result->columns as $index => $column)
                        <td @class(['num' => $column->isNumeric()])>
                            @if (array_key_exists($column->key, $totals))
                                {{ $column->display($totals[$column->key]) }}
                            @elseif ($index === 0)
                                Total
                            @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
