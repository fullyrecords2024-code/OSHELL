{{ $heading }}

@if ($recipientName)Dear {{ $recipientName }},

@endif
@foreach ($intro as $paragraph){{ $paragraph }}

@endforeach
@if (! empty($details))
@foreach ($details as $label => $value)
@if ($value !== null && $value !== ''){{ $label }}: {{ $value }}
@endif
@endforeach

@endif
@if (! empty($items))
Items:
@foreach ($items as $item)
- {{ $item['name'] }} x {{ $item['quantity'] }}: {{ $item['amount'] }}
@endforeach

@endif
@if (! empty($totals))
@foreach ($totals as $total)
{{ $total['label'] }}: {{ $total['value'] }}
@endforeach

@endif
@if ($action)
{{ $action['label'] }}: {{ $action['url'] }}

@endif
@if ($footnote){{ $footnote }}

@endif
If you need help, reply to this email{{ $clinicEmail ? ' or contact '.$clinicEmail : '' }}{{ $clinicPhone ? ' / '.$clinicPhone : '' }}.

{{ $clinicName }}
This is an automated message about your activity with us. Please do not share payment details by email.
