<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_connections', function (Blueprint $table) {
            $table->string('machining_status', 40)->default('pending')->after('is_verified');
            $table->json('generated_operation_ids')->nullable()->after('machining_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_connections', function (Blueprint $table) {
            $table->dropColumn(['machining_status', 'generated_operation_ids']);
        });
    }
};
