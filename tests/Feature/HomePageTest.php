<?php

namespace Tests\Feature;

use App\Domain\Integrations\Fixtures\FixtureMeasurementProvider;
use App\Domain\Integrations\Fixtures\FixtureStationRegistryProvider;
use App\Domain\Measurements\Services\MeasurementImporter;
use App\Domain\Stations\Services\StationRegistryImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_home_page_renders_the_inertia_react_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('home')
                ->has('generatedAt')
                ->where('stations', [])
                ->where('displayTimezone', 'Asia/Dushanbe')
                ->has('locale.available', 3)
                ->has('translations.brand_name'));
    }

    #[Test]
    public function the_home_page_receives_the_localized_public_station_snapshot(): void
    {
        (new StationRegistryImporter)->import(new FixtureStationRegistryProvider);
        (new MeasurementImporter)->import(new FixtureMeasurementProvider);

        $this->withSession(['locale' => 'en'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('stations', 3)
                ->where('stations.0.code', 'FIXTURE-001')
                ->where('stations.0.name', 'Fixture station 001 (fixture)')
                ->where('stations.0.isMock', true)
                ->where('stations.0.measurements.0.parameter', 'PM10')
                ->where('stations.0.measurements.0.value', 41.5)
                ->where('stations.0.measurements.1.parameter', 'PM25')
                ->where('stations.0.measurements.1.value', 23.4)
                ->where('stations.2.measurements', []));
    }

    #[Test]
    public function the_root_template_declares_a_standards_based_language_tag(): void
    {
        $this->withSession(['locale' => 'tj'])
            ->get('/')
            ->assertOk()
            ->assertSee('<html lang="tg-TJ">', false);
    }
}
