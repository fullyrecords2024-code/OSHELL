<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'status', 'starts_on', 'ends_on'], 'accounting_period_scope_dates');
        });

        Schema::create('ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('account_type', 20);
            $table->string('currency_code', 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_control_account')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'account_type', 'is_active'], 'ledger_account_scope_type');
        });

        Schema::create('accounting_posting_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 40);
            $table->foreignId('debit_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->foreignId('credit_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'event_type'], 'accounting_posting_rule_once');
        });

        Schema::create('accounting_source_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('source_type', 60);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->date('occurred_on');
            $table->string('description', 255);
            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'event_type', 'source_type', 'source_id'], 'accounting_source_event_once');
            $table->index(['organization_id', 'status', 'occurred_on'], 'accounting_source_event_queue');
        });

        Schema::create('accounting_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('source_event_id')->nullable()->unique()->constrained('accounting_source_events')->restrictOnDelete();
            $table->foreignId('reversal_of')->nullable()->constrained('accounting_journals')->restrictOnDelete();
            $table->string('journal_number', 50);
            $table->date('journal_date');
            $table->string('currency_code', 3);
            $table->string('memo', 500);
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'journal_number'], 'accounting_journal_number_once');
            $table->index(['organization_id', 'status', 'journal_date'], 'accounting_journal_scope_date');
        });

        Schema::create('accounting_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('accounting_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('ledger_accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->decimal('debit_amount', 14, 2)->default(0);
            $table->decimal('credit_amount', 14, 2)->default(0);
            $table->timestamps();
            $table->index(['account_id', 'journal_id'], 'accounting_journal_line_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_journal_lines');
        Schema::dropIfExists('accounting_journals');
        Schema::dropIfExists('accounting_source_events');
        Schema::dropIfExists('accounting_posting_rules');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('accounting_periods');
    }
};
