// Adresa API-ului: REACT_APP_API_BASE din .env.local (dev) sau --build-arg la Dockerfile.prod.
// CRA o "arde" in bundle la build, nu se citeste la runtime.
export const API_BASE = process.env.REACT_APP_API_BASE || 'http://localhost:8020';
// Prefixul API Platform; daca se decide versionarea (ca la ArtaNFT /api/v1), se schimba doar aici.
export const API_ENDPOINT = `${API_BASE}/api`;
