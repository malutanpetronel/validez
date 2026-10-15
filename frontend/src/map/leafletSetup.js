// Leaflet: stilurile și iconițele implicite ale markerului (webpack nu rezolvă singur căile din CSS-ul Leaflet).
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import iconUrl from 'leaflet/dist/images/marker-icon.png';
import iconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png';
import shadowUrl from 'leaflet/dist/images/marker-shadow.png';

delete L.Icon.Default.prototype._getIconUrl;
L.Icon.Default.mergeOptions({iconUrl, iconRetinaUrl, shadowUrl});

// ADR-0011 p.9: OpenStreetMap ca terț (menționat în pagina de confidențialitate); se încarcă doar cu harta afișată.
export const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
export const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a>';
export const ROMANIA = {center: [45.94, 24.97], zoom: 6};
export const PIN_ZOOM = 16;

/** Distanța în metri între două puncte {lat, lng} (haversine). */
export function distanceMeters(a, b) {
    const rad = (d) => (d * Math.PI) / 180;
    const dLat = rad(b.lat - a.lat);
    const dLng = rad(b.lng - a.lng);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) ** 2;
    return 2 * 6371000 * Math.asin(Math.sqrt(h));
}

export const formatDistance = (m) => (m < 1000 ? `~${Math.max(1, Math.round(m))} m` : `~${(m / 1000).toFixed(1).replace('.', ',')} km`);

export default L;
