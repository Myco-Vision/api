<?php

namespace App\Services;

use App\Mail\SubscriptionTransactionMail;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Support\MailDiagnostics;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Testing\Fakes\MailFake;

/**
 * Builds and dispatches transaction receipt/status emails for subscription payments and refunds.
 * Failures are logged and swallowed so gateway callbacks and DB commits never roll back on SMTP issues.
 */
class TransactionEmailService
{
    private const DISPLAY_TIMEZONE = 'Asia/Manila';

    /** @var array{sent: bool, mailer: string, recipient: ?string, warning: ?string} */
    private array $lastDelivery = [
        'sent'      => false,
        'mailer'    => 'log',
        'recipient' => null,
        'warning'   => null,
    ];

    /**
     * @return array{sent: bool, mailer: string, recipient: ?string, warning: ?string}
     */
    public function lastDelivery(): array
    {
        return $this->lastDelivery;
    }

    public function lastWarning(): ?string
    {
        return $this->lastDelivery['warning'];
    }

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
            'email_type'      => 'payment_receipt',
            'user_id'         => $subscription->user_id,
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
            'email_type'        => 'refund_requested',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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
            'email_type'        => 'refund_processing',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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
        $criteria = $this->buildRefundOutcomeCriteria($refund, 'completed', $amountText);

        $details = $this->baseRefundDetails($refund, 'Refund Successful');
        $details[] = ['label' => 'Refunded Amount', 'value' => $amountText];
        $details[] = ['label' => 'Refunded At',     'value' => $this->formatTimestamp($refund->refunded_at ?? now())];

        $note = 'Depending on your payment provider (e.g. GCash), it may take a short time to reflect on your statement. Per our Refund Policy, Premium access for this subscription has ended.';
        if (!empty($refund->admin_note)) {
            $note .= ' Reviewer note: ' . $refund->admin_note;
        }

        $payload = [
            'subject'          => "MycoVision Refund Successful (#{$refund->id} — {$amountText})",
            'badge'            => 'REFUND SUCCESSFUL',
            'badge_tone'       => 'success',
            'heading'          => 'Your Refund Was Successful',
            'recipient_name'   => $name,
            'intro'            => "Your refund of {$amountText} for MycoVision {$this->formatPlanName($refund->subscription?->plan)} was successful and has been issued to your payment method.",
            'amount_label'     => 'Amount Refunded',
            'amount_display'   => $amountText,
            'details'          => $details,
            'criteria_title'   => $criteria['title'],
            'criteria_summary' => $criteria['summary'],
            'criteria_items'   => $criteria['items'],
            'note'             => $note,
            'cta_label'        => 'View Subscription History',
            'cta_url'          => $this->frontendUrl('/users/subscription'),
            'support_email'    => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund completed receipt email failed', [
            'email_type'        => 'refund_completed',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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

        $details = $this->baseRefundDetails($refund, 'Rejected');
        $details[] = ['label' => 'Reviewed At', 'value' => $this->formatTimestamp($refund->reviewed_at ?? now())];
        $criteria = $this->buildRefundOutcomeCriteria($refund, 'rejected');

        $payload = [
            'subject'          => "MycoVision Refund Request Rejected (#{$refund->id})",
            'badge'            => 'REFUND REJECTED',
            'badge_tone'       => 'danger',
            'heading'          => 'Your Refund Request Was Rejected',
            'recipient_name'   => $name,
            'intro'            => "After reviewing refund request #{$refund->id}, your refund request was rejected.",
            'details'          => $details,
            'criteria_title'   => $criteria['title'],
            'criteria_summary' => $criteria['summary'],
            'criteria_items'   => $criteria['items'],
            'note'             => 'Your subscription remains unchanged. Review our Refund Policy for full eligibility terms, or contact support if you have additional billing documentation.',
            'cta_label'        => 'View Refund Policy',
            'cta_url'          => $this->frontendUrl('/refund-policy'),
            'support_email'    => RefundPolicyService::SUPPORT_EMAIL,
        ];

        return $this->deliver($email, $payload, 'Refund rejected email failed', [
            'email_type'        => 'refund_rejected',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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
            'email_type'        => 'refund_failed',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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
            'email_type'        => 'refund_withdrawn',
            'user_id'           => $refund->user_id,
            'subscription_id'   => $refund->subscription_id,
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
        $mailer = (string) config('mail.default');

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $warning = 'No valid recipient email address on file.';
            Log::info($logContext . ': skipped because no valid recipient email exists.', $context);
            $this->recordFailureEvent($context, $email, $warning);
            $this->lastDelivery = [
                'sent'      => false,
                'mailer'    => $mailer,
                'recipient' => $email,
                'warning'   => $warning,
            ];

            return false;
        }

        $isFaked = Mail::getFacadeRoot() instanceof MailFake;
        if (! $isFaked && ($configWarning = MailDiagnostics::configurationWarning()) !== null) {
            Log::warning($logContext . ': ' . $configWarning, $context);
            $this->recordFailureEvent($context, $email, $configWarning);
            $this->lastDelivery = [
                'sent'      => false,
                'mailer'    => $mailer,
                'recipient' => $email,
                'warning'   => $configWarning,
            ];

            return false;
        }

        try {
            Mail::to($email)->send(new SubscriptionTransactionMail($payload));
            $this->lastDelivery = [
                'sent'      => true,
                'mailer'    => $mailer,
                'recipient' => $email,
                'warning'   => null,
            ];

            return true;
        } catch (\Throwable $e) {
            $hint = MailDiagnostics::diagnose($logContext, $e, $context);
            $this->recordFailureEvent($context, $email, $hint);
            $this->lastDelivery = [
                'sent'      => false,
                'mailer'    => $mailer,
                'recipient' => $email,
                'warning'   => $hint,
            ];

            return false;
        }
    }

    /**
     * Persist non-fatal email delivery failures to subscription_events for admin/server visibility.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordFailureEvent(array $context, ?string $email, string $reason): void
    {
        try {
            SubscriptionEvent::log(
                $context['user_id'] ?? null,
                'transaction_email_failed',
                $context['subscription_id'] ?? null,
                null,
                array_filter([
                    'email_type'        => $context['email_type'] ?? null,
                    'refund_request_id' => $context['refund_request_id'] ?? null,
                    'recipient'         => $email,
                    'mailer'            => config('mail.default'),
                    'reason'            => $reason,
                ], static fn ($value) => $value !== null && $value !== '')
            );
        } catch (\Throwable) {
            // Diagnostics persistence must never break checkout or refund workflows.
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

    /**
     * Build the transparent eligibility / decision criteria breakdown explaining why a refund succeeded or was rejected.
     *
     * @return array{title: string, summary: string, items: array<int, array{status: string, label: string, detail: string}>}
     */
    private function buildRefundOutcomeCriteria(RefundRequest $refund, string $outcome, string $amountText = ''): array
    {
        $eval = is_array($refund->eligibility) ? $refund->eligibility : [];
        $adminNote = trim((string) ($refund->admin_note ?? ''));
        $policyNotes = array_values(array_filter(
            is_array($eval['notes'] ?? null) ? $eval['notes'] : [],
            static fn ($note) => is_string($note) && trim($note) !== ''
        ));

        $checks = $this->evaluateRefundPolicyChecks($refund, $eval, $outcome);

        if ($outcome === 'completed') {
            $summary = match ($refund->reason_type) {
                'first_time_guarantee' => 'Your request satisfied our 7-Day Money-Back Guarantee criteria and was settled back to your original payment method.',
                'annual_prorated'      => 'Your annual subscription qualified for a prorated refund within the 30-day window (minus the ₱' . RefundPolicyService::ANNUAL_PROCESSING_FEE . ' processing fee) and was settled via PayMongo.',
                'duplicate_charge'     => 'Our billing team verified a duplicate charge on your subscription and completed a full refund via PayMongo.',
                'charged_after_cancel' => 'Our billing team confirmed a charge occurred after cancellation and completed a full refund via PayMongo.',
                'service_failure'      => 'Our team verified a qualifying extended service interruption and issued a prorated refund via PayMongo.',
                default                => 'Our billing team reviewed and approved your refund request, and PayMongo confirmed settlement.',
            };

            $items = array_merge($checks, [
                [
                    'status' => 'pass',
                    'label'  => 'Approved Reason',
                    'detail' => $this->formatReasonType($refund->reason_type),
                ],
                [
                    'status' => 'pass',
                    'label'  => 'Gateway Settlement',
                    'detail' => 'Completed via PayMongo' . ($amountText !== '' ? " ({$amountText})" : ''),
                ],
            ]);

            if ($adminNote !== '') {
                $items[] = [
                    'status' => 'info',
                    'label'  => 'Reviewer Note',
                    'detail' => $adminNote,
                ];
            }

            return [
                'title'   => 'Refund Approval & Settlement Criteria',
                'summary' => $summary,
                'items'   => $items,
            ];
        }

        $failedChecks = array_values(array_filter($checks, static fn (array $item) => $item['status'] === 'fail'));

        if ($adminNote !== '' && $failedChecks !== []) {
            $summary = 'Your request did not meet all automated policy criteria and was rejected after review: ' . $adminNote;
        } elseif ($adminNote !== '' && $eval !== []) {
            $summary = 'Although automated initial checks passed, your request was declined following admin review: ' . $adminNote;
        } elseif ($adminNote !== '') {
            $summary = 'Your refund request was declined following admin review: ' . $adminNote;
        } elseif ($policyNotes !== []) {
            $summary = 'Your request did not meet our Refund Policy criteria: ' . implode(' ', $policyNotes);
        } else {
            $summary = 'Your refund request did not meet our Refund Policy eligibility criteria.';
        }

        $items = [
            [
                'status' => 'fail',
                'label'  => 'Review Decision',
                'detail' => $adminNote !== '' ? 'Rejected — ' . $adminNote : 'Rejected after policy review',
            ],
            [
                'status' => 'info',
                'label'  => 'Requested Category',
                'detail' => $this->formatReasonType($refund->reason_type),
            ],
        ];

        $items = array_merge($items, $checks);

        if ($checks === [] && $policyNotes !== []) {
            foreach ($policyNotes as $blocker) {
                $items[] = [
                    'status' => 'fail',
                    'label'  => 'Policy Requirement',
                    'detail' => $blocker,
                ];
            }
        }

        return [
            'title'   => 'Refund Rejection Criteria & Review',
            'summary' => $summary,
            'items'   => $items,
        ];
    }

    /**
     * Convert stored eligibility snapshot fields into customer-readable criteria rows.
     *
     * @param  array<string, mixed>  $eval
     * @return array<int, array{status: string, label: string, detail: string}>
     */
    private function evaluateRefundPolicyChecks(RefundRequest $refund, array $eval, string $outcome): array
    {
        if ($eval === []) {
            return [];
        }

        $items = [];
        $isGuaranteeClaim = $refund->reason_type === 'first_time_guarantee';
        $isAnnualClaim = $refund->reason_type === 'annual_prorated';
        $ruleFailStatus = static fn (bool $applicable): string => ($outcome === 'rejected' || $applicable) ? 'fail' : 'info';

        if (array_key_exists('paid', $eval)) {
            $paid = (bool) $eval['paid'];
            $items[] = [
                'status' => $paid ? 'pass' : 'fail',
                'label'  => 'Verified Gateway Payment',
                'detail' => $paid
                    ? 'Subscription was paid via PayMongo and eligible for review.'
                    : 'Not paid through PayMongo or already refunded.',
            ];
        }

        if (array_key_exists('days_since_purchase', $eval)) {
            $days = $eval['days_since_purchase'];
            if ($isAnnualClaim) {
                $windowOk = (bool) ($eval['annual_prorated_eligible'] ?? false)
                    || ($days !== null && $days >= RefundPolicyService::GUARANTEE_DAYS && $days < RefundPolicyService::ANNUAL_PRORATE_DAYS);
                $items[] = [
                    'status' => $windowOk ? 'pass' : 'fail',
                    'label'  => '30-Day Annual Proration Window',
                    'detail' => $days === null
                        ? 'Purchase start date is unavailable.'
                        : ($windowOk
                            ? "{$days} day(s) since purchase (eligible window: days " . RefundPolicyService::GUARANTEE_DAYS . '–' . (RefundPolicyService::ANNUAL_PRORATE_DAYS - 1) . ').'
                            : "{$days} day(s) since purchase (outside days " . RefundPolicyService::GUARANTEE_DAYS . '–' . (RefundPolicyService::ANNUAL_PRORATE_DAYS - 1) . ' proration window).'),
                ];
            } else {
                $inWindow = $days !== null && $days < RefundPolicyService::GUARANTEE_DAYS;
                $items[] = [
                    'status' => $inWindow ? 'pass' : $ruleFailStatus($isGuaranteeClaim),
                    'label'  => '7-Day Guarantee Window',
                    'detail' => $days === null
                        ? 'Purchase start date is unavailable.'
                        : ($inWindow
                            ? "{$days} day(s) since purchase (within the " . RefundPolicyService::GUARANTEE_DAYS . '-day limit).'
                            : "{$days} day(s) since purchase (exceeds the " . RefundPolicyService::GUARANTEE_DAYS . '-day limit).'),
                ];
            }
        }

        if (array_key_exists('is_first_purchase', $eval)) {
            $isFirst = (bool) $eval['is_first_purchase'];
            $items[] = [
                'status' => $isFirst ? 'pass' : $ruleFailStatus($isGuaranteeClaim),
                'label'  => 'First Premium Purchase',
                'detail' => $isFirst
                    ? 'Confirmed first paid Premium subscription on account.'
                    : 'Account had an earlier paid Premium subscription (guarantee applies to first purchase only).',
            ];
        }

        if (array_key_exists('premium_scans_used', $eval)) {
            $used = (int) $eval['premium_scans_used'];
            $cap = (int) ($eval['scan_cap'] ?? RefundPolicyService::GUARANTEE_SCAN_CAP);
            $withinCap = $used <= $cap;
            $items[] = [
                'status' => $withinCap ? 'pass' : $ruleFailStatus($isGuaranteeClaim),
                'label'  => 'Premium Scan Usage',
                'detail' => $withinCap
                    ? "{$used} of {$cap} allowed scans used."
                    : "{$used} scans used (exceeds the {$cap}-scan limit).",
            ];
        }

        if (array_key_exists('prior_guarantee_used', $eval)) {
            $priorUsed = (bool) $eval['prior_guarantee_used'];
            $items[] = [
                'status' => $priorUsed ? $ruleFailStatus($isGuaranteeClaim) : 'pass',
                'label'  => 'One Guarantee Per User',
                'detail' => $priorUsed
                    ? 'A 7-day guarantee refund was already used on this account.'
                    : 'No prior 7-day guarantee refund used.',
            ];
        }

        return $items;
    }
}

