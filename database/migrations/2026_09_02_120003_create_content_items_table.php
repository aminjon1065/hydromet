<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Localized CMS records, docs/03-data-contracts.md section 9.
 *
 * Translation columns stay nullable so an editor may save an incomplete
 * draft. The application refuses to publish until every required title and
 * body is present; PostgreSQL constraints protect the stable enum values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('slug', 160)->unique();
            $table->string('title_tj')->nullable();
            $table->string('title_ru')->nullable();
            $table->string('title_en')->nullable();
            $table->text('summary_tj')->nullable();
            $table->text('summary_ru')->nullable();
            $table->text('summary_en')->nullable();
            $table->longText('body_tj')->nullable();
            $table->longText('body_ru')->nullable();
            $table->longText('body_en')->nullable();
            $table->string('status', 32)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'status']);
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE content_items
                ADD CONSTRAINT content_items_type_check
                CHECK (type IN ('page', 'news', 'bulletin', 'health_advice'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE content_items
                ADD CONSTRAINT content_items_status_check
                CHECK (status IN ('draft', 'published'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE content_items
                ADD CONSTRAINT content_items_slug_check
                CHECK (slug ~ '^[a-z0-9]+(?:-[a-z0-9]+)*$')
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('content_items');
    }
};
