<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->string('confirmation_token', 64)->nullable()->unique();
            $table->timestamp('confirmation_expires_at')->nullable();
            $table->timestamp('consented_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('newsletter_subscribers')->whereNull('consented_at')->delete();

        Schema::table('newsletter_subscribers', function (Blueprint $table): void {
            $table->dropUnique(['confirmation_token']);
            $table->dropColumn(['confirmation_token', 'confirmation_expires_at']);
            $table->timestamp('consented_at')->nullable(false)->change();
        });
    }
};
