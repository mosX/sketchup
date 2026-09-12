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
        Schema::create('project_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('primary_instance_id')->constrained('part_instances')->cascadeOnDelete();
            $table->foreignId('secondary_instance_id')->constrained('part_instances')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('label')->nullable();
            $table->json('parameters');
            $table->text('note')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->timestamps();

            $table->index(['project_id', 'type']);
            $table->unique(['project_id', 'primary_instance_id', 'secondary_instance_id'], 'project_connections_instance_pair_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_connections');
    }
};
