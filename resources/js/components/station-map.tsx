import { CircleMarker, MapContainer, Popup, TileLayer, Tooltip } from 'react-leaflet';

import { AlertMapLayer } from '@/components/alert-map-layer';
import { useTranslations } from '@/hooks/use-portal';
import type { PublicAlert, PublicStation, StationMeasurement } from '@/types';

interface StationMapProps {
    stations: PublicStation[];
    /**
     * Active warnings drawn beneath the station markers. Optional so a page
     * with no warning source still renders exactly as before — an empty or
     * absent warning layer must never break the station map.
     */
    alerts?: PublicAlert[];
}

const STATUS_COLORS: Record<PublicStation['status'], string> = {
    active: '#047857',
    maintenance: '#b45309',
    offline: '#64748b',
};

function reading(measurement: StationMeasurement): string {
    if (measurement.value === null) {
        return '—';
    }

    return `${measurement.value.toFixed(measurement.precision)} ${measurement.unit}`;
}

export function StationMap({ stations, alerts = [] }: StationMapProps) {
    const t = useTranslations();
    const centre = stations.length
        ? ([
              stations.reduce((total, station) => total + station.latitude, 0) / stations.length,
              stations.reduce((total, station) => total + station.longitude, 0) / stations.length,
          ] as [number, number])
        : ([38.86, 71.28] as [number, number]);

    return (
        <div
            className="overflow-hidden rounded-xl border bg-muted"
            role="region"
            aria-label={t('station_map_label')}
        >
            <MapContainer
                center={centre}
                zoom={6}
                scrollWheelZoom={false}
                className="h-[32rem] w-full"
            >
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                />

                {/* Drawn before the markers so a station stays clickable inside a warning area. */}
                <AlertMapLayer alerts={alerts} />

                {stations.map((station) => (
                    <CircleMarker
                        key={station.id}
                        center={[station.latitude, station.longitude]}
                        radius={9}
                        pathOptions={{
                            color: '#ffffff',
                            weight: 2,
                            fillColor: STATUS_COLORS[station.status],
                            fillOpacity: 0.95,
                        }}
                    >
                        <Tooltip>{station.name}</Tooltip>
                        <Popup>
                            <div className="min-w-48 space-y-2">
                                <div>
                                    <strong>{station.name}</strong>
                                    <br />
                                    <span>{station.code}</span>
                                </div>
                                {station.measurements.length ? (
                                    <ul className="space-y-1">
                                        {station.measurements.map((measurement) => (
                                            <li key={measurement.parameter}>
                                                <strong>{measurement.parameter}:</strong>{' '}
                                                {reading(measurement)}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <span>{t('station_no_measurements')}</span>
                                )}
                                <div>
                                    <a href={`/stations/${station.id}`}>
                                        {t('station_view_details')}
                                    </a>
                                </div>
                            </div>
                        </Popup>
                    </CircleMarker>
                ))}
            </MapContainer>
        </div>
    );
}
