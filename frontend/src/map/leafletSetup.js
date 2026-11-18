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

export {distanceMeters, formatDistance} from './geo';

export default L;
