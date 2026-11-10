<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16)->default('outbound');
            $table->string('recipient_email');
            $table->string('recipient_name')->nullable();
            $table->string('subject');
            $table->text('body');
            $table->string('category', 40)->default('internal_alerts');
            $table->uuid('related_notification_id')->nullable();
            $table->string('status', 20)->default('sent');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('related_notification_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
