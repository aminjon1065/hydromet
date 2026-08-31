<?php

namespace Tests\Feature\Stations;

use App\Domain\Stations\Models\Parameter;
use App\Domain\Stations\Models\Station;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Schema-level guarantees.
 *
 * The uniqueness tests run on every driver. The CHECK-constraint tests are
 * PostgreSQL-only, because SQLite cannot add table constraints after creation;
 * on SQLite the same rules are enforced by the import service and covered by
 * StationRegistryImportTest.
 */
class StationSchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function source_and_external_id_are_unique_together(): void
    {
        Station::factory()->create([
            'source' => 'test',
            'external_id' => 'shared-id',
            'code' => 'TEST-A',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Station::factory()->create([
            'source' => 'test',
            'external_id' => 'shared-id',
            'code' => 'TEST-B',
        ]);
    }

    #[Test]
    public function source_and_code_are_unique_together(): void
    {
        Station::factory()->create([
            'source' => 'test',
            'external_id' => 'first-id',
            'code' => 'SHARED-CODE',
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        Station::factory()->create([
            'source' => 'test',
            'external_id' => 'second-id',
            'code' => 'SHARED-CODE',
        ]);
    }

    #[Test]
    public function the_same_identifiers_may_be_reused_by_a_different_source(): void
    {
        Station::factory()->create([
            'source' => 'test',
            'external_id' => 'shared-id',
            'code' => 'SHARED-CODE',
        ]);

        Station::factory()->create([
            'source' => 'other',
            'external_id' => 'shared-id',
            'code' => 'SHARED-CODE',
        ]);

        $this->assertSame(2, Station::query()->count());
    }

    #[Test]
    public function a_parameter_code_is_unique(): void
    {
        Parameter::factory()->create(['code' => 'PM25']);

        $this->expectException(UniqueConstraintViolationException::class);

        Parameter::factory()->create(['code' => 'PM25']);
    }

    #[Test]
    public function a_station_cannot_link_the_same_parameter_twice(): void
    {
        $station = Station::factory()->create();
        $parameter = Parameter::factory()->create();

        $station->parameters()->attach($parameter);

        $this->expectException(UniqueConstraintViolationException::class);

        $station->parameters()->attach($parameter);
    }

    #[Test]
    public function coordinates_keep_six_decimal_places(): void
    {
        $station = Station::factory()->create([
            'latitude' => 38.123456,
            'longitude' => 68.987654,
        ]);

        $station->refresh();

        $this->assertSame('38.123456', $station->latitude);
        $this->assertSame('68.987654', $station->longitude);
    }

    #[Test]
    public function the_timezone_column_defaults_to_the_public_timezone(): void
    {
        $id = DB::table('stations')->insertGetId([
            'source' => 'test',
            'external_id' => 'default-timezone',
            'code' => 'TZ-001',
            'name_tj' => 'tj',
            'name_ru' => 'ru',
            'name_en' => 'en',
            'latitude' => 38.5,
            'longitude' => 68.7,
            'region_code' => 'TEST-REGION',
            'status' => 'active',
            'station_type' => 'air_quality',
            'source_updated_at' => '2026-08-31 06:00:00',
            'created_at' => '2026-08-31 06:00:00',
            'updated_at' => '2026-08-31 06:00:00',
        ]);

        $this->assertSame('Asia/Dushanbe', Station::query()->findOrFail($id)->timezone);
    }

    #[Test]
    public function every_expected_column_exists(): void
    {
        $this->assertTrue(Schema::hasColumns('stations', [
            'source', 'external_id', 'code', 'name_tj', 'name_ru', 'name_en',
            'latitude', 'longitude', 'elevation_m', 'region_code', 'district_code',
            'timezone', 'status', 'station_type', 'owner', 'installed_at',
            'source_updated_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('parameters', [
            'code', 'kind', 'name_tj', 'name_ru', 'name_en', 'canonical_unit',
            'precision', 'default_averaging_period', 'plausible_min',
            'plausible_max', 'active', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('station_parameter', [
            'station_id', 'parameter_id', 'created_at', 'updated_at',
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_latitude_outside_the_allowed_range(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('stations')->insert($this->rawStation(['latitude' => 91]));
    }

    #[Test]
    public function postgresql_rejects_a_longitude_outside_the_allowed_range(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('stations')->insert($this->rawStation(['longitude' => -181]));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_station_status(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('stations')->insert($this->rawStation(['status' => 'retired']));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_station_type(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('stations')->insert($this->rawStation(['station_type' => 'radar']));
    }

    #[Test]
    public function postgresql_rejects_a_blank_station_code(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('stations')->insert($this->rawStation(['code' => '   ']));
    }

    #[Test]
    public function postgresql_rejects_an_unknown_parameter_kind(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('parameters')->insert($this->rawParameter(['kind' => 'index']));
    }

    #[Test]
    public function postgresql_rejects_reversed_plausible_bounds(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('parameters')->insert($this->rawParameter([
            'plausible_min' => 100,
            'plausible_max' => 10,
        ]));
    }

    #[Test]
    public function postgresql_rejects_a_precision_the_portal_cannot_render(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        DB::table('parameters')->insert($this->rawParameter(['precision' => 9]));
    }

    #[Test]
    public function postgresql_keeps_a_parameter_that_is_still_used_by_a_station(): void
    {
        $this->requirePostgres();

        $station = Station::factory()->create();
        $parameter = Parameter::factory()->create();
        $station->parameters()->attach($parameter);

        $this->expectException(QueryException::class);

        DB::table('parameters')->where('id', $parameter->id)->delete();
    }

    #[Test]
    public function postgresql_has_the_postgis_extension_available(): void
    {
        $this->requirePostgres();

        $installed = DB::selectOne("select extname from pg_extension where extname = 'postgis'");

        $this->assertNotNull($installed);
    }

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints and PostGIS are verified on PostgreSQL only.');
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawStation(array $overrides): array
    {
        return [
            'source' => 'test',
            'external_id' => 'constraint-check',
            'code' => 'CHECK-001',
            'name_tj' => 'tj',
            'name_ru' => 'ru',
            'name_en' => 'en',
            'latitude' => 38.5,
            'longitude' => 68.7,
            'region_code' => 'TEST-REGION',
            'timezone' => 'Asia/Dushanbe',
            'status' => 'active',
            'station_type' => 'air_quality',
            'source_updated_at' => '2026-08-31 06:00:00',
            'created_at' => '2026-08-31 06:00:00',
            'updated_at' => '2026-08-31 06:00:00',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function rawParameter(array $overrides): array
    {
        return [
            'code' => 'CHECK1',
            'kind' => 'pollutant',
            'name_tj' => 'tj',
            'name_ru' => 'ru',
            'name_en' => 'en',
            'canonical_unit' => 'ug/m3',
            'precision' => 1,
            'active' => true,
            'created_at' => '2026-08-31 06:00:00',
            'updated_at' => '2026-08-31 06:00:00',
            ...$overrides,
        ];
    }
}
