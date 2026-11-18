// Calcule pe coordonate, fără Leaflet (ca să nu tragă harta în pachetul principal).

/** Distanța în metri între două puncte {lat, lng} (haversine). */
export function distanceMeters(a, b) {
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(h));
}

export const formatDistance = (m) => (m < 1000 ? `~${Math.max(1, Math.round(m))} m` : `~${(m / 1000).toFixed(1).replace('.', ',')} km`);
