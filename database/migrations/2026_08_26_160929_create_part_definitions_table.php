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
        Schema::create('part_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('material')->nullable();
            $table->decimal('length', 10, 2);
            $table->decimal('width', 10, 2);
            $table->decimal('thickness', 10, 2);
            $table->string('grain_axis')->default('length');
            $table->json('operations')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('part_definitions');
    }
};
