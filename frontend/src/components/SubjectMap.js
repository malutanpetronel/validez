import {useEffect} from 'react';
import {CircleMarker, MapContainer, Marker, TileLayer, useMap, useMapEvents} from 'react-leaflet';
import {Box} from '@mui/material';
import {PIN_ZOOM, ROMANIA, TILE_ATTRIBUTION, TILE_URL} from '../map/leafletSetup';

/** Leaflet calculează dimensiunea la montare; într-un dialog animat containerul își schimbă mărimea după. */
function FixSize() {
    const map = useMap();
    useEffect(() => {
        const t = setTimeout(() => map.invalidateSize(), 250);
        return () => clearTimeout(t);
    }, [map]);
    return null;
}

/** Recentrează când punctul vine din afara hărții (locația dispozitivului). */
function FollowPoint({point, zoom}) {
    const map = useMap();
    useEffect(() => {
        if (point) map.setView([point.lat, point.lng], Math.max(map.getZoom(), zoom));
    }, [map, point?.lat, point?.lng, zoom]); // eslint-disable-line react-hooks/exhaustive-deps
    return null;
}

function FitPoints({points}) {
    const map = useMap();
    useEffect(() => {
        if (points.length === 1) map.setView([points[0].lat, points[0].lng], PIN_ZOOM);
        if (points.length > 1) map.fitBounds(points.map((p) => [p.lat, p.lng]), {padding: [40, 40], maxZoom: PIN_ZOOM + 1});
    }, [map, JSON.stringify(points)]); // eslint-disable-line react-hooks/exhaustive-deps
    return null;
}

function ClickToPlace({onPlace}) {
    useMapEvents({click: (e) => onPlace({lat: e.latlng.lat, lng: e.latlng.lng})});
    return null;
}

const frame = (height) => ({height, width: '100%', borderRadius: 2, overflow: 'hidden', border: 1, borderColor: 'divider',
    '& .leaflet-container': {height: '100%', width: '100%', fontFamily: 'inherit'}});

/** Pinul subiectului, doar pentru citire. */
export function SubjectMap({location, height = 240}) {
    return (
        <Box sx={frame(height)}>
            <MapContainer center={[location.lat, location.lng]} zoom={PIN_ZOOM} scrollWheelZoom={false}>
                <TileLayer url={TILE_URL} attribution={TILE_ATTRIBUTION}/>
                <FixSize/>
                <Marker position={[location.lat, location.lng]}/>
            </MapContainer>
        </Box>
    );
}

/** Alegerea pinului: click pe hartă sau trasul markerului; `onPlace` primește {lat, lng}. */
export function PickerMap({point, onPlace, height = 280}) {
    const start = point ? {center: [point.lat, point.lng], zoom: PIN_ZOOM} : ROMANIA;
    return (
        <Box sx={frame(height)}>
            <MapContainer center={start.center} zoom={start.zoom}>
                <TileLayer url={TILE_URL} attribution={TILE_ATTRIBUTION}/>
                <FixSize/>
                <FollowPoint point={point} zoom={PIN_ZOOM}/>
                <ClickToPlace onPlace={onPlace}/>
                {point && <Marker position={[point.lat, point.lng]} draggable
                                  eventHandlers={{dragend: (e) => { const ll = e.target.getLatLng(); onPlace({lat: ll.lat, lng: ll.lng}); }}}/>}
            </MapContainer>
        </Box>
    );
}

/** Reconfirmare (ADR-0011 p.11): punctul vechi (gri) și cel nou (markerul obișnuit). */
export function LocationDiffMap({before, after, height = 260}) {
    const points = [before, after].filter(Boolean);
    return (
        <Box sx={frame(height)}>
            <MapContainer center={[points[0].lat, points[0].lng]} zoom={PIN_ZOOM}>
                <TileLayer url={TILE_URL} attribution={TILE_ATTRIBUTION}/>
                <FixSize/>
                <FitPoints points={points}/>
                {before && <CircleMarker center={[before.lat, before.lng]} radius={10} pathOptions={{color: '#757575', fillColor: '#9e9e9e', fillOpacity: 0.6, dashArray: '4 4'}}/>}
                {after && <Marker position={[after.lat, after.lng]}/>}
            </MapContainer>
        </Box>
    );
}
