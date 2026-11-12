<?php

namespace App\Services;

use App\Mail\SubscriptionTransactionMail;
use App\Models\RefundRequest;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Builds and dispatches transaction receipt/status emails for subscription payments and refunds.
 * Failures are logged and swallowed so gateway callbacks and DB commits never roll back on SMTP issues.
 */
class TransactionEmailService
{
    private const DISPLAY_TIMEZONE = 'Asia/Manila';

    /**
     * Send an itemized receipt when a paid subscription activates.
     */
    public function sendPaymentReceipt(Subscription $subscription): bool
    {
        $subscription->loadMissing('user');
        [$email, $name] = $this->resolveRecipient($subscription);

        $planName = $this->formatPlanName($subscription->plan);
        $currency = strtoupper((string) ($subscription->currency ?: 'PHP'));
        $amountText = $this->formatCurrency((float) $subscription->price, $currency);
        $paymentRef = $subscription->paymongo_payment_id
            ?: ($subscription->paymongo_session_id ?: 'SUB-' . $subscription->id);

        $period = 'Active upon confirmation';
        if ($subscription->starts_at && $subscription->expires_at) {
            $period = $this->formatDate($subscription->starts_at)
                . ' – '
                . $this->formatDate($subscription->expires_at);
        }

        $payload = [
            'subject'        => "MycoVision Payment Receipt — {$planName} ({$amountText})",
            'badge'          => 'PAYMENT CONFIRMED',
            'badge_tone'     => 'success',
            'heading'        => 'Payment Receipt',
            'recipient_name' => $name,
            'intro'          => "Thank you for subscribing to MycoVision {$planName}. Your payment has been confirmed and your Premium access is now active.",
            'amount_label'   => 'Amount Paid',
            'amount_display' => $amountText,
            'details'        => [
                ['label' => 'Transaction Type', 'value' => 'Subscription Payment'],
                ['label' => 'Subscription ID',  'value' => '#' . $subscription->id],
                ['label' => 'Payment Reference', 'value' => $paymentRef],
                ['label' => 'Plan',             'value' => $planName],
                ['label' => 'Payment Method',   'value' => $this->formatPaymentMethod($subscription->payment_method)],
                ['label' => 'Billing Period',   'value' => $period],
                ['label' => 'Transaction Date', 'value' => $this->formatTimestamp($subscription->starts_at ?? $subscription->updated_at)],
                ['label' => 'Status',           'value' => 'Paid & Active'],
            ],
            'note'          => 'You can review your subscription history, manage renewal, or view our refund policy anytime from your account.',
            'cta_label'     => 'View Subscription Details',
            'cta_url'       => $this->frontendUrl('/users/subscription'),
            'support_email' => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Subscription payment receipt email failed', [
            'subscription_id' => $subscription->id,
        ]);
    }

    /**
     * Confirm that a refund request has been filed (by the user or by support/admin).
     */
    public function sendRefundRequested(RefundRequest $refund): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $requested = (float) ($refund->requested_amount ?? 0);
        $currency = $this->currencyFor($refund);
        $amountText = $requested > 0
            ? $this->formatCurrency($requested, $currency)
            : 'To be determined upon review';

        $details = $this->baseRefundDetails($refund, 'Pending Review');
        $details[] = ['label' => 'Estimated Refund', 'value' => $amountText];
        $details[] = ['label' => 'Submitted At',     'value' => $this->formatTimestamp($refund->created_at)];

        $payload = [
            'subject'        => "MycoVision Refund Request Received (#{$refund->id})",
            'badge'          => 'REFUND REQUESTED',
            'badge_tone'     => 'info',
            'heading'        => 'Refund Request Received',
            'recipient_name' => $name,
            'intro'          => "We have logged refund request #{$refund->id} for your {$this->formatPlanName($refund->subscription?->plan)} subscription.",
            'amount_label'   => $requested > 0 ? 'Requested Refund Amount' : null,
            'amount_display' => $requested > 0 ? $amountText : null,
            'details'        => $details,
            'note'           => 'Our team will review your request and send an update within 3 business days.',
            'cta_label'      => 'View Refund Status',
            'cta_url'        => $this->frontendUrl('/users/subscription'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund requested email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * Notify the customer when an approved refund has been submitted to PayMongo asynchronously.
     */
    public function sendRefundProcessing(RefundRequest $refund): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $amount = (float) ($refund->approved_amount ?? $refund->requested_amount ?? 0);
        $amountText = $this->formatCurrency($amount, $this->currencyFor($refund));

        $details = $this->baseRefundDetails($refund, 'Approved & Processing');
        $details[] = ['label' => 'Approved Amount', 'value' => $amountText];
        $details[] = ['label' => 'Reviewed At',     'value' => $this->formatTimestamp($refund->reviewed_at ?? now())];

        $note = 'Your refund has been approved and submitted to PayMongo. You will receive a final confirmation receipt as soon as the transfer settles.';
        if (!empty($refund->admin_note)) {
            $note .= ' Review note: ' . $refund->admin_note;
        }

        $payload = [
            'subject'        => "MycoVision Refund Approved & Processing (#{$refund->id} — {$amountText})",
            'badge'          => 'REFUND PROCESSING',
            'badge_tone'     => 'info',
            'heading'        => 'Refund Approved & Processing',
            'recipient_name' => $name,
            'intro'          => "Your refund request #{$refund->id} has been approved and sent to our payment processor.",
            'amount_label'   => 'Approved Refund Amount',
            'amount_display' => $amountText,
            'details'        => $details,
            'note'           => $note,
            'cta_label'      => 'View Refund Status',
            'cta_url'        => $this->frontendUrl('/users/subscription'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund processing email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * Send the final receipt once PayMongo confirms a refund is complete.
     */
    public function sendRefundCompleted(RefundRequest $refund): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $amount = (float) ($refund->approved_amount ?? $refund->requested_amount ?? 0);
        $amountText = $this->formatCurrency($amount, $this->currencyFor($refund));

        $details = $this->baseRefundDetails($refund, 'Refunded');
        $details[] = ['label' => 'Refunded Amount', 'value' => $amountText];
        $details[] = ['label' => 'Refunded At',     'value' => $this->formatTimestamp($refund->refunded_at ?? now())];

        $note = 'Depending on your payment provider (e.g. GCash), it may take a short time to reflect on your statement. Per our Refund Policy, Premium access for this subscription has ended.';
        if (!empty($refund->admin_note)) {
            $note .= ' Note: ' . $refund->admin_note;
        }

        $payload = [
            'subject'        => "MycoVision Refund Receipt (#{$refund->id} — {$amountText})",
            'badge'          => 'REFUND ISSUED',
            'badge_tone'     => 'success',
            'heading'        => 'Refund Transaction Receipt',
            'recipient_name' => $name,
            'intro'          => "Your refund of {$amountText} for MycoVision {$this->formatPlanName($refund->subscription?->plan)} has been completed.",
            'amount_label'   => 'Amount Refunded',
            'amount_display' => $amountText,
            'details'        => $details,
            'note'           => $note,
            'cta_label'      => 'View Subscription History',
            'cta_url'        => $this->frontendUrl('/users/subscription'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund completed receipt email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * Notify the customer when an admin rejects a refund request.
     */
    public function sendRefundRejected(RefundRequest $refund): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $details = $this->baseRefundDetails($refund, 'Not Approved');
        $details[] = ['label' => 'Reviewed At', 'value' => $this->formatTimestamp($refund->reviewed_at ?? now())];

        $payload = [
            'subject'        => "MycoVision Refund Request Update (#{$refund->id})",
            'badge'          => 'NOT APPROVED',
            'badge_tone'     => 'warning',
            'heading'        => 'Refund Request Not Approved',
            'recipient_name' => $name,
            'intro'          => "After reviewing refund request #{$refund->id}, we were unable to approve it.",
            'details'        => $details,
            'note'           => $refund->admin_note ? 'Reviewer note: ' . $refund->admin_note : null,
            'cta_label'      => 'View Refund Policy',
            'cta_url'        => $this->frontendUrl('/refund-policy'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund rejected email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * Notify the customer when PayMongo reports a refund attempt as failed.
     */
    public function sendRefundFailed(RefundRequest $refund, ?string $gatewayMessage = null): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $amount = (float) ($refund->approved_amount ?? $refund->requested_amount ?? 0);
        $amountText = $amount > 0 ? $this->formatCurrency($amount, $this->currencyFor($refund)) : null;

        $details = $this->baseRefundDetails($refund, 'Gateway Failed');
        if ($amountText) {
            $details[] = ['label' => 'Refund Amount', 'value' => $amountText];
        }
        $details[] = ['label' => 'Updated At', 'value' => $this->formatTimestamp(now())];

        $note = 'The payment gateway could not complete this refund automatically. No funds were reversed yet; please contact support so we can resolve this for you.';
        if ($gatewayMessage) {
            $note .= ' Details: ' . $gatewayMessage;
        }

        $payload = [
            'subject'        => "MycoVision Refund Failed (#{$refund->id})",
            'badge'          => 'REFUND FAILED',
            'badge_tone'     => 'danger',
            'heading'        => 'Refund Processing Failed',
            'recipient_name' => $name,
            'intro'          => "We encountered an issue while processing refund request #{$refund->id} through PayMongo.",
            'details'        => $details,
            'note'           => $note,
            'cta_label'      => 'View Subscription & Refunds',
            'cta_url'        => $this->frontendUrl('/users/subscription'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund failed email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * Confirm when a user withdraws their pending refund request.
     */
    public function sendRefundWithdrawn(RefundRequest $refund): bool
    {
        $refund->loadMissing(['user', 'subscription']);
        [$email, $name] = $this->resolveRecipient($refund);

        $details = $this->baseRefundDetails($refund, 'Withdrawn');
        $details[] = ['label' => 'Withdrawn At', 'value' => $this->formatTimestamp(now())];

        $payload = [
            'subject'        => "MycoVision Refund Request Withdrawn (#{$refund->id})",
            'badge'          => 'WITHDRAWN',
            'badge_tone'     => 'info',
            'heading'        => 'Refund Request Withdrawn',
            'recipient_name' => $name,
            'intro'          => "You have withdrawn refund request #{$refund->id}. Your subscription remains unchanged.",
            'details'        => $details,
            'cta_label'      => 'View Subscription Details',
            'cta_url'        => $this->frontendUrl('/users/subscription'),
            'support_email'  => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund withdrawn email failed', [
            'refund_request_id' => $refund->id,
        ]);
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    private function baseRefundDetails(RefundRequest $refund, string $statusLabel): array
    {
        $sub = $refund->subscription;
        $rows = [
            ['label' => 'Transaction Type', 'value' => 'Subscription Refund' . ($refund->refund_type ? ' (' . ucfirst($refund->refund_type) . ')' : '')],
            ['label' => 'Refund Request',   'value' => '#' . $refund->id],
        ];

        if ($refund->paymongo_refund_id) {
            $rows[] = ['label' => 'Refund Reference', 'value' => $refund->paymongo_refund_id];
        }

        if ($sub) {
            $rows[] = [
                'label' => 'Original Payment',
                'value' => ($sub->paymongo_payment_id ?: 'SUB-' . $sub->id) . ' (' . $this->formatCurrency((float) $sub->price, $this->currencyFor($refund)) . ')',
            ];
            $rows[] = ['label' => 'Plan', 'value' => $this->formatPlanName($sub->plan)];
            $rows[] = ['label' => 'Payment Method', 'value' => $this->formatPaymentMethod($sub->payment_method)];
        }

        $rows[] = ['label' => 'Reason Category', 'value' => $this->formatReasonType($refund->reason_type)];
        $rows[] = ['label' => 'Status',          'value' => $statusLabel];

        return $rows;
    }

    private function deliver(?string $email, array $payload, string $logContext, array $context = []): bool
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::info($logContext . ': skipped because no valid recipient email exists.', $context);
            return false;
        }

        try {
            Mail::to($email)->send(new SubscriptionTransactionMail($payload));
            return true;
        } catch (\Throwable $e) {
            Log::error($logContext . ': ' . $e->getMessage(), array_merge($context, [
                'exception' => $e::class,
                'mailer'    => config('mail.default'),
            ]));
            return false;
        }
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolveRecipient(Subscription|RefundRequest $record): array
    {
        $user = $record->user;
        $email = $user?->email ?: $record->deleted_user_email;
        $name = $user?->name ?: ($user?->first_name ?: 'Customer');

        if ($name === 'Deleted user' && $record->deleted_user_email) {
            $name = 'Customer';
        }

        return [$email ? trim((string) $email) : null, $name];
    }

    private function currencyFor(RefundRequest $refund): string
    {
        return strtoupper((string) ($refund->subscription?->currency ?: 'PHP'));
    }

    private function formatCurrency(float $amount, string $currency = 'PHP'): string
    {
        $symbol = $currency === 'PHP' ? '₱' : $currency . ' ';
        return $symbol . number_format($amount, 2) . ($currency === 'PHP' ? ' PHP' : '');
    }

    private function formatPlanName(?string $plan): string
    {
        return match ($plan) {
            'monthly' => 'Premium Monthly',
            'annual'  => 'Premium Annual',
            null, ''  => 'Premium Subscription',
            default   => ucfirst($plan),
        };
    }

    private function formatPaymentMethod(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'gcash'       => 'GCash',
            'paymaya'     => 'Maya',
            'grab_pay'    => 'GrabPay',
            'card'        => 'Card',
            'admin_grant' => 'Admin Grant',
            '', 'paymongo' => 'PayMongo',
            default       => ucfirst((string) $method),
        };
    }

    private function formatReasonType(?string $reasonType): string
    {
        return match ($reasonType) {
            'first_time_guarantee' => '7-Day Money-Back Guarantee',
            'duplicate_charge'     => 'Duplicate Charge',
            'service_failure'      => 'Extended Service Failure',
            'charged_after_cancel' => 'Charged After Cancellation',
            'annual_prorated'      => 'Annual Prorated Refund',
            default                => 'Other',
        };
    }

    private function formatTimestamp($value): string
    {
        $dt = $value instanceof Carbon ? $value : ($value ? Carbon::parse($value) : now());
        return $dt->copy()->timezone(self::DISPLAY_TIMEZONE)->format('M d, Y g:i A') . ' PHT';
    }

    private function formatDate($value): string
    {
        $dt = $value instanceof Carbon ? $value : Carbon::parse($value);
        return $dt->copy()->timezone(self::DISPLAY_TIMEZONE)->format('M d, Y');
    }

    private function frontendUrl(string $path = ''): string
    {
        $base = rtrim(trim(explode(',', (string) config('app.frontend_url', 'http://localhost:3000'))[0] ?? ''), '/');
        if ($base === '') {
            $base = 'http://localhost:3000';
        }

        return $base . $path;
    }
}

