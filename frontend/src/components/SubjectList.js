import {useCallback, useEffect, useRef, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {
    Alert, Avatar, Box, Button, Chip, FormControl, InputLabel, List, ListItemButton, MenuItem,
    Paper, Select, Skeleton, Stack, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import FolderOutlinedIcon from '@mui/icons-material/FolderOutlined';
import PersonOutlineIcon from '@mui/icons-material/PersonOutline';
import ScheduleIcon from '@mui/icons-material/Schedule';
import {alpha, useTheme} from '@mui/material/styles';
import {BRAND} from '../theme';
import {useAuth} from '../auth/AuthContext';
import {fetchSubjects, PER_PAGE} from '../api/subjects';
import {formatDate, recentTitle, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS, TYPE_PLURALS} from '../subjects/labels';
import {areaShort} from '../subjects/area';
import {CHIP, typeChip, typeTint} from '../subjects/tint';
import SubjectForm from './LazySubjectForm';
import SubjectEstimate from './SubjectEstimate';
import SubjectStats from './SubjectStats';
import MediaImage from './MediaImage';
import Paginare from './Paginare';
import {StageDot, TypeDot} from './ColorDot';

// Format 1.91:1 ca previzualizările Facebook / og:image; pe mobil fără colțuri (poza merge până la marginea ecranului).
const COVER = {width: '100%', aspectRatio: '1.91 / 1', borderRadius: {xs: 0, sm: 2}};
// Pe mobil cardul iese peste marginea Container-ului din Layout (16 px = spacing 2), ca feed-ul Facebook.
const BLEED = {xs: -2, sm: 0};
// ... iar textul revine la alinierea paginii.
const TEXT_PX = {xs: 2, sm: 0};
// Chenar neutru: gri pe luminos, albastrul brandului atenuat pe întunecat.
const CARD_BORDER = (t) => (t.palette.mode === 'dark' ? alpha(BRAND.albastru, 0.45) : t.palette.grey[300]);
// Tipul dă culoarea cardului și a chip-ului: subjects/tint.js (aceleași pe pagina subiectului).

/** Coperta subiectului (prima fotografie publicată), pe toată lățimea; fără fotografie nu se afișează nimic. */
function SubjectCover({subject, priority}) {
    if (!subject.coverThumbUrl) return null;
    // Subiect ascuns / în așteptare (îl văd doar autorul și adminii): thumb-ul cere autorizare.
    // Prima copertă e de obicei cea mai mare imagine vizibilă la deschidere (LCP): fără lazy, cu prioritate.
    return <MediaImage item={{thumbUrl: subject.coverThumbUrl, cardUrl: subject.coverCardUrl, displayUrl: subject.coverDisplayUrl}}
        isPublic={subject.visibility === 'PUBLISHED'} alt="" sizes="(min-width: 600px) 700px, 100vw" data-testid="subject-cover" sx={COVER}
        {...(priority ? {loading: 'eager', fetchpriority: 'high'} : {})}/>;
}

/**
 * Subiectele nodului selectat și ale întregului subarbore (decizie Step 2), cu nodul fiecăruia afișat;
 * fără nod selectat: cele mai noi subiecte. Filtre tip/stadiu, paginare `perPage` (implicit 20) pe pagină.
 * Card ca în feed-ul Facebook: titlul + categoria/autorul/data, poza pe toată lățimea, tipul și stadiul, rezumatul, linkul.
 * Pe mobil fără chenar, până la marginea ecranului, separate de o bandă; pe desktop card rotunjit, aceeași ordine.
 * Cu `page` + `onPageChange` pagina stă în adresă
 * (lângă arbore: `?pagina=`); după schimbarea paginii lista derulează la titlul ei când vin datele noi.
 * Cu `type` + `onTypeChange` tipul vine din afară (Step T: filtrul paginii principale / `?tip=`); fără, e local.
 * `area` (zona) și `q` (căutarea în titlu/descriere) vin din pagina principală; cu `q` titlul devine „Rezultate pentru …”.
 * `extraFilters` = câmpuri puse pe același rând după Tip și Stadiu (pe /subiecte: Zonă și Caută).
 */
export default function SubjectList({node, scope = '', navigationState, perPage = PER_PAGE, page: controlledPage, onPageChange, footer, showFilters = true,
    type: controlledType, onTypeChange, showHeading = true, area = '', q = '', extraFilters = null}) {
    const {user} = useAuth();
    const theme = useTheme();
    const location = useLocation();
    const [filters, setFilters] = useState({type: '', stage: ''});
    const [localPage, setLocalPage] = useState(1);
    const controlled = typeof onPageChange === 'function';
    const page = controlled ? controlledPage ?? 1 : localPage;
    const setPage = useCallback((p) => (controlled ? onPageChange(p) : setLocalPage(p)), [controlled, onPageChange]);
    const typeControlled = typeof onTypeChange === 'function';
    const activeType = typeControlled ? controlledType ?? '' : filters.type;
    const [data, setData] = useState({items: [], total: 0, page: null});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const headingRef = useRef(null);
    const pageToScroll = useRef(null);

    const load = useCallback(() => {
        setLoading(true);
        setError(null);
        return fetchSubjects({node: node?.id ?? null, type: activeType, stage: filters.stage, page, scope, perPage, area, q})
            .then((d) => setData({...d, page}))
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [node?.id, activeType, filters.stage, page, scope, perPage, area, q]);

    useEffect(() => { load(); }, [load]);
    // Filtrele/nodul schimbate pornesc de la prima pagină - nu și la montare (pagina din adresă rămâne), nici la
    // a doua rulare a efectului din StrictMode: comparăm cu valorile anterioare.
    const listKey = JSON.stringify([node?.id ?? null, activeType, filters.stage, area, q]);
    const previousKey = useRef(listKey);
    useEffect(() => {
        if (previousKey.current === listKey) return;
        previousKey.current = listKey;
        setPage(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [listKey]);

    const changePage = (p) => { pageToScroll.current = p; setPage(p); };
    // Ca la „Lucrări” în ArtaNFT: abia după ce sosesc subiectele paginii cerute (nu ale celei vechi, încă afișate).
    useEffect(() => {
        if (!loading && pageToScroll.current !== null && data.page === pageToScroll.current) {
            pageToScroll.current = null;
            headingRef.current?.scrollIntoView?.({block: 'start', behavior: 'smooth'});
        }
    }, [loading, data]);

    const setFilter = (k) => (e) => (k === 'type' && typeControlled ? onTypeChange(e.target.value) : setFilters({...filters, [k]: e.target.value}));
    const pages = Math.max(1, Math.ceil(data.total / perPage));

    return (
        <Paper elevation={0} sx={{p: 0, minWidth: 0, bgcolor: 'transparent'}}>
            <Stack spacing={1.5} sx={{mb: 2, pb: 2, borderBottom: 1, borderColor: 'divider'}}>
                <Box ref={headingRef} sx={{minWidth: 0, scrollMarginTop: 16}}>
                    {node && <Typography variant="overline" color="text.secondary">Subiectele categoriei</Typography>}
                    {showHeading && <Typography variant="h6" component="h2" sx={{fontWeight: 700, lineHeight: 1.35, overflowWrap: 'anywhere'}}>
                        {node ? node.name : q ? `Rezultate pentru „${q}”` : recentTitle(activeType)}
                    </Typography>}
                </Box>
                <Stack direction="row" spacing={1} sx={{alignItems: 'center', justifyContent: 'space-between'}}>
                    <Typography variant="body2" color="text.secondary" aria-live="polite">
                        {loading ? 'Se încarcă…' : error ? 'Lista nu este disponibilă' : `${data.total} ${data.total === 1 ? 'subiect' : 'subiecte'}`}
                    </Typography>
                    {user && node && <Button variant="outlined" size="small" startIcon={<AddIcon/>}
                        sx={{flexShrink: 0, minHeight: 40}} onClick={() => setCreating(true)}>Subiect nou</Button>}
                </Stack>
            </Stack>
            {node && scope !== 'direct' && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>Include subiectele din toate subnodurile.</Typography>}
            {!user && showFilters && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>
                <RouterLink to="/login" state={{from: '/'}}>Intră în cont</RouterLink> ca să adaugi un subiect.
            </Typography>}
            {user && !node && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>Selectează un nod din arbore ca să adaugi un subiect.</Typography>}

            {/* Un rând pe desktop; pe telefon fiecare filtru pe rândul lui. */}
            {showFilters && <Stack direction="row" spacing={1.5} useFlexGap sx={{mb: 2, flexWrap: 'wrap', rowGap: 1.5}}>
                <FormControl size="small" sx={{minWidth: 0, flex: {xs: '1 1 100%', sm: '1 1 150px'}}}>
                    <InputLabel id="flt-type">Tip</InputLabel>
                    <Select labelId="flt-type" label="Tip" value={activeType} onChange={setFilter('type')}>
                        <MenuItem value="">Toate</MenuItem>
                        {Object.entries(TYPE_LABELS).map(([k, l]) => <MenuItem key={k} value={k}><TypeDot type={k}/>{l}</MenuItem>)}
                    </Select>
                </FormControl>
                <FormControl size="small" sx={{minWidth: 0, flex: {xs: '1 1 100%', sm: '1 1 150px'}}}>
                    <InputLabel id="flt-stage">Stadiu</InputLabel>
                    <Select labelId="flt-stage" label="Stadiu" value={filters.stage} onChange={setFilter('stage')}>
                        <MenuItem value="">Toate</MenuItem>
                        {Object.entries(STAGE_LABELS).map(([k, l]) => <MenuItem key={k} value={k}><StageDot stage={k}/>{l}</MenuItem>)}
                    </Select>
                </FormControl>
                {extraFilters}
            </Stack>}

            {error && <Alert severity="error">{error}</Alert>}
            {!error && !loading && data.items.length === 0 && (activeType ? <Stack spacing={1} sx={{py: 2, alignItems: 'flex-start'}}>
                <Typography color="text.secondary">
                    Nu există {TYPE_PLURALS[activeType]?.toLowerCase()} {node ? 'în această categorie' : 'încă'}.
                </Typography>
                {typeControlled && <Button size="small" onClick={() => onTypeChange('')}>
                    {node ? 'Arată toate subiectele categoriei' : 'Arată toate subiectele'}
                </Button>}
            </Stack> : (
                <Typography color="text.secondary" sx={{py: 2}}>Niciun subiect{node ? ' aici încă' : ''}.</Typography>
            ))}
            {loading && data.items.length === 0 && <Stack spacing={1} aria-label="Se încarcă subiectele">
                {[0, 1, 2].map((key) => <Skeleton key={key} variant="rounded" height={150}/>)}
            </Stack>}
            <List disablePadding aria-label="Lista subiectelor" aria-busy={loading}
                sx={{display: 'grid', gap: {xs: 1, sm: 1.25}, opacity: loading ? 0.6 : 1}}>
                {data.items.map((s, index) => (
                    <Box component="li" key={s.id} sx={{listStyle: 'none', minWidth: 0, mx: BLEED, overflow: 'hidden',
                        // Cardul (chenar, nuanța tipului, hover) e pe <li>: antetul stă în afara linkului (va primi butoane).
                        // Grosime separată de culoare: `border` responsiv (shorthand) ar reseta culoarea la cea a textului.
                        borderStyle: 'solid', borderWidth: {xs: 0, sm: 1}, borderColor: CARD_BORDER, borderRadius: {xs: 0, sm: 2.5},
                        bgcolor: 'background.paper',
                        ...typeTint(s.type)(theme),
                        transition: 'border-color 160ms, background-color 160ms, transform 160ms',
                        '&:hover': {borderColor: 'primary.main', transform: {xs: 'none', sm: 'translateY(-2px)'}}}}>
                        {/* Antet ca la FB (decizie 20.11.2026): categoria e „numele”, dedesubt autor · dată · zonă.
                            În lista unei categorii (scope=direct) categoria e aceeași peste tot, deci antetul arată autorul. */}
                        <Stack direction="row" spacing={1.25} sx={{alignItems: 'center', px: 2, pt: {xs: 1.5, sm: 2}, minWidth: 0}}>
                            <Avatar aria-hidden sx={{width: 40, height: 40, bgcolor: (t) => alpha(t.palette.primary.main, 0.12), color: 'primary.main'}}>
                                {scope === 'direct' ? <PersonOutlineIcon/> : <FolderOutlinedIcon/>}
                            </Avatar>
                            <Box sx={{minWidth: 0, flex: 1}}>
                                <Typography component="div" variant="subtitle2" sx={{fontWeight: 700, lineHeight: 1.3, overflowWrap: 'anywhere'}}>
                                    {scope === 'direct' ? s.authorName : s.nodeName}
                                </Typography>
                                <Typography component="div" variant="caption" color="text.secondary" sx={{lineHeight: 1.35, overflowWrap: 'anywhere'}}>
                                    {scope === 'direct' ? '' : `${s.authorName} · `}{formatDate(s.createdAt)}
                                    {areaShort(s.area) && <> · <Box component="strong" sx={{fontWeight: 600, color: 'text.primary'}}>{areaShort(s.area)}</Box></>}
                                </Typography>
                            </Box>
                        </Stack>
                        <ListItemButton component={RouterLink} to={s.canonicalPath ?? `/subiecte/${s.id}`} state={navigationState ?? location.state}
                            sx={{flexDirection: 'column', alignItems: 'stretch', gap: 1.25, px: {xs: 0, sm: 2}, pt: 1.25, pb: {xs: 1.5, sm: 2},
                                '&:hover': {bgcolor: 'action.hover'},
                                '&.Mui-focusVisible': {outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2}}}>
                            <Stack spacing={0.25} sx={{px: TEXT_PX, minWidth: 0}}>
                                <Typography component="span" variant="subtitle1" sx={{fontWeight: 700, lineHeight: 1.35, overflowWrap: 'anywhere'}}>{s.title}</Typography>
                                <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap', pt: 0.75}}>
                                    <Chip size="small" label={TYPE_LABELS[s.type] ?? s.type} variant="outlined" sx={typeChip(s.type)}/>
                                    <Chip size="small" label={STAGE_LABELS[s.stage] ?? s.stage} color={STAGE_COLORS[s.stage] ?? 'default'} sx={CHIP}/>
                                    {s.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined" sx={CHIP}/>}
                                    {s.visibility === 'PENDING' && <Chip size="small" label="În așteptarea aprobării" color="warning" variant="outlined" sx={CHIP}/>}
                                </Stack>
                                {s.pollClosesAt && <Typography component="span" variant="caption" color="text.secondary"
                                    sx={{display: 'flex', alignItems: 'center', gap: 0.5, pt: 0.5, overflowWrap: 'anywhere'}}>
                                    <ScheduleIcon sx={{fontSize: '1rem'}} aria-hidden/>
                                    {s.pollClosed ? `Încheiat pe ${formatDate(s.pollClosesAt)}` : `Se încheie pe ${formatDate(s.pollClosesAt)}`}
                                </Typography>}
                            </Stack>
                            {/* Coperta rămâne decorativă pentru cititoarele de ecran; textul linkului conține informațiile utile. */}
                            <SubjectCover subject={s} priority={index === 0}/>
                            <Stack spacing={1} sx={{px: TEXT_PX, minWidth: 0}}>
                                {s.description && <Typography component="span" variant="body2" color="text.secondary"
                                    sx={{overflow: 'hidden', display: '-webkit-box', WebkitLineClamp: 3, WebkitBoxOrient: 'vertical', overflowWrap: 'anywhere'}}>
                                    {s.description}
                                </Typography>}
                                <SubjectEstimate subject={s} compact/>
                                <SubjectStats subject={s}/>
                                <Typography component="span" variant="body2" sx={{color: 'primary.main', fontWeight: 700, pt: 0.25}}>Vezi subiectul →</Typography>
                            </Stack>
                        </ListItemButton>
                    </Box>
                ))}
            </List>
            {!error && <Paginare pagina={page} totalPagini={pages} onChange={changePage}/>}
            {footer}

            {creating && node && (
                <SubjectForm node={node} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); setPage(1); load(); }}/>
            )}
        </Paper>
    );
}
