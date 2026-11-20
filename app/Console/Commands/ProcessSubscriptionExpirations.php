<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionEvent;
use App\Services\TransactionEmailService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessSubscriptionExpirations extends Command
{
    protected $signature = 'subscriptions:process-expirations';
    protected $description = 'Process expired subscriptions, send pre-expiration alerts, and cleanup abandoned sessions';

    public function handle(TransactionEmailService $mailer): int
    {
        $now = Carbon::now();
        $this->info("Processing subscription lifecycle at {$now->toDateTimeString()}...");

        // 1. Mark expired subscriptions
        $expiredSubs = Subscription::where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        $expiredCount = 0;
        foreach ($expiredSubs as $sub) {
            $sub->update([
                'status'     => 'expired',
                'auto_renew' => false,
            ]);

            SubscriptionEvent::log(
                $sub->user_id,
                'expired',
                $sub->id,
                null,
                [
                    'plan'       => $sub->plan,
                    'expired_at' => $sub->expires_at?->toIso8601String(),
                ]
            );

            // Send expiration notice email
            $mailer->sendSubscriptionExpired($sub);
            $expiredCount++;
        }
        $this->info("Expired subscriptions processed: {$expiredCount}");

        // 2. Pre-expiry alerts: 7-day reminders
        $sevenDaysStart = $now->copy();
        $sevenDaysEnd = $now->copy()->addDays(7);
        $expiring7d = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [$sevenDaysStart, $sevenDaysEnd])
            ->where(function ($q) {
                $q->whereNull('metadata->reminder_7d_sent_at');
            })
            ->get();

        $reminded7d = 0;
        foreach ($expiring7d as $sub) {
            $daysLeft = (int) ceil($now->diffInDays($sub->expires_at, false));
            if ($daysLeft >= 2 && $daysLeft <= 7) {
                $meta = $sub->metadata ?? [];
                $meta['reminder_7d_sent_at'] = $now->toIso8601String();
                $sub->update(['metadata' => $meta]);

                $mailer->sendExpirationReminder($sub, max(1, $daysLeft));
                $reminded7d++;
            }
        }
        $this->info("7-day reminders sent: {$reminded7d}");

        // 3. Pre-expiry alerts: 1-day reminders (within 24-36 hours)
        $oneDayStart = $now->copy();
        $oneDayEnd = $now->copy()->addHours(36);
        $expiring1d = Subscription::where('status', 'active')
            ->whereBetween('expires_at', [$oneDayStart, $oneDayEnd])
            ->where(function ($q) {
                $q->whereNull('metadata->reminder_1d_sent_at');
            })
            ->get();

        $reminded1d = 0;
        foreach ($expiring1d as $sub) {
            $meta = $sub->metadata ?? [];
            $meta['reminder_1d_sent_at'] = $now->toIso8601String();
            $sub->update(['metadata' => $meta]);

            $mailer->sendExpirationReminder($sub, 1);
            $reminded1d++;
        }
        $this->info("1-day reminders sent: {$reminded1d}");

        // 4. Cancel abandoned pending checkout sessions older than 48 hours
        $abandonedCount = Subscription::where('status', 'pending')
            ->where('created_at', '<=', $now->copy()->subHours(48))
            ->update(['status' => 'cancelled']);
        $this->info("Abandoned pending sessions cancelled: {$abandonedCount}");

        return Command::SUCCESS;
    }
}

