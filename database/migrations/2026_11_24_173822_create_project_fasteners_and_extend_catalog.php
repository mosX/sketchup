<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_materials', function (Blueprint $table): void {
            $table->decimal('fastener_diameter', 6, 2)->nullable();
            $table->decimal('fastener_length', 7, 2)->nullable();
        });
        Schema::create('project_fasteners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_instance_id')->constrained('part_instances')->cascadeOnDelete();
            $table->foreignId('target_instance_id')->nullable()->constrained('part_instances')->nullOnDelete();
            $table->foreignId('catalog_material_id')->constrained()->cascadeOnDelete();
            $table->string('face', 8);
            $table->decimal('center_u', 9, 2);
            $table->decimal('center_v', 9, 2);
            $table->decimal('sink', 7, 2);
            $table->decimal('diameter', 6, 2);
            $table->decimal('length', 7, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fasteners');
        Schema::table('catalog_materials', function (Blueprint $table): void {
            $table->dropColumn(['fastener_diameter', 'fastener_length']);
        });
    }
};
