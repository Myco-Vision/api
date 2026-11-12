MycoVision — Subscription Billing
==================================
[{{ $tx['badge'] ?? 'TRANSACTION' }}]

{{ $tx['heading'] ?? 'Subscription Transaction Notice' }}

Hi {{ $tx['recipient_name'] ?? 'Customer' }},

{{ $tx['intro'] ?? '' }}

@if (!empty($tx['amount_display']))
{{ $tx['amount_label'] ?? 'Transaction Amount' }}: {{ $tx['amount_display'] }}

@endif
@if (!empty($tx['details']))
Transaction Details:
@foreach ($tx['details'] as $row)
- {{ $row['label'] }}: {{ $row['value'] }}
@endforeach

@endif
@if (!empty($tx['note']))
Note: {{ $tx['note'] }}

@endif
@if (!empty($tx['cta_url']) && !empty($tx['cta_label']))
{{ $tx['cta_label'] }}: {{ $tx['cta_url'] }}

@endif
----------------------------------
This automated receipt was sent for your MycoVision subscription records.
Questions? Contact {{ $tx['support_email'] ?? 'support@mycovision.app' }}.

