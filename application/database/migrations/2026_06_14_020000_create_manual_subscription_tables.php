<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('subscription_plans')) {
            Schema::create('subscription_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('price_label')->nullable();
                $table->unsignedInteger('price_rub')->nullable();
                $table->unsignedSmallInteger('period_days')->nullable();
                $table->json('features')->nullable();
                $table->json('limits')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(100)->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('widget_subscriptions')) {
            Schema::create('widget_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('app_id')->nullable()->constrained('apps')->nullOnDelete();
                $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
                $table->string('widget')->index();
                $table->string('status', 32)->default('active')->index();
                $table->date('starts_at')->nullable()->index();
                $table->date('ends_at')->nullable()->index();
                $table->date('grace_until')->nullable()->index();
                $table->timestamp('blocked_at')->nullable()->index();
                $table->timestamp('last_notified_at')->nullable();
                $table->json('notification_log')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['user_id', 'widget', 'status'], 'widget_subscriptions_user_widget_status_idx');
                $table->index(['user_id', 'widget', 'ends_at'], 'widget_subscriptions_user_widget_ends_idx');
            });
        }

        if (!Schema::hasTable('subscription_invoice_requests')) {
            Schema::create('subscription_invoice_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
                $table->string('widget')->nullable()->index();
                $table->string('status', 32)->default('new')->index();
                $table->string('contact_name')->nullable();
                $table->string('contact_email')->nullable();
                $table->string('contact_phone')->nullable();
                $table->text('comment')->nullable();
                $table->text('manager_note')->nullable();
                $table->timestamp('resolved_at')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoice_requests');
        Schema::dropIfExists('widget_subscriptions');
        Schema::dropIfExists('subscription_plans');
    }
};
