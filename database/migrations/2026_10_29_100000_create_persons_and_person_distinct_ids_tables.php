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
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            // Time-ordered uuid (v7): the identifier ClickHouse rows carry for a person.
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // Property values are merged on a person merge (see IdentityResolver); '{}' is a valid default for jsonb on
            // Postgres and plain text on SQLite.
            $table->jsonb('properties')->default('{}');
            $table->boolean('is_identified')->default(false);
            $table->timestamps();

            // The target of the composite foreign key of person_distinct_ids: a distinct id cannot point at a person
            // of another project.
            $table->unique(['project_id', 'id']);
            $table->index('properties', 'persons_properties_gin', 'gin');
        });

        Schema::create('person_distinct_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id');
            $table->text('distinct_id');
            // Starts at 0 and goes up by one every time the row is moved to another person, so the ClickHouse copy
            // (ReplacingMergeTree(version)) always keeps the latest assignment of a distinct id, whatever order the
            // rows arrive in.
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'distinct_id']);
            $table->foreign(['project_id', 'person_id'])->references(['project_id', 'id'])->on('persons')->cascadeOnDelete();
            $table->index('person_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_distinct_ids');
        Schema::dropIfExists('persons');
    }
};
