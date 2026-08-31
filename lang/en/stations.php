<?php

/*
 * Station registry and parameter catalogue strings.
 *
 * Provisional wording. Final Tajik, Russian and English terminology is
 * approved by Hydromet (docs/08-hydromet-input-checklist.md, section 5).
 */

return [
    'navigation_group' => 'Reference data',

    'station' => 'Station',
    'stations' => 'Stations',
    'parameter' => 'Parameter',
    'parameters' => 'Parameters',

    'fields' => [
        'code' => 'Code',
        'name' => 'Name',
        'source' => 'Source',
        'external_id' => 'Source identifier',
        'region_code' => 'Region',
        'district_code' => 'District',
        'status' => 'Status',
        'station_type' => 'Type',
        'parameters_count' => 'Parameters',
        'source_updated_at' => 'Source updated',
        'latitude' => 'Latitude',
        'longitude' => 'Longitude',
        'elevation_m' => 'Elevation, m',
        'timezone' => 'Timezone',
        'owner' => 'Owner',
        'installed_at' => 'Installed',
        'imported_at' => 'First imported',
        'updated_at' => 'Last changed',
        'kind' => 'Kind',
        'canonical_unit' => 'Canonical unit',
        'precision' => 'Decimal places',
        'default_averaging_period' => 'Default averaging period',
        'plausible_min' => 'Plausible minimum',
        'plausible_max' => 'Plausible maximum',
        'active' => 'Published',
    ],

    'sections' => [
        'identity' => 'Identity',
        'location' => 'Location',
        'lifecycle' => 'Lifecycle',
        'catalogue' => 'Catalogue',
        'quality_control' => 'Quality control',
        'provenance' => 'Provenance',
    ],

    'statuses' => [
        'active' => 'Active',
        'maintenance' => 'Maintenance',
        'offline' => 'Offline',
        'decommissioned' => 'Decommissioned',
    ],

    'types' => [
        'air_quality' => 'Air quality',
        'meteorological' => 'Meteorological',
        'combined' => 'Combined',
    ],

    'parameter_kinds' => [
        'pollutant' => 'Pollutant',
        'meteorological' => 'Meteorological',
        'derived' => 'Derived',
    ],

    'read_only_notice' => 'Imported reference data. Records are maintained by the source import and cannot be edited here.',
    'not_supplied' => 'Not supplied',
];
