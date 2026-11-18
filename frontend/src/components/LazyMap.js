import {lazy, Suspense} from 'react';
import {Skeleton} from '@mui/material';

// Viteză (audit 18.11.2026): Leaflet (~146 KB) se descarcă doar când o hartă chiar apare pe ecran.
const load = () => import('./SubjectMap');
const LazySubjectMap = lazy(() => load().then((m) => ({default: m.SubjectMap})));
const LazyPickerMap = lazy(() => load().then((m) => ({default: m.PickerMap})));
const LazyLocationDiffMap = lazy(() => load().then((m) => ({default: m.LocationDiffMap})));

const fallback = (height) => <Skeleton variant="rectangular" height={height} sx={{borderRadius: 1}} aria-label="Se încarcă harta"/>;

export const SubjectMap = (props) => <Suspense fallback={fallback(props.height ?? 240)}><LazySubjectMap {...props}/></Suspense>;
export const PickerMap = (props) => <Suspense fallback={fallback(props.height ?? 280)}><LazyPickerMap {...props}/></Suspense>;
export const LocationDiffMap = (props) => <Suspense fallback={fallback(props.height ?? 260)}><LazyLocationDiffMap {...props}/></Suspense>;
