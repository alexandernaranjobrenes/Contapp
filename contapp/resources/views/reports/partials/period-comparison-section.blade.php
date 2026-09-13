@php $label = $label ?? ''; @endphp
<h3 class="section-title">{{ $label }}</h3>
<table class="data">
    <thead>
        <tr>
            <th>Cuenta</th>
            <th class="num">Periodo 1</th>
            <th class="num">Periodo 2</th>
            <th class="num">Variación</th>
            <th class="num">Variación %</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr class="{{ $line->isHeader ? 'is-header' : '' }}">
                <td style="padding-left: {{ 6 + $line->depth * 10 }}px">{{ $line->description }}</td>
                <td class="num">{{ $line->amounts->period1 }}</td>
                <td class="num">{{ $line->amounts->period2 }}</td>
                <td class="num">{{ $line->amounts->variance }}</td>
                <td class="num">{{ $line->amounts->variancePercent ?? '—' }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>Total {{ strtolower($label) }}</td>
            <td class="num">{{ $total->period1 }}</td>
            <td class="num">{{ $total->period2 }}</td>
            <td class="num">{{ $total->variance }}</td>
            <td class="num">{{ $total->variancePercent ?? '—' }}</td>
        </tr>
    </tfoot>
</table>
