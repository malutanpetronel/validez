import {useEffect, useRef, useState} from 'react';
import {Alert, Box, Button, Chip, LinearProgress, Paper, Stack, Typography} from '@mui/material';
import PhotoCameraOutlinedIcon from '@mui/icons-material/PhotoCameraOutlined';
import PhotoLibraryOutlinedIcon from '@mui/icons-material/PhotoLibraryOutlined';
import AutoAwesomeOutlinedIcon from '@mui/icons-material/AutoAwesomeOutlined';
import {suggestSubjectFromPhoto} from '../api/ai';
import {hasCordovaCamera, pickWithCordovaCamera, prepareImage} from '../media/prepareImage';

export const PROBLEM_KIND_LABELS = {
    WASTE: 'Gunoi / deșeuri', POTHOLE: 'Groapă', SIDEWALK: 'Trotuar degradat', POLE: 'Stâlp', LIGHTING: 'Iluminat',
    SIGNAGE: 'Semnalizare', VEGETATION: 'Vegetație', STREET_FURNITURE: 'Mobilier urban', DRAINAGE: 'Scurgere / canal',
    DANGEROUS_BUILDING: 'Construcție periculoasă', PARKING: 'Parcare', OTHER: 'Altă problemă',
};
const SEVERITY_LABELS = {LOW: 'gravitate scăzută', MEDIUM: 'gravitate medie', HIGH: 'gravitate ridicată'};

/**
 * ADR-0014 p.10: coordonatele și data se citesc doar în browser; serverul elimină oricum metadatele.
 * @return {Promise<{point: {lat, lng}|null, takenAt: string|null}>}
 */
export async function readPhotoMeta(file) {
    try {
        // Viteză: exifr (~74 KB) doar când un admin chiar alege o fotografie.
        const {default: exifr} = await import('exifr');
        const meta = await exifr.parse(file, {gps: true, pick: ['DateTimeOriginal', 'CreateDate', 'latitude', 'longitude', 'GPSLatitude', 'GPSLongitude', 'GPSLatitudeRef', 'GPSLongitudeRef']});
        const lat = meta?.latitude;
        const lng = meta?.longitude;
        const valid = Number.isFinite(lat) && Number.isFinite(lng) && Math.abs(lat) <= 90 && Math.abs(lng) <= 180 && !(lat === 0 && lng === 0);
        const date = meta?.DateTimeOriginal ?? meta?.CreateDate;
        return {point: valid ? {lat, lng} : null, takenAt: date instanceof Date && !Number.isNaN(date.getTime()) ? date.toISOString() : null};
    } catch {
        return {point: null, takenAt: null};
    }
}

/**
 * Sesizare din fotografie (ADR-0014, doar admini, la creare): fotografia → pin din EXIF + sugestia AI.
 * onPhoto(file|null), onPoint({lat, lng}), onSuggestion(suggestion) — formularul decide ce completează.
 */
export default function AiPhotoSuggestion({nodeId, disabled, onPhoto, onPoint, onSuggestion, onApplyText}) {
    const camera = useRef(null);
    const gallery = useRef(null);
    const [file, setFile] = useState(null);
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);
    const [hasGps, setHasGps] = useState(null);
    const meta = useRef({point: null, takenAt: null});

    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

    const analyze = async (picked) => {
        setBusy(true); setError(null); setResult(null);
        try {
            const r = await suggestSubjectFromPhoto({file: picked, nodeId, takenAt: meta.current.takenAt});
            setResult(r);
            onSuggestion(r.suggestion);
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    };

    const use = async (original) => {
        if (!original) return;
        // EXIF-ul se citește din fișierul original: micșorarea din browser și pluginul Cordova nu-l păstrează.
        meta.current = await readPhotoMeta(original);
        const picked = await prepareImage(original);
        setFile(picked);
        setPreview(URL.createObjectURL(picked));
        onPhoto(picked);
        setHasGps(!!meta.current.point);
        if (meta.current.point) onPoint(meta.current.point);
        await analyze(picked);
    };

    const pick = (event) => {
        const picked = event.target.files?.[0];
        event.target.value = '';
        return use(picked);
    };

    const open = async (fromCamera) => {
        if (!hasCordovaCamera()) {
            (fromCamera ? camera : gallery).current?.click();
            return;
        }
        try {
            await use(await pickWithCordovaCamera(fromCamera));
        } catch (e) {
            setError(e.message);
        }
    };

    const remove = () => {
        setFile(null); setPreview(null); setResult(null); setError(null); setHasGps(null);
        onPhoto(null);
    };

    const s = result?.suggestion;
    return (
        <Paper variant="outlined" sx={{p: 2, minWidth: 0}}>
            <Stack spacing={1.5}>
                <Stack direction="row" spacing={1} sx={{alignItems: 'center'}}>
                    <AutoAwesomeOutlinedIcon color="primary" fontSize="small"/>
                    <Typography variant="subtitle1" component="h3" sx={{fontWeight: 600}}>Sesizare din fotografie</Typography>
                </Stack>
                <Typography variant="body2" color="text.secondary">
                    Fă o poză sau alege una din galerie: AI-ul propune problema, titlul, descrierea și categoria, iar pinul vine din
                    coordonatele fotografiei, dacă le are. Verifică totul înainte de salvare. Fotografia devine prima fotografie a subiectului.
                </Typography>
                <input ref={camera} type="file" accept="image/*" capture="environment" hidden onChange={pick} data-testid="ai-camera"/>
                <input ref={gallery} type="file" accept="image/jpeg,image/png,image/webp,image/heic,image/heif" hidden onChange={pick} data-testid="ai-gallery"/>
                <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                    <Button variant="contained" size="small" startIcon={<PhotoCameraOutlinedIcon/>} onClick={() => open(true)} disabled={disabled || busy}>Fă o poză</Button>
                    <Button variant="outlined" size="small" startIcon={<PhotoLibraryOutlinedIcon/>} onClick={() => open(false)} disabled={disabled || busy}>Din galerie</Button>
                    {file && <Button size="small" color="error" onClick={remove} disabled={disabled || busy}>Scoate fotografia</Button>}
                </Stack>
                {preview && <Box component="img" src={preview} alt="Fotografia aleasă"
                                 sx={{display: 'block', width: '100%', maxWidth: 360, maxHeight: 240, objectFit: 'cover', borderRadius: 2}}/>}
                {busy && <Stack spacing={0.5}><LinearProgress/><Typography variant="body2" color="text.secondary">Se analizează fotografia…</Typography></Stack>}
                {error && <Alert severity="warning" action={file && <Button color="inherit" size="small" onClick={() => analyze(file)}>Reîncearcă</Button>}>{error}</Alert>}
                {hasGps === true && <Alert severity="info">Pinul a fost pus din coordonatele fotografiei. Verifică-l pe hartă.</Alert>}
                {hasGps === false && <Alert severity="info">Fotografia nu are coordonate. Dacă ești la fața locului, apasă „Folosește locația mea”; altfel pune pinul pe hartă.</Alert>}
                {s && <Stack spacing={1}>
                    {!s.isPublicProblem && <Alert severity="warning">AI-ul nu a recunoscut o problemă din spațiul public. Completează manual dacă e cazul.</Alert>}
                    {s.immediateDanger && <Alert severity="error">Pericol imediat semnalat în fotografie.</Alert>}
                    {s.peopleOrPlatesVisible && <Alert severity="warning">În fotografie se pot vedea persoane sau numere de înmatriculare. Gândește-te dacă e necesară publicarea ei.</Alert>}
                    <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                        <Chip size="small" label={PROBLEM_KIND_LABELS[s.problemKind] ?? s.problemKind}/>
                        <Chip size="small" variant="outlined" label={SEVERITY_LABELS[s.severity] ?? s.severity}/>
                        <Chip size="small" variant="outlined" label={`încredere ${Math.round((s.confidence ?? 0) * 100)}%`}/>
                        {result.cached && <Chip size="small" variant="outlined" label="din cache"/>}
                    </Stack>
                    {s.locationClues?.length > 0 && <Typography variant="body2" sx={{overflowWrap: 'anywhere'}}>
                        Indicii de loc din fotografie: <strong>{s.locationClues.join(' · ')}</strong>
                    </Typography>}
                    {s.isPublicProblem && <div><Button size="small" onClick={() => onApplyText(s)}>Folosește titlul și descrierea propuse</Button></div>}
                </Stack>}
            </Stack>
        </Paper>
    );
}
