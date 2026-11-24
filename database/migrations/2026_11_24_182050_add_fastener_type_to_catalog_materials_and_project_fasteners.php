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
        Schema::table('catalog_materials', function (Blueprint $table): void {
            $table->string('fastener_type', 16)->nullable();
        });

        Schema::table('project_fasteners', function (Blueprint $table): void {
            $table->string('fastener_type', 16)->default('screw');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_fasteners', function (Blueprint $table): void {
            $table->dropColumn('fastener_type');
        });

        Schema::table('catalog_materials', function (Blueprint $table): void {
            $table->dropColumn('fastener_type');
        });
    }
};
