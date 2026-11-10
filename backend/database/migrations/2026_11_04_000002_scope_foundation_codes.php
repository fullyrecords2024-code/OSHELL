<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_code_unique');
            $table->unique(['organization_id', 'code']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropUnique('departments_organization_id_code_unique');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique('branches_organization_id_code_unique');
            $table->unique('code');
        });
    }
};
