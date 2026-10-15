// Jest (CRA) nu transformă node_modules, iar react-leaflet e ESM. În teste harta e doar un container gol:
// jsdom nu are layout, deci Leaflet oricum nu poate desena tile-uri.
const Box = ({children}) => <div data-testid="leaflet-mock">{children}</div>;
const Nothing = () => null;

export const MapContainer = Box;
export const TileLayer = Nothing;
export const Marker = Nothing;
export const CircleMarker = Nothing;
export const useMap = () => ({invalidateSize() {}, setView() {}, fitBounds() {}, getZoom: () => 6});
export const useMapEvents = () => null;
