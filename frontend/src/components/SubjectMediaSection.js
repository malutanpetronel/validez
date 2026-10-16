import {useEffect, useRef, useState} from 'react';
import {
    Alert, Box, Button, Chip, Collapse, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, Link, Stack,
    TextField, Typography,
} from '@mui/material';
import AddPhotoAlternateIcon from '@mui/icons-material/AddPhotoAlternate';
import PhotoCameraIcon from '@mui/icons-material/PhotoCamera';
import CollectionsIcon from '@mui/icons-material/Collections';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import LinkIcon from '@mui/icons-material/Link';
import YouTubeIcon from '@mui/icons-material/YouTube';
import OpenInNewIcon from '@mui/icons-material/OpenInNew';
import HistoryIcon from '@mui/icons-material/History';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import StarOutlineIcon from '@mui/icons-material/StarOutline';
import {addLink, fetchMediaHistory, removeMedia, reorderPhotos, uploadPhoto} from '../api/media';
import {hasCordovaCamera, pickWithCordovaCamera, prepareImage} from '../media/prepareImage';
import {movePhoto} from '../media/photoOrder';
import {isPublicPhoto} from '../media/useSubjectMedia';
import {formatDate} from '../subjects/labels';
import MediaImage from './MediaImage';
import PhotoViewer from './PhotoViewer';

const PEOPLE_WARNING = 'Nu include fețe sau numere de înmatriculare care nu sunt necesare. Metadatele (inclusiv locul unde a fost făcută poza) se elimină automat.';
const VIDEO_HOSTS = /(^|\.)(youtube\.com|youtu\.be|vimeo\.com)$/i;

const hostOf = (url) => { try { return new URL(url).hostname.replace(/^www\./, ''); } catch { return url; } };

function History({subjectId, version}) {
    const [events, setEvents] = useState(null);
    useEffect(() => {
        let active = true;
        fetchMediaHistory(subjectId).then((d) => { if (active) setEvents(d.events); }).catch(() => { if (active) setEvents([]); });
        return () => { active = false; };
    }, [subjectId, version]);
    if (!events) return <Typography variant="body2" color="text.secondary">Se încarcă…</Typography>;
    if (!events.length) return <Typography variant="body2" color="text.secondary">Nicio fotografie sau link publicat până acum.</Typography>;
    const what = (e) => (e.kind === 'PHOTO' ? 'fotografie' : 'link');
    return (
        <Stack component="ul" spacing={0.5} sx={{m: 0, pl: 2.5}}>
            {events.map((e, i) => (
                <Typography component="li" variant="body2" key={i}>
                    {formatDate(e.at)} — {e.event === 'ADDED' ? `${what(e)} adăugat${e.kind === 'PHOTO' ? 'ă' : ''}` : `${what(e)} scos${e.kind === 'PHOTO' ? 'ă' : ''}`}
                    {e.event === 'REMOVED' && e.by && ` de ${e.by === 'AUTHOR' ? 'autor' : 'un administrator'}`}
                    {e.url && <> (<Link href={e.url} target="_blank" rel="nofollow ugc noopener noreferrer">{e.label || hostOf(e.url)}</Link>)</>}
                </Typography>
            ))}
        </Stack>
    );
}

/**
 * Fotografiile și linkurile subiectului (ADR-0011). Adaugă și scot doar autorul subiectului și administratorii;
 * la autorii sub moderare, media noi așteaptă aprobarea individual (p.12). Media nu creează revizie (p.2).
 * Datele vin din useSubjectMedia (aceleași cu coperta); vizualizatorul e comandat de pagină (`viewer`), ca și
 * coperta să-l poată deschide.
 */
export default function SubjectMediaSection({subject, canManage, media, viewer, onViewer}) {
    const {data, items, photos, links, version, reload} = media;
    const [error, setError] = useState(null);
    const [notice, setNotice] = useState(null);
    const [busy, setBusy] = useState(false);
    const [confirm, setConfirm] = useState(null);
    const [link, setLink] = useState({url: '', label: ''});
    const [showHistory, setShowHistory] = useState(false);
    const fileInput = useRef(null);
    const listError = media.error;
    const pendingCount = items.filter((m) => m.status === 'PENDING').length;
    const isPublicImage = (m) => isPublicPhoto(subject, m);
    const after = (saved) => {
        setNotice(saved?.status === 'PENDING' ? 'Salvat. Va fi vizibil public după aprobarea unui administrator.' : null);
        reload();
    };
    const run = async (task) => {
        setBusy(true); setError(null); setNotice(null);
        try { after(await task()); } catch (e) { setError(e.message); } finally { setBusy(false); }
    };

    const upload = (file) => file && run(async () => uploadPhoto(subject.id, await prepareImage(file)));
    const fromCamera = (camera) => run(async () => {
        const file = await pickWithCordovaCamera(camera);
        return file ? uploadPhoto(subject.id, file) : null;
    });
    const saveLink = () => run(async () => {
        const saved = await addLink(subject.id, link.url.trim(), link.label.trim());
        setLink({url: '', label: ''});
        return saved;
    });
    const remove = (item) => { setConfirm(null); onViewer(null); run(() => removeMedia(item.id)); };
    // Butoane, nu drag and drop: drag-ul HTML5 nu funcționează la atingere (Cordova, telefon).
    const move = (from, to) => {
        const ids = movePhoto(photos.map((m) => m.id), from, to);
        if (ids) run(() => reorderPhotos(subject.id, ids));
    };
    const canReorder = canManage && photos.length > 1;

    if (!data && !listError) return null;
    if (data && !items.length && !canManage) {
        return <Box><Button size="small" startIcon={<HistoryIcon/>} onClick={() => setShowHistory((s) => !s)}>Istoric fotografii și linkuri</Button>
            <Collapse in={showHistory} unmountOnExit><History subjectId={subject.id} version={version}/></Collapse></Box>;
    }

    return (
        <Stack spacing={1.5}>
            <Typography variant="subtitle1" component="h2" sx={{fontWeight: 600}}>Fotografii și linkuri</Typography>
            {(error || listError) && <Alert severity="error" onClose={() => setError(null)}>{error || listError}</Alert>}
            {notice && <Alert severity="info" onClose={() => setNotice(null)}>{notice}</Alert>}

            {/* O singură fotografie, pentru cine nu o poate scoate, apare doar ca copertă. */}
            {(photos.length > 1 || (canManage && photos.length > 0)) && (
                <Box sx={{display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(96px, 1fr))', gap: 1}}>
                    {photos.map((m, index) => (
                        <Box key={m.id} sx={{position: 'relative'}}>
                            <Box component="button" type="button" onClick={() => onViewer(index)} aria-label={`Deschide fotografia ${index + 1}`}
                                 sx={{p: 0, border: 0, width: '100%', cursor: 'pointer', borderRadius: 2, overflow: 'hidden', bgcolor: 'transparent'}}>
                                <MediaImage item={m} isPublic={isPublicImage(m)} alt={`Fotografie la „${subject.title}”`} sx={{width: '100%', aspectRatio: '1 / 1'}}/>
                            </Box>
                            {m.status === 'PENDING' && <Chip size="small" color="warning" label="În așteptare" sx={{position: 'absolute', left: 4, bottom: 4}}/>}
                            {canReorder && index === 0 && <Chip size="small" color="primary" label="Copertă" sx={{position: 'absolute', left: 4, top: 4}}/>}
                            {canReorder && (
                                <Stack direction="row" sx={{justifyContent: 'center'}}>
                                    <IconButton size="small" sx={{p: 0.25}} aria-label={`Mută fotografia ${index + 1} înainte`} disabled={busy || index === 0}
                                                onClick={() => move(index, index - 1)}><ChevronLeftIcon fontSize="small"/></IconButton>
                                    <IconButton size="small" sx={{p: 0.25}} aria-label={`Fă fotografia ${index + 1} copertă`} title="Fă copertă" disabled={busy || index === 0}
                                                onClick={() => move(index, 0)}><StarOutlineIcon fontSize="small"/></IconButton>
                                    <IconButton size="small" sx={{p: 0.25}} aria-label={`Mută fotografia ${index + 1} după`} disabled={busy || index === photos.length - 1}
                                                onClick={() => move(index, index + 1)}><ChevronRightIcon fontSize="small"/></IconButton>
                                </Stack>
                            )}
                        </Box>
                    ))}
                </Box>
            )}
            {canReorder && (
                <Typography variant="caption" color="text.secondary">
                    Prima fotografie publicată este coperta și imaginea afișată când subiectul e distribuit (Facebook, WhatsApp, X).
                </Typography>
            )}
            {pendingCount > 0 && (
                <Typography variant="caption" color="text.secondary">
                    Cele marcate „În așteptare” le vezi doar tu și administratorii, până la aprobare. Ele intră deja în limita de {data.max} fotografii / {data.maxLinks} linkuri.
                </Typography>
            )}

            {canManage && data && (
                <Stack spacing={1}>
                    <Typography variant="caption" color="text.secondary">{PEOPLE_WARNING}</Typography>
                    <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                        {hasCordovaCamera() ? <>
                            <Button variant="outlined" size="small" startIcon={<PhotoCameraIcon/>} disabled={busy || photos.length >= data.max} onClick={() => fromCamera(true)}>Cameră</Button>
                            <Button variant="outlined" size="small" startIcon={<CollectionsIcon/>} disabled={busy || photos.length >= data.max} onClick={() => fromCamera(false)}>Galerie</Button>
                        </> : <>
                            <input ref={fileInput} type="file" hidden accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.heic,.heif"
                                   onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; upload(f); }}/>
                            <Button variant="outlined" size="small" startIcon={<AddPhotoAlternateIcon/>} disabled={busy || photos.length >= data.max}
                                    onClick={() => fileInput.current?.click()}>Adaugă fotografie</Button>
                        </>}
                        <Typography variant="caption" color="text.secondary">{busy ? 'Se trimite…' : `${photos.length}/${data.max} fotografii · JPEG, PNG, WebP, HEIC, max. 10 MB`}</Typography>
                    </Stack>
                </Stack>
            )}

            {links.length > 0 && (
                <Stack component="ul" spacing={0.5} sx={{m: 0, pl: 0, listStyle: 'none'}}>
                    {links.map((m) => {
                        const host = hostOf(m.url);
                        return (
                            <Stack component="li" key={m.id} direction="row" spacing={1} sx={{alignItems: 'center'}}>
                                {VIDEO_HOSTS.test(host) ? <YouTubeIcon fontSize="small" color="error"/> : <LinkIcon fontSize="small" color="action"/>}
                                <Link href={m.url} target="_blank" rel="nofollow ugc noopener noreferrer" sx={{overflowWrap: 'anywhere'}}>
                                    {m.label || host}
                                </Link>
                                {m.label && <Typography variant="caption" color="text.secondary">{host}</Typography>}
                                <OpenInNewIcon sx={{fontSize: 14}} color="action"/>
                                {m.status === 'PENDING' && <Chip size="small" color="warning" label="În așteptare"/>}
                                {canManage && <IconButton size="small" aria-label="Scoate linkul" disabled={busy} onClick={() => setConfirm(m)}><DeleteOutlineIcon fontSize="small"/></IconButton>}
                            </Stack>
                        );
                    })}
                </Stack>
            )}

            {canManage && data && links.length < data.maxLinks && (
                <Stack direction={{xs: 'column', sm: 'row'}} spacing={1}>
                    <TextField size="small" label="Link (http/https)" value={link.url} onChange={(e) => setLink({...link, url: e.target.value})}
                               inputProps={{maxLength: 2048, inputMode: 'url'}} sx={{flex: 2}}/>
                    <TextField size="small" label="Descriere (opțional)" value={link.label} onChange={(e) => setLink({...link, label: e.target.value})}
                               inputProps={{maxLength: 200}} sx={{flex: 1}}/>
                    <Button variant="outlined" size="small" disabled={busy || !/^https?:\/\/\S+$/i.test(link.url.trim())} onClick={saveLink}>Adaugă link</Button>
                </Stack>
            )}
            {canManage && data && <Typography variant="caption" color="text.secondary">{links.length}/{data.maxLinks} linkuri · Filmările se adaugă ca link (de exemplu YouTube).</Typography>}

            <Box>
                <Button size="small" startIcon={<HistoryIcon/>} onClick={() => setShowHistory((s) => !s)}>
                    {showHistory ? 'Ascunde istoricul' : 'Istoric fotografii și linkuri'}
                </Button>
                <Collapse in={showHistory} unmountOnExit><History subjectId={subject.id} version={version}/></Collapse>
            </Box>

            <PhotoViewer photos={photos} index={viewer} onIndex={onViewer} onClose={() => onViewer(null)} isPublic={isPublicImage}
                         title={subject.title} actions={canManage ? (photo) => (
                             // Vizualizatorul stă peste dialogurile MUI: îl închidem înainte de confirmare.
                             <Button color="error" startIcon={<DeleteOutlineIcon/>} disabled={busy} onClick={() => { onViewer(null); setConfirm(photo); }}>Scoate fotografia</Button>
                         ) : undefined}/>

            <Dialog open={!!confirm} onClose={() => setConfirm(null)}>
                <DialogTitle>{confirm?.kind === 'PHOTO' ? 'Scoți fotografia?' : 'Scoți linkul?'}</DialogTitle>
                <DialogContent>
                    <Typography variant="body2">
                        {confirm?.kind === 'PHOTO' ? 'Fișierul se șterge de pe server; din copiile de siguranță dispare în cel mult 30 de zile. '
                            : ''}Istoricul public va arăta doar că a fost scos și când, fără conținut.
                    </Typography>
                </DialogContent>
                <DialogActions>
                    <Button onClick={() => setConfirm(null)}>Renunță</Button>
                    <Button color="error" variant="contained" onClick={() => remove(confirm)}>Scoate</Button>
                </DialogActions>
            </Dialog>
        </Stack>
    );
}
