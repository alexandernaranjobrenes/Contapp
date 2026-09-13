<div style="border-bottom: 2px solid #0B1F3A; padding-bottom: 8px; margin-bottom: 12px;">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="width: 70px; vertical-align: top;">
                @if ($header->logoPath)
                    <img src="{{ 'data:image/png;base64,'.base64_encode(file_get_contents($header->logoPath)) }}" style="max-width: 60px; max-height: 60px;">
                @endif
            </td>
            <td style="vertical-align: top;">
                <div style="font-size: 14px; font-weight: bold; color: #0B1F3A;">{{ $header->companyName }}</div>
                @if ($header->taxId)
                    <div style="font-size: 9px; color: #555;">Cédula jurídica: {{ $header->taxId }}</div>
                @endif
                @if ($header->address)
                    <div style="font-size: 9px; color: #555;">{{ $header->address }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div style="margin-top: 10px; font-size: 13px; font-weight: bold;">{{ $header->title }}</div>
    <div style="font-size: 9px; color: #555;">{{ $header->paramsSummary }}</div>
</div>
