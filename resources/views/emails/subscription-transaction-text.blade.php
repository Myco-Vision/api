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
@if (!empty($tx['criteria_summary']) || !empty($tx['criteria_items']))
{{ $tx['criteria_title'] ?? 'Eligibility & Decision Criteria' }}:
@if (!empty($tx['criteria_summary']))
{{ $tx['criteria_summary'] }}
@endif
@if (!empty($tx['criteria_items']))
@foreach ($tx['criteria_items'] as $item)
- [{{ match ($item['status'] ?? 'info') { 'pass' => 'PASS', 'fail' => 'UNMET', default => 'INFO' } }}] {{ $item['label'] }}: {{ $item['detail'] }}
@endforeach
@endif

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

