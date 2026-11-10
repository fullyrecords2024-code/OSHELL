<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1e9;color:#292b28;font-family:'Segoe UI',Helvetica,Arial,sans-serif;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader ?: $heading }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1e9;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border:1px solid #e4e0d8;border-radius:14px;overflow:hidden;">
                <tr>
                    <td style="background:#39452f;padding:24px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="color:#ffffff;font-size:21px;font-weight:700;letter-spacing:-0.4px;">{{ $clinicName }}</td>
                                <td align="right" style="color:#c3d3a3;font-size:10px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;">Oshel</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:34px 32px 10px;">
                        <h1 style="margin:0 0 14px;font-size:24px;line-height:1.3;color:#23271f;font-weight:700;">{{ $heading }}</h1>
                        @if ($recipientName)
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#3d4038;">Dear {{ $recipientName }},</p>
                        @endif
                        @foreach ($intro as $paragraph)
                            <p style="margin:0 0 14px;font-size:15px;line-height:1.65;color:#3d4038;">{!! nl2br(e($paragraph)) !!}</p>
                        @endforeach
                    </td>
                </tr>
                @if (! empty($details))
                    <tr>
                        <td style="padding:6px 32px 4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e7e3da;border-radius:10px;border-collapse:separate;overflow:hidden;">
                                @foreach ($details as $label => $value)
                                    @if ($value !== null && $value !== '')
                                        <tr>
                                            <td style="padding:10px 16px;background:#faf8f3;border-bottom:1px solid #efebe3;font-size:12px;color:#72756c;width:42%;">{{ $label }}</td>
                                            <td style="padding:10px 16px;border-bottom:1px solid #efebe3;font-size:13px;color:#262a22;font-weight:600;">{{ $value }}</td>
                                        </tr>
                                    @endif
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif
                @if (! empty($items))
                    <tr>
                        <td style="padding:18px 32px 2px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <th align="left" style="padding:8px 0;border-bottom:1px solid #e7e3da;font-size:11px;letter-spacing:0.8px;text-transform:uppercase;color:#8b8d84;">Item</th>
                                    <th align="center" style="padding:8px 0;border-bottom:1px solid #e7e3da;font-size:11px;letter-spacing:0.8px;text-transform:uppercase;color:#8b8d84;width:70px;">Qty</th>
                                    <th align="right" style="padding:8px 0;border-bottom:1px solid #e7e3da;font-size:11px;letter-spacing:0.8px;text-transform:uppercase;color:#8b8d84;width:100px;">Amount</th>
                                </tr>
                                @foreach ($items as $item)
                                    <tr>
                                        <td style="padding:10px 0;border-bottom:1px solid #f0ece5;font-size:13px;color:#2c3028;">{{ $item['name'] }}</td>
                                        <td align="center" style="padding:10px 0;border-bottom:1px solid #f0ece5;font-size:13px;color:#5c6056;">{{ $item['quantity'] }}</td>
                                        <td align="right" style="padding:10px 0;border-bottom:1px solid #f0ece5;font-size:13px;color:#2c3028;font-weight:600;">{{ $item['amount'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif
                @if (! empty($totals))
                    <tr>
                        <td style="padding:12px 32px 4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                @foreach ($totals as $total)
                                    <tr>
                                        <td align="right" style="padding:4px 0;font-size:{{ ! empty($total['emphasis']) ? '15' : '13' }}px;color:{{ ! empty($total['emphasis']) ? '#23271f' : '#5c6056' }};font-weight:{{ ! empty($total['emphasis']) ? '700' : '400' }};">{{ $total['label'] }}</td>
                                        <td align="right" style="padding:4px 0 4px 18px;font-size:{{ ! empty($total['emphasis']) ? '15' : '13' }}px;color:#23271f;font-weight:{{ ! empty($total['emphasis']) ? '700' : '600' }};width:120px;">{{ $total['value'] }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif
                @if ($action)
                    <tr>
                        <td style="padding:22px 32px 6px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:#39452f;border-radius:8px;">
                                        <a href="{{ $action['url'] }}" style="display:inline-block;padding:12px 22px;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;">{{ $action['label'] }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:22px 32px 32px;">
                        @if ($footnote)
                            <p style="margin:0 0 12px;font-size:12px;line-height:1.6;color:#7d8077;">{{ $footnote }}</p>
                        @endif
                        <p style="margin:0;font-size:12px;line-height:1.6;color:#7d8077;">
                            If you need help, reply to this email{{ $clinicEmail ? ' or contact '.$clinicEmail : '' }}{{ $clinicPhone ? ' / '.$clinicPhone : '' }}.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#faf8f3;border-top:1px solid #e7e3da;padding:18px 32px;font-size:11px;line-height:1.6;color:#8b8d84;">
                        <strong style="color:#5c6056;">{{ $clinicName }}</strong><br>
                        This is an automated message about your activity with us. Please do not share payment details by email.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
