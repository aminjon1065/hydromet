<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parameters available at a station, docs/03-data-contracts.md section 3.1
 * (`parameters`). The unique pair keeps a repeated import from duplicating a
 * link, and the timestamps record when a parameter first appeared at a station.
 *
 * A parameter that is still referenced by a station cannot be deleted; removing
 * a station removes only its own links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_parameter', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->foreignId('parameter_id')->constrained('parameters')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['station_id', 'parameter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_parameter');
    }
};
