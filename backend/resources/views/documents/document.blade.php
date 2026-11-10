<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $docType }} {{ $docNumber }}</title>
    <style>
        @page { margin: 26mm 16mm 22mm 16mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #2a2c28; font-family: 'DejaVu Sans', sans-serif; font-size: 10.5pt; line-height: 1.45; }
        .brandbar { background: #39452f; color: #ffffff; padding: 18px 20px; border-radius: 10px; }
        .brandbar table { width: 100%; border-collapse: collapse; }
        .brandbar td { vertical-align: top; }
        .wordmark { font-size: 26pt; font-weight: bold; letter-spacing: -0.5px; color: #ffffff; }
        .wordmark span { color: #c3d3a3; }
        .tagline { font-size: 7.5pt; letter-spacing: 2px; text-transform: uppercase; color: #c3d3a3; margin-top: 3px; }
        .org { text-align: right; font-size: 8.5pt; color: #e7ecdc; line-height: 1.5; }
        .org strong { color: #ffffff; font-size: 10pt; }
        .doc-title { margin-top: 22px; }
        .doc-title table { width: 100%; border-collapse: collapse; }
        .doc-title h1 { margin: 0; font-size: 20pt; color: #2b1e17; letter-spacing: -0.3px; }
        .doc-title .number { text-align: right; font-size: 9pt; color: #6f7268; }
        .doc-title .number strong { display: block; font-size: 12pt; color: #2b1e17; }
        .muted { color: #74776d; }
        .parties { margin-top: 20px; width: 100%; border-collapse: collapse; }
        .parties td { width: 50%; vertical-align: top; padding: 0; }
        .label { font-size: 7.5pt; letter-spacing: 1.4px; text-transform: uppercase; color: #8b8e83; font-weight: bold; }
        .party { padding: 10px 12px; background: #f6f3ea; border-radius: 8px; margin-top: 5px; font-size: 9.5pt; }
        .party strong { display: block; font-size: 11pt; color: #2b1e17; margin-bottom: 2px; }
        .meta { margin-top: 16px; width: 100%; border-collapse: collapse; border: 1px solid #e4e0d4; border-radius: 8px; }
        .meta td { padding: 7px 10px; border-bottom: 1px solid #ece8dd; font-size: 9pt; }
        .meta tr:last-child td { border-bottom: none; }
        .meta td.k { color: #74776d; width: 26%; background: #faf8f2; }
        .items { margin-top: 18px; width: 100%; border-collapse: collapse; }
        .items th { text-align: left; font-size: 7.5pt; letter-spacing: 1.1px; text-transform: uppercase; color: #7d8076; border-bottom: 1.5px solid #39452f; padding: 8px 6px; }
        .items td { padding: 9px 6px; border-bottom: 1px solid #ece8dd; vertical-align: top; }
        .items .r { text-align: right; }
        .items .c { text-align: center; }
        .items .name { font-weight: bold; color: #2b1e17; }
        .items .sku { font-size: 8pt; color: #85887d; }
        .totals { margin-top: 14px; width: 46%; margin-left: 54%; border-collapse: collapse; }
        .totals td { padding: 6px 8px; font-size: 9.5pt; }
        .totals td.k { color: #666a60; }
        .totals td.v { text-align: right; }
        .totals tr.grand td { border-top: 1.5px solid #39452f; border-bottom: 1.5px solid #39452f; font-weight: bold; font-size: 11.5pt; color: #2b1e17; background: #f6f3ea; }
        .payments { margin-top: 18px; width: 100%; border-collapse: collapse; }
        .payments th { text-align: left; font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; color: #7d8076; border-bottom: 1px solid #e4e0d4; padding: 6px; }
        .payments td { padding: 6px; border-bottom: 1px solid #f0ece2; font-size: 9pt; }
        .payments .r { text-align: right; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e7ecd8; color: #3c4a2c; font-size: 8pt; font-weight: bold; }
        .rule { margin-top: 22px; border-top: 2px solid #c3d3a3; padding-top: 10px; font-size: 8pt; color: #7a7d72; }
        .rule table { width: 100%; border-collapse: collapse; }
        .rule td { vertical-align: top; padding: 0; }
        .rule .r { text-align: right; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 60pt; color: #f2efe6; font-weight: bold; z-index: -1; letter-spacing: 8px; }
    </style>
</head>
<body>
    <div class="watermark">OSHEL</div>

    <div class="brandbar">
        <table>
            <tr>
                <td>
                    <div class="wordmark">oshel<span>.</span></div>
                    <div class="tagline">Skin care &amp; beauty clinic</div>
                </td>
                <td class="org">
                    <strong>{{ $organization?->name ?? 'Oshel Skin Care & Beauty Clinic' }}</strong><br>
                    @if ($organization?->address){{ $organization->address }}<br>@endif
                    @if ($organization?->city){{ $organization->city }}<br>@endif
                    @if ($organization?->phone){{ $organization->phone }}<br>@endif
                    @if ($organization?->email){{ $organization->email }}<br>@endif
                    @if ($organization?->registration_number)Reg. {{ $organization->registration_number }}@endif
                </td>
            </tr>
        </table>
    </div>

    <div class="doc-title">
        <table>
            <tr>
                <td>
                    <h1>{{ $docType }}</h1>
                    <div class="muted">Issued {{ \Carbon\CarbonImmutable::parse($issuedAt, 'UTC')->setTimezone('Africa/Nairobi')->format('D, j M Y H:i') }} EAT</div>
                </td>
                <td class="number">
                    <span class="label">Document number</span>
                    <strong>{{ $docNumber }}</strong>
                    <div class="status">{{ $status }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="parties">
        <tr>
            <td style="padding-right:10px;">
                <div class="label">Billed to</div>
                <div class="party">
                    <strong>{{ $customer?->name ?? 'Walk-in customer' }}</strong>
                    @if ($customer?->email){{ $customer->email }}<br>@endif
                    @if ($customer?->phone){{ $customer->phone }}<br>@endif
                    @if ($branch){{ $branch->name }}@if ($branch->city), {{ $branch->city }}@endif<br>@endif
                </div>
            </td>
            <td style="padding-left:10px;">
                <div class="label">Document details</div>
                <table class="meta">
                    @foreach ($meta as $label => $value)
                        @if ($value !== null && $value !== '')
                            <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Item</th>
                <th class="c" style="width:12%;">Qty</th>
                <th class="r" style="width:20%;">Unit price</th>
                <th class="r" style="width:20%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>
                        <span class="name">{{ $line['name'] }}</span>
                        @if (! empty($line['sku']))
                            <div class="sku">{{ $line['sku'] }}</div>
                        @endif
                    </td>
                    <td class="c">{{ rtrim(rtrim(number_format((float) $line['quantity'], 3, '.', ''), '0'), '.') }}</td>
                    <td class="r">{{ strtoupper($currency) }} {{ number_format((float) $line['unit_price'], 2) }}</td>
                    <td class="r">{{ strtoupper($currency) }} {{ number_format((float) $line['line_total'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach ($totals as $label => $value)
            <tr class="{{ $label === 'Total' ? 'grand' : '' }}">
                <td class="k">{{ $label }}</td>
                <td class="v">{{ strtoupper($currency) }} {{ number_format((float) $value, 2) }}</td>
            </tr>
        @endforeach
    </table>

    @if (! empty($payments))
        <table class="payments">
            <thead>
                <tr><th>Payment method</th><th>Reference</th><th>Status</th><th class="r">Amount</th></tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td>{{ $payment['method'] }}</td>
                        <td>{{ $payment['reference'] ?? '—' }}</td>
                        <td>{{ ucfirst($payment['status'] ?? 'recorded') }}</td>
                        <td class="r">{{ strtoupper($currency) }} {{ number_format((float) $payment['amount'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="rule">
        <table>
            <tr>
                <td>
                    {{ $footnote }}<br>
                    This document was generated electronically by Oshel and is valid without a signature.
                </td>
                <td class="r">
                    {{ $organization?->name ?? 'Oshel Skin Care & Beauty Clinic' }}<br>
                    @if ($branch){{ $branch->name }}@endif
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
