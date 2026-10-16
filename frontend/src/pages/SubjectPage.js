import {useEffect, useRef, useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate, useParams} from 'react-router-dom';
import {Alert, Box, Button, Chip, Paper, Skeleton, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import EditIcon from '@mui/icons-material/Edit';
import PlaceIcon from '@mui/icons-material/Place';
import PhotoLibraryIcon from '@mui/icons-material/PhotoLibrary';
import {useAuth} from '../auth/AuthContext';
import {fetchSubject} from '../api/subjects';
import {administrativeText, canEditSubject, formatDate, locationConfirmationText, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from '../components/SubjectForm';
import SubjectEstimate from '../components/SubjectEstimate';
import AuthorPublishing from '../components/AuthorPublishing';
import SubjectPrivateNote from '../components/SubjectPrivateNote';
import SubjectVotes from '../components/SubjectVotes';
import SubjectMediaSection from '../components/SubjectMediaSection';
import MediaImage from '../components/MediaImage';
import useSubjectMedia, {isPublicPhoto} from '../media/useSubjectMedia';
import {SubjectMap} from '../components/SubjectMap';
import CategoryBreadcrumbs from '../components/CategoryBreadcrumbs';
import {fetchNodeByPath} from '../api/tree';
import usePageMeta, {excerpt} from '../seo/usePageMeta';
import {categoryPathOf} from '../seo/paths';
import {hideSplash} from '../splash';

/** Forma cardului cât se încarcă: titlu, rând de detalii, copertă 16:9, text - fără salt la apariția conținutului. */
function SubjectSkeleton({header}) {
    return (
        <Paper sx={{p: {xs: 2, sm: 3}}} aria-busy="true" aria-label="Se încarcă subiectul">
            <Stack spacing={1.5}>
                {header}
                <Stack direction="row" spacing={0.75}><Skeleton variant="rounded" width={72} height={24}/><Skeleton variant="rounded" width={64} height={24}/></Stack>
                <Skeleton variant="text" sx={{fontSize: '1.5rem', width: '75%'}}/>
                <Skeleton variant="text" sx={{width: '55%'}}/>
                <Skeleton variant="rounded" sx={{width: '100%', height: 'auto', aspectRatio: '16 / 9', maxHeight: 420}}/>
                <Skeleton variant="text"/><Skeleton variant="text"/><Skeleton variant="text" sx={{width: '60%'}}/>
            </Stack>
        </Paper>
    );
}

/**
 * embedded: în panoul arborelui (fără buton înapoi, breadcrumbs, redirect canonic); meta: titlul/meta paginii vin de aici.
 * focusTitle: contor; la fiecare creștere (selectarea subiectului cu click) linia titlului e adusă în vedere și primește focus.
 * header: primul rând din card (în arbore, breadcrumb-ul categoriei - cardul pornește la nivelul arborelui).
 */
export default function SubjectPage({subjectId, embedded = false, meta = false, onUpdated, focusTitle = 0, header = null}) {
    const params = useParams();
    const location = useLocation();
    const navigate = useNavigate();
    const id = subjectId ?? params.id;
    const {user, isAdmin} = useAuth();
    const [subject, setSubject] = useState(null);
    const [error, setError] = useState(null);
    const [notFound, setNotFound] = useState(false);
    const [editing, setEditing] = useState(false);
    const [noteVersion, setNoteVersion] = useState(0);
    const [crumbs, setCrumbs] = useState([]);
    const locationVotes = useRef(null);
    const titleRef = useRef(null);
    const [viewer, setViewer] = useState(null);
    const media = useSubjectMedia(id, user?.id);
    // Cardul apare o singură dată, cu subiectul și lista de fotografii: coperta nu mai împinge descrierea în jos.
    const ready = Boolean(error) || (subject !== null && (media.data !== null || media.error !== null));
    useEffect(() => {
        if (ready) hideSplash();
    }, [ready]);

    // Doar la o selectare cu click (contorul > 0), nu la deschiderea directă dintr-un URL. Titlul există abia când
    // cardul e afișat (subiect + fotografii), deci așteptăm și lista de fotografii.
    const loadedId = subject && (media.data !== null || media.error !== null) ? subject.id : undefined;
    useEffect(() => {
        if (!focusTitle || !loadedId || !titleRef.current) return undefined;
        const frame = requestAnimationFrame(() => {
            titleRef.current?.scrollIntoView({behavior: 'smooth', block: 'start'});
            titleRef.current?.focus({preventScroll: true});
        });
        return () => cancelAnimationFrame(frame);
    }, [focusTitle, loadedId]);

    useEffect(() => {
        setSubject(null);
        setError(null);
        setNotFound(false);
        let active = true;
        fetchSubject(id).then((s) => { if (active) setSubject(s); }).catch((e) => {
            if (!active) return;
            setNotFound(e.status === 404);
            setError(e.message);
        });
        return () => { active = false; };
    }, [id, user?.id]);

    // ADR-0011 p.9: votul LOCATION poate confirma locul; la o schimbare a rezultatelor lui reîncărcăm subiectul
    // (starea confirmării vine de la server). Prima numărătoare doar se reține.
    useEffect(() => {
        locationVotes.current = null;
        const onVotes = (event) => {
            if (event.detail?.subjectId !== id) return;
            const type = event.detail.summary?.types?.find((t) => t.code === 'LOCATION');
            const signature = type ? JSON.stringify(type.result) : null;
            if (locationVotes.current !== null && signature !== locationVotes.current) {
                fetchSubject(id).then(setSubject).catch(() => {});
            }
            locationVotes.current = signature;
        };
        window.addEventListener('subject-votes-updated', onVotes);
        return () => window.removeEventListener('subject-votes-updated', onVotes);
    }, [id]);

    // ADR-0008 (doar ca pagină de sine stătătoare): URL-ul canonic — și după un titlu nou salvat aici —,
    // breadcrumbs ale categoriei, titlu/meta; subiectele nepublicate nu se indexează.
    const canonicalPath = embedded ? null : subject?.canonicalPath ?? null;
    useEffect(() => {
        if (canonicalPath && location.pathname !== canonicalPath) navigate(canonicalPath, {replace: true, state: location.state});
    }, [canonicalPath, location.pathname, location.state, navigate]);
    const categoryPath = categoryPathOf(canonicalPath);
    useEffect(() => {
        setCrumbs([]);
        if (!categoryPath) return undefined;
        let active = true;
        fetchNodeByPath(categoryPath).then((n) => { if (active) setCrumbs(n.breadcrumbs ?? []); }).catch(() => {});
        return () => { active = false; };
    }, [categoryPath]);
    usePageMeta(subject
        ? {title: subject.title, description: excerpt(subject.description), path: subject.canonicalPath ?? null, noindex: subject.visibility !== 'PUBLISHED'}
        : {title: notFound ? 'Subiect negăsit' : null, noindex: notFound}, !embedded || meta);

    return (
        <Stack spacing={2}>
            {!embedded && <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>}
            {!embedded && <CategoryBreadcrumbs items={crumbs}/>}
            {error && <Alert severity="warning">{error}</Alert>}
            {!ready && <SubjectSkeleton header={header}/>}
            {ready && subject && (
                <Paper sx={{p: {xs: 2, sm: 3}}}>
                    <Stack spacing={1.5}>
                        {header}
                        <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap'}}>
                            <Chip size="small" label={TYPE_LABELS[subject.type] ?? subject.type} variant="outlined"/>
                            <Chip size="small" label={STAGE_LABELS[subject.stage] ?? subject.stage} color={STAGE_COLORS[subject.stage] ?? 'default'}/>
                            {subject.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined"/>}
                            {subject.visibility === 'PENDING' && <Chip size="small" label="În așteptarea aprobării" color="warning" variant="outlined"/>}
                        </Stack>
                        <Typography ref={titleRef} tabIndex={-1} variant="h5" component="h1"
                                    sx={{wordBreak: 'break-word', scrollMarginTop: 80, outline: 'none'}}>{subject.title}</Typography>
                        <Typography variant="body2" color="text.secondary">
                            În <Box component="strong" sx={{color: 'primary.main'}}>{subject.nodeName}</Box>
                            {' · de '}<Box component="span" sx={{color: 'primary.main', fontStyle: 'italic'}}>{subject.authorName}</Box>
                            {' · '}<Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.createdAt)}</Box>
                            {subject.updatedAt !== subject.createdAt && <> · modificat <Box component="span" sx={{color: 'primary.main'}}>{formatDate(subject.updatedAt)}</Box></>}
                        </Typography>
                        <SubjectEstimate subject={subject}/>
                        {media.photos.length > 0 && (() => {
                            const cover = media.photos[0];
                            return (
                                <Box component="button" type="button" onClick={() => setViewer(0)}
                                     aria-label={media.photos.length > 1 ? `Deschide fotografiile (${media.photos.length})` : 'Deschide fotografia'}
                                     sx={{p: 0, border: 0, display: 'block', width: '100%', cursor: 'zoom-in', borderRadius: 2,
                                         overflow: 'hidden', position: 'relative', bgcolor: 'transparent'}}>
                                    <MediaImage key={cover.id} item={cover} variant="display" isPublic={isPublicPhoto(subject, cover)}
                                                alt={`Fotografie la „${subject.title}”`} sx={{width: '100%', aspectRatio: '16 / 9', maxHeight: 420}}/>
                                    {media.photos.length > 1 && <Chip size="small" icon={<PhotoLibraryIcon/>} label={`${media.photos.length} fotografii`}
                                                                      sx={{position: 'absolute', right: 8, bottom: 8, color: '#fff', bgcolor: 'rgba(0,0,0,0.6)', '& .MuiChip-icon': {color: '#fff'}}}/>}
                                    {cover.status === 'PENDING' && <Chip size="small" color="warning" label="În așteptare" sx={{position: 'absolute', left: 8, bottom: 8}}/>}
                                </Box>
                            );
                        })()}
                        <Typography sx={{whiteSpace: 'pre-wrap', wordBreak: 'break-word'}}>{subject.description}</Typography>
                        {subject.location && (
                            <Stack spacing={0.75}>
                                <Stack direction="row" spacing={1} sx={{alignItems: 'center', flexWrap: 'wrap'}} useFlexGap>
                                    <PlaceIcon fontSize="small" color="primary"/>
                                    {administrativeText(subject.location) && (
                                        <Typography variant="body2" data-testid="subject-administrative"
                                                    color={administrativeText(subject.location).determined ? 'text.primary' : 'warning.main'}
                                                    title={subject.location.administrative.provisional ? 'Limite administrative provizorii: lângă o limită, încadrarea poate fi neclară.' : undefined}>
                                            {administrativeText(subject.location).text}
                                        </Typography>
                                    )}
                                    <Chip size="small" variant="outlined" color={subject.location.confirmed ? 'success' : 'default'}
                                          label={locationConfirmationText(subject.location)}/>
                                </Stack>
                                <SubjectMap key={`${subject.location.lat},${subject.location.lng}`} location={subject.location}/>
                            </Stack>
                        )}
                        <SubjectMediaSection key={subject.id} subject={subject} canManage={canEditSubject(user, subject)}
                                             media={media} viewer={viewer} onViewer={setViewer}/>
                        {isAdmin && <AuthorPublishing key={subject.authorId} authorId={subject.authorId}/>}
                        {subject.type === 'PROPOSAL' && user?.id === subject.authorId && <SubjectPrivateNote key={`${subject.id}:${noteVersion}`} subjectId={subject.id}/>}
                        {canEditSubject(user, subject) && (
                            <div><Button variant="outlined" startIcon={<EditIcon/>} onClick={() => setEditing(true)}>Editează</Button></div>
                        )}
                        {/* Revizie nouă după editare → reîncărcăm voturile (pot deveni nereconfirmate). */}
                        <SubjectVotes key={`${subject.id}:${subject.revisionNumber ?? 1}:${subject.stage}:${subject.visibility}`} subjectId={subject.id}/>
                    </Stack>
                </Paper>
            )}
            {editing && subject && (
                <SubjectForm subject={subject} onClose={() => setEditing(false)} onSaved={(s) => { setNoteVersion((value) => value + 1); setSubject(s); setEditing(false); onUpdated?.(s); }}/>
            )}
        </Stack>
    );
}
