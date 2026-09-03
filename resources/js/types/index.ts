/**
 * Application locale keys. The standards-based `tg` / `tg-TJ` tag is produced
 * on the server for HTML metadata only and never used as an internal key.
 */
export type LocaleKey = 'tj' | 'ru' | 'en';

export interface LocaleOption {
    value: LocaleKey;
    label: string;
}

export interface LocaleShare {
    current: LocaleKey;
    bcp47: string;
    fallback: LocaleKey;
    available: LocaleOption[];
}

export interface SharedProps {
    locale: LocaleShare;
    displayTimezone: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}

export type MeasurementQuality = 'valid' | 'suspect' | 'missing' | 'corrected';

export interface StationMeasurement {
    parameter: string;
    value: number | null;
    unit: string;
    precision: number;
    quality: MeasurementQuality;
    observedAt: string;
}

export interface PublicStation {
    id: number;
    code: string;
    name: string;
    latitude: number;
    longitude: number;
    status: 'active' | 'maintenance' | 'offline';
    source: string;
    isMock: boolean;
    observedAt: string | null;
    measurements: StationMeasurement[];
}

export type PublicSeriesPeriod = '24h' | '7d' | '30d' | '1y';

export interface StationParameter {
    code: string;
    name: string;
    unit: string;
    precision: number;
}

export interface StationSeriesPoint {
    time: string;
    value: number | null;
    quality: MeasurementQuality;
    corrected: boolean;
    sampleCount: number;
}

export interface StationSeries {
    parameter: string;
    unit: string;
    precision: number;
    points: StationSeriesPoint[];
}

export interface StationSeriesRange {
    from: string;
    to: string;
    period: PublicSeriesPeriod;
    aggregation: 'raw' | 'hour' | 'day';
    series: StationSeries[];
}

export interface StationDetail {
    id: number;
    code: string;
    name: string;
    latitude: number;
    longitude: number;
    elevationM: number | null;
    regionCode: string;
    districtCode: string | null;
    status: PublicStation['status'];
    stationType: 'air_quality' | 'meteorological' | 'combined';
    source: string;
    isMock: boolean;
    lastSynchronizationAt: string | null;
    parameters: StationParameter[];
}

export type AlertSeverity = 'Extreme' | 'Severe' | 'Moderate' | 'Minor' | 'Unknown';

export interface AlertGeocode {
    name: string;
    value: string;
}

/**
 * A GeoJSON Polygon or MultiPolygon, kept structural rather than typed as a
 * `geojson` library shape: the portal only ever reads `type` and hands
 * `coordinates` to Leaflet.
 */
export interface AlertGeometry {
    type: 'Polygon' | 'MultiPolygon';
    coordinates: unknown;
}

export interface PublicAlertArea {
    description: string;
    geometry: AlertGeometry | null;
    geocodes: AlertGeocode[];
}

export interface PublicAlert {
    identifier: string;
    source: string;
    isMock: boolean;
    eventCode: string;
    severity: AlertSeverity;
    urgency: string;
    certainty: string;
    sender: string;
    headline: string;
    description: string;
    instruction: string | null;
    sentAt: string;
    effectiveAt: string | null;
    onsetAt: string | null;
    expiresAt: string;
    areas: PublicAlertArea[];
}

/**
 * One warning on its own page, which carries what the list on the home page
 * cannot: whether this message is still the current one, and if not, when it
 * stopped being so.
 */
export interface PublicAlertDetail extends PublicAlert {
    status: string;
    messageType: string;
    supersededAt: string | null;
    isActive: boolean;
}

/**
 * One row of the published warning history.
 *
 * Lighter than {@link PublicAlert} on purpose: the list draws no map and shows
 * no body text, so it carries area names rather than geometry and no
 * description or instruction at all.
 */
export interface PublicAlertHistoryRow {
    identifier: string;
    source: string;
    isMock: boolean;
    severity: AlertSeverity;
    messageType: string;
    headline: string;
    sentAt: string;
    effectiveAt: string | null;
    expiresAt: string;
    supersededAt: string | null;
    isActive: boolean;
    areas: string[];
}

/**
 * A link in the supersession chain — `Alert → Update → Update → Cancel`.
 *
 * Deliberately small: the history is a way to reach the other messages, not a
 * second copy of them. Each entry is addressable at its own URL under the same
 * source as the message being read.
 */
export interface PublicAlertHistoryEntry {
    identifier: string;
    messageType: string;
    severity: AlertSeverity;
    headline: string;
    sentAt: string;
    supersededAt: string | null;
}
