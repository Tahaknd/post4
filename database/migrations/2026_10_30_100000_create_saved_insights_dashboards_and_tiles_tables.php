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
        Schema::create('saved_insights', function (Blueprint $table) {
            $table->id();
            // The identifier of the API (URLs and bodies never carry the auto-increment id).
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // A key of App\Domain\Insights\InsightTypes. Not an enum column: a type is code, and a stored row of a type that
            // was removed must still load (and be reported as needs_review) instead of failing the read.
            $table->string('type', 32);
            // InsightQuery::canonical() as it was when the insight was saved; a relative range is re-resolved from
            // date_preset at run time, so this holds the range as of the last save.
            $table->jsonb('query');
            // The `v` of the canonical array when it was written (see App\Domain\Dashboards\SavedQueryResolver).
            $table->unsignedSmallInteger('query_version');
            // A DatePreset value when the range is relative ("last_7_days"), null for absolute days.
            $table->string('date_preset', 32)->nullable();
            // The zone the range is resolved in (the project's zone at save time unless the body named one).
            $table->string('timezone', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The target of the composite foreign key of dashboard_tiles: a tile cannot join a dashboard and an insight
            // of two different projects. Also the index of "the insights of a project, newest id first".
            $table->unique(['project_id', 'id']);
            $table->index(['project_id', 'updated_at']);
            $table->index('created_by');
        });

        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // When the dashboard was last opened through the API (throttled write, see RecordDashboardView): the
            // scheduled refresh only keeps the dashboards somebody looks at warm.
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'id']);
            $table->index(['project_id', 'updated_at']);
            $table->index('last_viewed_at');
            $table->index('created_by');
        });

        Schema::create('dashboard_tiles', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Repeated from the two parents on purpose: the composite foreign keys below make a cross-project tile
            // impossible whatever the code does (the same pattern as person_distinct_ids).
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dashboard_id');
            $table->foreignId('saved_insight_id');
            // 0-based and contiguous after every write that touches a dashboard's tiles (see the tile actions).
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->foreign(['project_id', 'dashboard_id'])->references(['project_id', 'id'])->on('dashboards')->cascadeOnDelete();
            $table->foreign(['project_id', 'saved_insight_id'])->references(['project_id', 'id'])->on('saved_insights')->cascadeOnDelete();
            // One tile per insight on a dashboard: makes "add" idempotent and a concurrent double add impossible.
            $table->unique(['dashboard_id', 'saved_insight_id']);
            $table->index(['dashboard_id', 'position']);
            // "Which dashboards show this insight" (deleting an insight compacts them).
            $table->index('saved_insight_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_tiles');
        Schema::dropIfExists('dashboards');
        Schema::dropIfExists('saved_insights');
    }
};
