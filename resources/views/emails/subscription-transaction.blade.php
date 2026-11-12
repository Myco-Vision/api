@php
    $tone = $tx['badge_tone'] ?? 'info';
    $badgeColors = match ($tone) {
        'success' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#bbf7d0'],
        'warning' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fde68a'],
        'danger'  => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fecaca'],
        default   => ['bg' => '#e0f2fe', 'text' => '#075985', 'border' => '#bae6fd'],
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $tx['subject'] ?? 'MycoVision Transaction' }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f4f6f5; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color:#1c1917;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f5; padding:28px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px; background-color:#ffffff; border-radius:14px; border:1px solid #e7e5e4; overflow:hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#064e3b; padding:22px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="font-size:20px; font-weight:700; color:#ffffff; letter-spacing:-0.2px;">
                                        MycoVision
                                    </td>
                                    <td align="right" style="font-size:12px; color:#a7f3d0;">
                                        Subscription Billing
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:28px;">
                            <div style="margin-bottom:14px;">
                                <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:0.5px; background-color:{{ $badgeColors['bg'] }}; color:{{ $badgeColors['text'] }}; border:1px solid {{ $badgeColors['border'] }};">
                                    {{ $tx['badge'] ?? 'TRANSACTION' }}
                                </span>
                            </div>

                            <h1 style="margin:0 0 10px 0; font-size:21px; line-height:1.3; font-weight:700; color:#1c1917;">
                                {{ $tx['heading'] ?? 'Subscription Transaction Notice' }}
                            </h1>

                            <p style="margin:0 0 16px 0; font-size:14px; line-height:1.6; color:#44403c;">
                                Hi {{ $tx['recipient_name'] ?? 'Customer' }},
                            </p>

                            <p style="margin:0 0 20px 0; font-size:14px; line-height:1.6; color:#44403c;">
                                {{ $tx['intro'] ?? '' }}
                            </p>

                            @if (!empty($tx['amount_display']))
                                <div style="margin:0 0 22px 0; padding:16px 18px; background-color:#f8faf9; border:1px solid #d1fae5; border-radius:10px;">
                                    <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.6px; color:#047857; margin-bottom:4px;">
                                        {{ $tx['amount_label'] ?? 'Transaction Amount' }}
                                    </div>
                                    <div style="font-size:26px; font-weight:700; color:#064e3b; line-height:1.2;">
                                        {{ $tx['amount_display'] }}
                                    </div>
                                </div>
                            @endif

                            @if (!empty($tx['details']))
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse; border:1px solid #e7e5e4; border-radius:10px; overflow:hidden; margin-bottom:20px;">
                                    @foreach ($tx['details'] as $row)
                                        <tr>
                                            <td style="padding:10px 14px; font-size:13px; color:#57534e; background-color:#fafaf9; border-bottom:1px solid #e7e5e4; width:42%; vertical-align:top;">
                                                {{ $row['label'] }}
                                            </td>
                                            <td style="padding:10px 14px; font-size:13px; font-weight:600; color:#1c1917; border-bottom:1px solid #e7e5e4; word-break:break-word; vertical-align:top;">
                                                {{ $row['value'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </table>
                            @endif

                            @if (!empty($tx['note']))
                                <div style="margin:0 0 22px 0; padding:12px 14px; background-color:#fafaf9; border-left:3px solid #10b981; border-radius:4px; font-size:13px; line-height:1.55; color:#44403c;">
                                    {{ $tx['note'] }}
                                </div>
                            @endif

                            @if (!empty($tx['cta_url']) && !empty($tx['cta_label']))
                                <table role="presentation" cellspacing="0" cellpadding="0" style="margin:6px 0 8px 0;">
                                    <tr>
                                        <td style="border-radius:8px; background-color:#059669;">
                                            <a href="{{ $tx['cta_url'] }}" target="_blank" rel="noopener" style="display:inline-block; padding:11px 20px; font-size:13px; font-weight:600; color:#ffffff; text-decoration:none;">
                                                {{ $tx['cta_label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#fafaf9; border-top:1px solid #e7e5e4; padding:16px 28px; font-size:12px; line-height:1.5; color:#78716c;">
                            This automated receipt was sent for your MycoVision subscription records.
                            Questions? Contact us at
                            <a href="mailto:{{ $tx['support_email'] ?? 'support@mycovision.app' }}" style="color:#047857; text-decoration:none;">{{ $tx['support_email'] ?? 'support@mycovision.app' }}</a>.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

