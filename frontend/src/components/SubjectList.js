import {useCallback, useEffect, useRef, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {
    Alert, Box, Button, Chip, FormControl, InputLabel, List, ListItemButton, MenuItem,
    Paper, Select, Skeleton, Stack, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import {useAuth} from '../auth/AuthContext';
import {fetchSubjects, PER_PAGE} from '../api/subjects';
import {formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from './SubjectForm';
import SubjectEstimate from './SubjectEstimate';
import MediaImage from './MediaImage';
import Paginare from './Paginare';

const COVER = {width: '100%', aspectRatio: '1 / 1', borderRadius: 1.5};
const CHIP = {maxWidth: '100%', fontSize: '0.7rem', '& .MuiChip-label': {px: 0.75, overflow: 'hidden', textOverflow: 'ellipsis'}};

/** Coperta subiectului (prima fotografie publicată); fără fotografie, un loc discret cu iconița de subiect. */
function SubjectCover({subject}) {
    if (!subject.coverThumbUrl) {
        return <Box aria-hidden="true" sx={{...COVER, display: 'grid', placeItems: 'center', bgcolor: 'action.hover', color: 'text.disabled'}}>
            <DescriptionOutlinedIcon/>
        </Box>;
    }
    // Subiect ascuns / în așteptare (îl văd doar autorul și adminii): thumb-ul cere autorizare.
    return <MediaImage item={{thumbUrl: subject.coverThumbUrl}} isPublic={subject.visibility === 'PUBLISHED'} alt=""
        data-testid="subject-cover" sx={COVER}/>;
}

/**
 * Subiectele nodului selectat și ale întregului subarbore (decizie Step 2), cu nodul fiecăruia afișat;
 * fără nod selectat: cele mai noi subiecte. Filtre tip/stadiu, paginare `perPage` (implicit 20) pe pagină.
 * Card: coperta cu tipul și stadiul dedesubt, titlul în dreapta. Cu `page` + `onPageChange` pagina stă în adresă
 * (lângă arbore: `?pagina=`); după schimbarea paginii lista derulează la titlul ei când vin datele noi.
 */
export default function SubjectList({node, scope = '', navigationState, perPage = PER_PAGE, page: controlledPage, onPageChange, footer}) {
    const {user} = useAuth();
    const location = useLocation();
    const [filters, setFilters] = useState({type: '', stage: ''});
    const [localPage, setLocalPage] = useState(1);
    const controlled = typeof onPageChange === 'function';
    const page = controlled ? controlledPage ?? 1 : localPage;
    const setPage = useCallback((p) => (controlled ? onPageChange(p) : setLocalPage(p)), [controlled, onPageChange]);
    const [data, setData] = useState({items: [], total: 0, page: null});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);
    const headingRef = useRef(null);
    const pageToScroll = useRef(null);

    const load = useCallback(() => {
        setLoading(true);
        setError(null);
        return fetchSubjects({node: node?.id ?? null, ...filters, page, scope, perPage})
            .then((d) => setData({...d, page}))
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [node?.id, filters, page, scope, perPage]);

    useEffect(() => { load(); }, [load]);
    // Filtrele/nodul schimbate pornesc de la prima pagină - nu și la montare (pagina din adresă rămâne), nici la
    // a doua rulare a efectului din StrictMode: comparăm cu valorile anterioare.
    const listKey = JSON.stringify([node?.id ?? null, filters]);
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

    const setFilter = (k) => (e) => setFilters({...filters, [k]: e.target.value});
    const pages = Math.max(1, Math.ceil(data.total / perPage));

    return (
        <Paper sx={{p: {xs: 2, sm: 2.5}, minWidth: 0}}>
            <Stack spacing={1.5} sx={{mb: 2, pb: 2, borderBottom: 1, borderColor: 'divider'}}>
                <Box ref={headingRef} sx={{minWidth: 0, scrollMarginTop: 16}}>
                    {node && <Typography variant="overline" color="text.secondary">Subiectele categoriei</Typography>}
                    <Typography variant="h6" component="h2" sx={{fontWeight: 700, lineHeight: 1.35, overflowWrap: 'anywhere'}}>
                        {node ? node.name : 'Subiecte recente'}
                    </Typography>
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
            {!user && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>
                <RouterLink to="/login" state={{from: '/'}}>Intră în cont</RouterLink> ca să adaugi un subiect.
            </Typography>}
            {user && !node && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>Selectează un nod din arbore ca să adaugi un subiect.</Typography>}

            <Stack direction={{xs: 'column', sm: 'row'}} spacing={1.5} sx={{mb: 2}}>
                <FormControl size="small" fullWidth>
                    <InputLabel id="flt-type">Tip</InputLabel>
                    <Select labelId="flt-type" label="Tip" value={filters.type} onChange={setFilter('type')}>
                        <MenuItem value="">Toate</MenuItem>
                        {Object.entries(TYPE_LABELS).map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                    </Select>
                </FormControl>
                <FormControl size="small" fullWidth>
                    <InputLabel id="flt-stage">Stadiu</InputLabel>
                    <Select labelId="flt-stage" label="Stadiu" value={filters.stage} onChange={setFilter('stage')}>
                        <MenuItem value="">Toate</MenuItem>
                        {Object.entries(STAGE_LABELS).map(([k, l]) => <MenuItem key={k} value={k}>{l}</MenuItem>)}
                    </Select>
                </FormControl>
            </Stack>

            {error && <Alert severity="error">{error}</Alert>}
            {!error && !loading && data.items.length === 0 && (
                <Typography color="text.secondary" sx={{py: 2}}>Niciun subiect{node ? ' aici încă' : ''}.</Typography>
            )}
            {loading && data.items.length === 0 && <Stack spacing={1} aria-label="Se încarcă subiectele">
                {[0, 1, 2].map((key) => <Skeleton key={key} variant="rounded" height={150}/>)}
            </Stack>}
            <List disablePadding aria-label="Lista subiectelor" aria-busy={loading}
                sx={{display: 'grid', gap: 1.25, opacity: loading ? 0.6 : 1}}>
                {data.items.map((s) => (
                    <Box component="li" key={s.id} sx={{listStyle: 'none', minWidth: 0}}>
                        <ListItemButton component={RouterLink} to={s.canonicalPath ?? `/subiecte/${s.id}`} state={navigationState ?? location.state}
                            sx={{p: {xs: 1.25, sm: 1.5}, alignItems: 'flex-start', gap: {xs: 1.25, sm: 1.5}, border: 1, borderColor: 'divider', borderRadius: 2,
                                '&:hover': {borderColor: 'primary.main', bgcolor: 'action.hover'},
                                '&.Mui-focusVisible': {outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2}}}>
                            <Stack spacing={1} sx={{flex: 1, minWidth: 0}}>
                                <Typography component="span" variant="subtitle1" sx={{fontWeight: 600, lineHeight: 1.4, overflowWrap: 'anywhere'}}>{s.title}</Typography>
                                {s.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined" sx={{alignSelf: 'flex-start', maxWidth: '100%'}}/>}
                                {s.visibility === 'PENDING' && <Chip size="small" label="În așteptarea aprobării" color="warning" variant="outlined" sx={{alignSelf: 'flex-start', maxWidth: '100%'}}/>}
                                <SubjectEstimate subject={s} compact/>
                                <Typography component="span" variant="caption" color="text.secondary" sx={{overflowWrap: 'anywhere'}}>
                                    {scope === 'direct' ? '' : `în ${s.nodeName} · `}{s.authorName} · {formatDate(s.createdAt)}
                                </Typography>
                            </Stack>
                            {/* În DOM după text (linkul se citește începând cu titlul), afișată în stânga prin `order`. */}
                            <Stack spacing={0.75} sx={{order: -1, width: {xs: 84, sm: 104}, flexShrink: 0, alignItems: 'flex-start'}}>
                                <SubjectCover subject={s}/>
                                <Chip size="small" label={TYPE_LABELS[s.type] ?? s.type} variant="outlined" sx={CHIP}/>
                                <Chip size="small" label={STAGE_LABELS[s.stage] ?? s.stage} color={STAGE_COLORS[s.stage] ?? 'default'} sx={CHIP}/>
                            </Stack>
                            <ChevronRightIcon fontSize="small" sx={{display: {xs: 'none', sm: 'block'}, mt: 0.25, color: 'text.secondary', flexShrink: 0}}/>
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
