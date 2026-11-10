<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->json('metadata')->nullable();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('metadata');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
