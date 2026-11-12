<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Unified receipt / status email for MycoVision subscription payment and refund transactions.
 * Sent synchronously (no ShouldQueue) so delivery works on serverless runtimes without workers.
 */
class SubscriptionTransactionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(public array $transaction)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) ($this->transaction['subject'] ?? 'MycoVision Subscription Transaction'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-transaction',
            text: 'emails.subscription-transaction-text',
            with: [
                'tx' => $this->transaction,
            ],
        );
    }
}

