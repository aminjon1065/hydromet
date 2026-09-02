<?php

namespace App\Domain\Alerts\Data;

use App\Support\Canonical\CanonicalReader;
use App\Support\Canonical\InvalidCanonicalRow;
use App\Support\Canonical\RejectionReason;

/**
 * One canonical affected area, docs/03-data-contracts.md section 7.
 *
 * Geometry is validated structurally here — that it is a GeoJSON Polygon or
 * MultiPolygon whose coordinates are numbers in WGS84 range — because a warning
 * polygon is drawn straight onto a public map and an adapter is untrusted
 * input. Whether the shape is *correct* is Hydromet's business; whether it is
 * safe to render is the portal's.
 */
final readonly class AlertAreaRecord
{
    /**
     * @param  list<array{name: string, value: string}>  $geocodes
     * @param  array<string, mixed>|null  $geometry
     * @param  array{west: float, south: float, east: float, north: float}|null  $bbox
     */
    public function __construct(
        public string $descriptionTj,
        public string $descriptionRu,
        public string $descriptionEn,
        public array $geocodes,
        public ?array $geometry,
        public ?array $bbox,
        public ?float $altitudeM,
        public ?float $ceilingM,
    ) {}

    /**
     * @param  array<array-key, mixed>  $row
     *
     * @throws InvalidCanonicalRow
     */
    public static function fromCanonical(array $row): self
    {
        $reader = new CanonicalReader($row);
        $description = $reader->localized('description');
        $geometry = self::readGeometry($row);

        return new self(
            descriptionTj: $description['tj'],
            descriptionRu: $description['ru'],
            descriptionEn: $description['en'],
            geocodes: self::readGeocodes($row),
            geometry: $geometry,
            bbox: $geometry === null ? null : self::boundingBox($geometry),
            altitudeM: $reader->nullableFloat('altitude_m'),
            ceilingM: $reader->nullableFloat('ceiling_m'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return list<array{name: string, value: string}>
     *
     * @throws InvalidCanonicalRow
     */
    private static function readGeocodes(array $row): array
    {
        $value = $row['geocodes'] ?? [];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                "Field 'geocodes' must be an array of name/value pairs.",
            );
        }

        $geocodes = [];

        foreach ($value as $index => $geocode) {
            if (! is_array($geocode)) {
                throw new InvalidCanonicalRow(
                    RejectionReason::InvalidFieldType,
                    "Field 'geocodes[{$index}]' must be an object with a name and a value.",
                );
            }

            $reader = new CanonicalReader($geocode);
            $geocodes[] = ['name' => $reader->string('name'), 'value' => $reader->string('value')];
        }

        return $geocodes;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>|null
     *
     * @throws InvalidCanonicalRow
     */
    private static function readGeometry(array $row): ?array
    {
        $value = $row['geometry'] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                "Field 'geometry' must be a GeoJSON object.",
            );
        }

        $type = $value['type'] ?? null;

        if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
            throw new InvalidCanonicalRow(
                RejectionReason::UnsupportedGeometry,
                "Only Polygon and MultiPolygon warning areas can be drawn; received '"
                    .(is_string($type) ? mb_substr($type, 0, 40) : 'no type')."'.",
            );
        }

        $coordinates = $value['coordinates'] ?? null;

        if (! is_array($coordinates) || ! array_is_list($coordinates) || $coordinates === []) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                "Field 'geometry.coordinates' must be a non-empty array.",
            );
        }

        // Depth differs by type: a Polygon is rings of positions, a
        // MultiPolygon is polygons of rings of positions.
        $rings = $type === 'Polygon' ? $coordinates : self::flattenMultiPolygon($coordinates);

        foreach ($rings as $ring) {
            self::assertRing($ring);
        }

        return ['type' => $type, 'coordinates' => $coordinates];
    }

    /**
     * @param  list<mixed>  $coordinates
     * @return list<mixed>
     *
     * @throws InvalidCanonicalRow
     */
    private static function flattenMultiPolygon(array $coordinates): array
    {
        $rings = [];

        foreach ($coordinates as $polygon) {
            if (! is_array($polygon) || ! array_is_list($polygon) || $polygon === []) {
                throw new InvalidCanonicalRow(
                    RejectionReason::InvalidFieldType,
                    "Field 'geometry.coordinates' must contain non-empty polygons.",
                );
            }

            foreach ($polygon as $ring) {
                $rings[] = $ring;
            }
        }

        return $rings;
    }

    /**
     * A linear ring: at least four positions, first equal to last, every
     * position a WGS84 longitude/latitude pair.
     *
     * @throws InvalidCanonicalRow
     */
    private static function assertRing(mixed $ring): void
    {
        if (! is_array($ring) || ! array_is_list($ring) || count($ring) < 4) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                'A warning area ring must list at least four positions.',
            );
        }

        $positions = [];

        foreach ($ring as $position) {
            $positions[] = self::assertPosition($position);
        }

        if ($positions[0] !== $positions[count($positions) - 1]) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                'A warning area ring must be closed: its first and last positions must match.',
            );
        }
    }

    /**
     * @return array{float, float}
     *
     * @throws InvalidCanonicalRow
     */
    private static function assertPosition(mixed $position): array
    {
        if (! is_array($position) || ! array_is_list($position) || count($position) < 2) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                'A warning area position must be a [longitude, latitude] pair.',
            );
        }

        [$longitude, $latitude] = $position;

        if (! is_int($longitude) && ! is_float($longitude)) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                'A warning area longitude must be a number.',
            );
        }

        if (! is_int($latitude) && ! is_float($latitude)) {
            throw new InvalidCanonicalRow(
                RejectionReason::InvalidFieldType,
                'A warning area latitude must be a number.',
            );
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidCanonicalRow(
                RejectionReason::LongitudeOutOfRange,
                'A warning area longitude is outside -180..180.',
            );
        }

        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidCanonicalRow(
                RejectionReason::LatitudeOutOfRange,
                'A warning area latitude is outside -90..90.',
            );
        }

        return [(float) $longitude, (float) $latitude];
    }

    /**
     * Extent of a validated geometry, used for the portable bbox filter.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{west: float, south: float, east: float, north: float}
     */
    public static function boundingBox(array $geometry): array
    {
        $west = 180.0;
        $south = 90.0;
        $east = -180.0;
        $north = -90.0;

        foreach (self::positions($geometry['coordinates'] ?? []) as [$longitude, $latitude]) {
            $west = min($west, $longitude);
            $east = max($east, $longitude);
            $south = min($south, $latitude);
            $north = max($north, $latitude);
        }

        return ['west' => $west, 'south' => $south, 'east' => $east, 'north' => $north];
    }

    /**
     * Every [longitude, latitude] pair inside an arbitrarily nested coordinate
     * array. The geometry has already been validated, so the walk is total.
     *
     * @return list<array{float, float}>
     */
    private static function positions(mixed $coordinates): array
    {
        if (! is_array($coordinates) || $coordinates === []) {
            return [];
        }

        $first = $coordinates[0] ?? null;

        if (is_int($first) || is_float($first)) {
            $longitude = $coordinates[0];
            $latitude = $coordinates[1] ?? null;

            return is_int($latitude) || is_float($latitude)
                ? [[(float) $longitude, (float) $latitude]]
                : [];
        }

        $positions = [];

        foreach ($coordinates as $nested) {
            foreach (self::positions($nested) as $position) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
