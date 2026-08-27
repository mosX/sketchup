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
        Schema::create('part_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('part_definition_id')->index()->constrained()->cascadeOnDelete();
            $table->decimal('position_x', 12, 2)->default(0);
            $table->decimal('position_y', 12, 2)->default(0);
            $table->decimal('position_z', 12, 2)->default(0);
            $table->decimal('rotation_x', 8, 3)->default(0);
            $table->decimal('rotation_y', 8, 3)->default(0);
            $table->decimal('rotation_z', 8, 3)->default(0);
            $table->boolean('mirrored')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'part_definition_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_instances');
    }
};
