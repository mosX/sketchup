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
        Schema::table('part_instances', function (Blueprint $table) {
            $table->foreignId('assembly_group_id')
                ->nullable()
                ->after('part_definition_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['project_id', 'assembly_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('part_instances', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'assembly_group_id']);
            $table->dropConstrainedForeignId('assembly_group_id');
        });
    }
};
