import { GeoJSON, Popup, Tooltip } from 'react-leaflet';

import { useTranslations } from '@/hooks/use-portal';
import { alertSeverityStyle } from '@/lib/alert-severity';
import type { PublicAlert } from '@/types';

interface AlertMapLayerProps {
    alerts: PublicAlert[];
}

/**
 * Warning polygons drawn over the station map.
 *
 * Geometry is handed to react-leaflet's GeoJSON layer rather than converted by
 * hand: GeoJSON positions are [longitude, latitude] and Leaflet's are
 * [latitude, longitude], and letting the library do that swap removes the most
 * common way a warning ends up drawn in the wrong hemisphere.
 *
 * An area with no geometry is skipped here on purpose. It is still listed in
 * the accessible warning list, so a geocode-only warning is never invisible —
 * it just has no shape until Hydromet supplies the administrative boundary
 * dataset (docs/08-hydromet-input-checklist.md, section 3).
 *
 * Polygons are decoration, not the only route to the information: SVG paths are
 * not reliably keyboard reachable, so the same warnings are also rendered as a
 * focusable list beside the map.
 */
export function AlertMapLayer({ alerts }: AlertMapLayerProps) {
    const t = useTranslations();

    return (
        <>
            {alerts.flatMap((alert) => {
                const style = alertSeverityStyle(alert.severity);

                return alert.areas
                    .map((area, index) => {
                        if (area.geometry === null) {
                            return null;
                        }

                        return (
                            <GeoJSON
                                // The identifier plus the area position is the
                                // only stable key: an area carries no id of its
                                // own in the canonical contract.
                                key={`${alert.identifier}-${index}`}
                                data={area.geometry}
                                pathOptions={{
                                    color: style.stroke,
                                    weight: 2,
                                    fillColor: style.fill,
                                    fillOpacity: style.fillOpacity,
                                }}
                            >
                                <Tooltip>{alert.headline}</Tooltip>
                                <Popup>
                                    <div className="min-w-56 space-y-2">
                                        <div>
                                            <strong>{alert.headline}</strong>
                                            <br />
                                            <span>
                                                {t(
                                                    `alert_severity_${alert.severity.toLowerCase()}`,
                                                )}
                                            </span>
                                        </div>
                                        <p>{area.description}</p>
                                        {alert.instruction !== null && <p>{alert.instruction}</p>}
                                        {alert.isMock && <p>{t('mock_data_badge')}</p>}
                                    </div>
                                </Popup>
                            </GeoJSON>
                        );
                    })
                    .filter((element) => element !== null);
            })}
        </>
    );
}
