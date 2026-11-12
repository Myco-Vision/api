<?php

namespace Tests\Feature;

use App\Mail\SubscriptionTransactionMail;
use App\Models\RefundRequest;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PayMongoService;
use App\Services\TransactionEmailService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TransactionEmailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.paymongo.secret_key'     => '',
            'services.paymongo.public_key'     => '',
            'services.paymongo.webhook_secret' => '',
            'app.frontend_url'                 => 'https://mycovision.example',
            'services.frontend.url'            => 'https://mycovision.example',
        ]);

        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('username')->nullable();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('forager');
            $table->string('contact_number')->nullable();
            $table->string('address')->nullable();
            $table->string('google_id')->nullable();
            $table->boolean('needs_profile_completion')->default(false);
            $table->string('profile_photo')->nullable();
            $table->boolean('allow_data_training')->default(false);
            $table->timestamp('data_training_consented_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('deleted_user_email')->nullable();
            $table->string('plan');
            $table->string('status')->default('pending');
            $table->boolean('auto_renew')->default(true);
            $table->decimal('price', 10, 2);
            $table->string('currency', 10)->default('PHP');
            $table->string('paymongo_session_id')->nullable();
            $table->string('paymongo_payment_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('deleted_user_email')->nullable();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action');
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('deleted_user_email')->nullable();
            $table->unsignedBigInteger('subscription_id');
            $table->string('reason_type');
            $table->text('reason');
            $table->decimal('requested_amount', 10, 2)->default(0);
            $table->decimal('approved_amount', 10, 2)->nullable();
            $table->string('refund_type')->nullable();
            $table->string('status')->default('pending');
            $table->json('eligibility')->nullable();
            $table->text('admin_note')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('paymongo_refund_id')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_verify_payment_sends_single_receipt_even_with_subsequent_webhook(): void
    {
        Mail::fake();
        $user = $this->createUser('buyer@gmail.com');

        $sub = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'annual',
            'status'              => 'pending',
            'price'               => 1299.00,
            'currency'            => 'PHP',
            'payment_method'      => 'gcash',
            'paymongo_session_id' => 'cs_mock_annual123',
        ]);

        $res = $this->actingAs($user)->postJson('/api/subscription/verify', [
            'session_id' => 'cs_mock_annual123',
            'plan'       => 'annual',
        ]);

        $res->assertOk();
        $this->assertEquals('active', $sub->fresh()->status);
        $this->assertEquals(1299.00, $sub->fresh()->price);
        $this->assertEquals('paid', $sub->fresh()->payment_status);

        // Webhook delivery for the same session must remain idempotent and not resend
        $this->postJson('/api/paymongo/webhook', [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id'         => 'cs_mock_annual123',
                        'attributes' => [
                            'payments' => [[
                                'id'         => 'pay_mock_annual123',
                                'attributes' => ['amount' => 129900, 'currency' => 'PHP', 'source' => ['type' => 'gcash']],
                            ]],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        Mail::assertSent(SubscriptionTransactionMail::class, 1);
        Mail::assertSent(SubscriptionTransactionMail::class, function (SubscriptionTransactionMail $mail) use ($user) {
            return $mail->hasTo($user->email)
                && str_contains($mail->transaction['subject'], 'Premium Annual')
                && str_contains($mail->transaction['amount_display'], '1,299.00');
        });
    }

    public function test_status_auto_sync_and_webhook_send_payment_receipt_once(): void
    {
        Mail::fake();
        $user = $this->createUser('syncuser@gmail.com');

        Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'monthly',
            'status'              => 'pending',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'payment_method'      => 'gcash',
            'paymongo_session_id' => 'cs_mock_sync456',
        ]);

        $this->actingAs($user)->getJson('/api/subscription/status')->assertOk();
        $this->actingAs($user)->getJson('/api/subscription/status')->assertOk();

        Mail::assertSent(SubscriptionTransactionMail::class, 1);
        Mail::assertSent(SubscriptionTransactionMail::class, function (SubscriptionTransactionMail $mail) use ($user) {
            $html = $mail->render();
            return $mail->hasTo($user->email)
                && str_contains($html, 'PAYMENT CONFIRMED')
                && str_contains($html, '149.00 PHP');
        });

        // Pure webhook activation path (without prior verify/status call)
        $webhookUser = $this->createUser('webhookuser@gmail.com');
        Subscription::create([
            'user_id'             => $webhookUser->id,
            'plan'                => 'monthly',
            'status'              => 'pending',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'payment_method'      => 'gcash',
            'paymongo_session_id' => 'cs_live_wh_999',
        ]);

        $payload = [
            'data' => [
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id'         => 'cs_live_wh_999',
                        'attributes' => [
                            'payments' => [[
                                'id'         => 'pay_live_wh_999',
                                'attributes' => ['amount' => 14900, 'currency' => 'PHP', 'source' => ['type' => 'gcash']],
                            ]],
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/paymongo/webhook', $payload)->assertOk();
        $this->postJson('/api/paymongo/webhook', $payload)->assertOk();

        Mail::assertSent(SubscriptionTransactionMail::class, fn ($m) => $m->hasTo($webhookUser->email));
        $this->assertCount(1, Mail::sent(SubscriptionTransactionMail::class, fn ($m) => $m->hasTo($webhookUser->email)));
    }

    public function test_refund_lifecycle_emails_for_immediate_and_async_refunds(): void
    {
        Mail::fake();
        $user = $this->createUser('refunder@gmail.com');
        $admin = $this->createUser('superadmin@gmail.com', 'super_admin');

        $sub1 = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'monthly',
            'status'              => 'active',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'paymongo_payment_id' => 'pay_mock_r1',
            'payment_method'      => 'gcash',
            'payment_status'      => 'paid',
            'starts_at'           => now()->subDay(),
            'expires_at'          => now()->addDays(29),
        ]);

        // 1. User requests refund -> REFUND REQUESTED email
        $storeRes = $this->actingAs($user)->postJson('/api/refunds', [
            'subscription_id' => $sub1->id,
            'reason_type'     => 'first_time_guarantee',
            'reason'          => 'Testing the refund transparency email flow.',
        ]);
        $storeRes->assertCreated();
        $refundId = $storeRes->json('request.id');

        // 2. Super admin approves -> PayMongo mock succeeds immediately -> REFUND ISSUED email (no duplicate processing mail)
        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refundId}/approve", [
            'amount'      => 149.00,
            'refund_type' => 'full',
            'note'        => 'Approved under 7-day guarantee.',
        ])->assertOk();

        // Calling webhook afterwards must be idempotent
        $refund = RefundRequest::findOrFail($refundId);
        $this->postJson('/api/paymongo/webhook', [
            'data' => [
                'attributes' => [
                    'type' => 'payment.refund.updated',
                    'data' => [
                        'id'         => $refund->paymongo_refund_id,
                        'type'       => 'refund',
                        'attributes' => ['status' => 'succeeded'],
                    ],
                ],
            ],
        ])->assertOk();

        $sent = Mail::sent(SubscriptionTransactionMail::class);
        $this->assertCount(2, $sent);
        $this->assertEquals('REFUND REQUESTED', $sent[0]->transaction['badge']);
        $this->assertEquals('REFUND ISSUED', $sent[1]->transaction['badge']);
        $this->assertStringContainsString('149.00 PHP', $sent[1]->transaction['amount_display']);
    }

    public function test_async_refund_processing_rejection_and_failure_emails(): void
    {
        Mail::fake();
        $user = $this->createUser('asyncuser@gmail.com');
        $admin = $this->createUser('admin2@gmail.com', 'super_admin');

        $sub = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'annual',
            'status'              => 'active',
            'price'               => 1299.00,
            'currency'            => 'PHP',
            'paymongo_payment_id' => 'pay_live_async',
            'payment_method'      => 'gcash',
            'payment_status'      => 'paid',
            'starts_at'           => now()->subDays(2),
            'expires_at'          => now()->addDays(363),
        ]);

        // Mock PayMongo returning pending/processing status on first refund
        $mock = Mockery::mock(PayMongoService::class);
        $mock->shouldReceive('createRefund')
            ->once()
            ->andReturn(['id' => 'ref_live_123', 'status' => 'pending', 'amount' => 1299.00, 'mode' => 'paymongo_live']);
        $this->app->instance(PayMongoService::class, $mock);

        $refund = RefundRequest::create([
            'user_id'          => $user->id,
            'subscription_id'  => $sub->id,
            'reason_type'      => 'duplicate_charge',
            'reason'           => 'Charged twice for the same annual plan.',
            'requested_amount' => 1299.00,
            'refund_type'      => 'full',
            'status'           => 'pending',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund->id}/approve", [
            'amount'      => 1299.00,
            'refund_type' => 'full',
        ])->assertOk();

        // Webhook confirms failure
        $this->postJson('/api/paymongo/webhook', [
            'data' => [
                'attributes' => [
                    'type' => 'payment.refund.updated',
                    'data' => [
                        'id'         => 'ref_live_123',
                        'type'       => 'refund',
                        'attributes' => ['status' => 'failed'],
                    ],
                ],
            ],
        ])->assertOk();

        // Rejection path on another pending refund
        $refund2 = RefundRequest::create([
            'user_id'          => $user->id,
            'subscription_id'  => $sub->id,
            'reason_type'      => 'other',
            'reason'           => 'Testing rejection notification email.',
            'requested_amount' => 0,
            'refund_type'      => 'full',
            'status'           => 'pending',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/refunds/{$refund2->id}/reject", [
            'note' => 'Outside refund window per policy.',
        ])->assertOk();

        $badges = Mail::sent(SubscriptionTransactionMail::class)
            ->map(fn (SubscriptionTransactionMail $m) => $m->transaction['badge'])
            ->values()
            ->all();

        $this->assertEquals(['REFUND PROCESSING', 'REFUND FAILED', 'NOT APPROVED'], $badges);
    }

    public function test_admin_created_and_withdrawn_refund_requests_send_emails(): void
    {
        Mail::fake();
        $user = $this->createUser('withdraw@gmail.com');
        $admin = $this->createUser('supportadmin@gmail.com', 'admin');

        $sub = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'monthly',
            'status'              => 'active',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'paymongo_payment_id' => 'pay_mock_wd',
            'payment_method'      => 'gcash',
            'payment_status'      => 'paid',
            'starts_at'           => now()->subDay(),
            'expires_at'          => now()->addDays(29),
        ]);

        $res = $this->actingAs($admin)->postJson("/api/admin/subscriptions/{$sub->id}/refund", [
            'reason_type' => 'first_time_guarantee',
            'reason'      => 'Customer emailed support requesting refund.',
        ]);
        $res->assertCreated();
        $refundId = $res->json('request.id');

        $this->actingAs($user)->postJson("/api/refunds/{$refundId}/cancel")->assertOk();

        $sent = Mail::sent(SubscriptionTransactionMail::class);
        $this->assertCount(2, $sent);
        $this->assertTrue($sent[0]->hasTo($user->email));
        $this->assertEquals('REFUND REQUESTED', $sent[0]->transaction['badge']);
        $this->assertEquals('WITHDRAWN', $sent[1]->transaction['badge']);

        $textBody = view('emails.subscription-transaction-text', ['tx' => $sent[1]->transaction])->render();
        $this->assertStringContainsString('[WITHDRAWN]', $textBody);
        $this->assertStringContainsString('Refund Request Withdrawn', $textBody);
    }

    public function test_mail_delivery_failure_never_breaks_activation(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP down'));

        $user = $this->createUser('resilient@gmail.com');
        $sub = Subscription::create([
            'user_id'             => $user->id,
            'plan'                => 'monthly',
            'status'              => 'pending',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'payment_method'      => 'gcash',
            'paymongo_session_id' => 'cs_mock_resilient',
        ]);

        $this->actingAs($user)->postJson('/api/subscription/verify', [
            'session_id' => 'cs_mock_resilient',
        ])->assertOk();

        $this->assertEquals('active', $sub->fresh()->status);
    }

    public function test_deleted_user_email_fallback_receives_transaction_emails(): void
    {
        Mail::fake();
        $deletedSub = Subscription::create([
            'user_id'             => null,
            'deleted_user_email'  => 'former_member@gmail.com',
            'plan'                => 'monthly',
            'status'              => 'active',
            'price'               => 149.00,
            'currency'            => 'PHP',
            'paymongo_payment_id' => 'pay_deleted_1',
            'payment_method'      => 'gcash',
            'starts_at'           => now()->subDay(),
            'expires_at'          => now()->addDays(29),
        ]);

        app(TransactionEmailService::class)->sendPaymentReceipt($deletedSub);

        Mail::assertSent(SubscriptionTransactionMail::class, fn ($m) => $m->hasTo('former_member@gmail.com'));
    }

    private function createUser(string $email, string $role = 'forager'): User
    {
        return User::create([
            'name'              => 'Test User',
            'first_name'        => 'Test',
            'last_name'         => 'User',
            'username'          => explode('@', $email)[0],
            'email'             => $email,
            'email_verified_at' => now(),
            'password'          => 'Password123!',
            'role'              => $role,
        ]);
    }
}
