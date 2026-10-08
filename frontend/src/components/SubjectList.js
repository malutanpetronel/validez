import {useCallback, useEffect, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {
    Alert, Box, Button, Chip, FormControl, InputLabel, List, ListItemButton, MenuItem,
    Pagination, Paper, Select, Skeleton, Stack, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import {useAuth} from '../auth/AuthContext';
import {fetchSubjects, PER_PAGE} from '../api/subjects';
import {formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from './SubjectForm';
import SubjectEstimate from './SubjectEstimate';

/**
 * Subiectele nodului selectat și ale întregului subarbore (decizie Step 2), cu nodul fiecăruia afișat;
 * fără nod selectat: cele mai noi subiecte. Filtre tip/stadiu, paginare 20/pagină.
 */
export default function SubjectList({node, scope = '', navigationState}) {
    const {user} = useAuth();
    const location = useLocation();
    const [filters, setFilters] = useState({type: '', stage: ''});
    const [page, setPage] = useState(1);
    const [data, setData] = useState({items: [], total: 0});
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [creating, setCreating] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        setError(null);
        return fetchSubjects({node: node?.id ?? null, ...filters, page, scope})
            .then(setData)
            .catch((e) => setError(e.message))
            .finally(() => setLoading(false));
    }, [node?.id, filters, page, scope]);

    useEffect(() => { load(); }, [load]);
    useEffect(() => { setPage(1); }, [node?.id, filters]);

    const setFilter = (k) => (e) => setFilters({...filters, [k]: e.target.value});
    const pages = Math.max(1, Math.ceil(data.total / PER_PAGE));

    return (
        <Paper sx={{p: {xs: 2, sm: 2.5}, minWidth: 0}}>
            <Stack spacing={1.5} sx={{mb: 2, pb: 2, borderBottom: 1, borderColor: 'divider'}}>
                <Box sx={{minWidth: 0}}>
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
                <RouterLink to="/login" state={{from: '/arbore'}}>Intră în cont</RouterLink> ca să adaugi un subiect.
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
                {[0, 1, 2].map((key) => <Skeleton key={key} variant="rounded" height={112}/>)}
            </Stack>}
            <List disablePadding aria-label="Lista subiectelor" aria-busy={loading}
                sx={{display: 'grid', gap: 1.25, opacity: loading ? 0.6 : 1}}>
                {data.items.map((s) => (
                    <Box component="li" key={s.id} sx={{listStyle: 'none', minWidth: 0}}>
                        <ListItemButton component={RouterLink} to={`/subiecte/${s.id}`} state={navigationState ?? location.state}
                            sx={{p: {xs: 1.5, sm: 2}, alignItems: 'flex-start', gap: 1, border: 1, borderColor: 'divider', borderRadius: 2,
                                '&:hover': {borderColor: 'primary.main', bgcolor: 'action.hover'},
                                '&.Mui-focusVisible': {outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2}}}>
                            <Stack spacing={1} sx={{flex: 1, minWidth: 0}}>
                                <Typography component="span" variant="subtitle1" sx={{fontWeight: 600, lineHeight: 1.4, overflowWrap: 'anywhere'}}>{s.title}</Typography>
                                <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center'}}>
                                    <Chip size="small" label={TYPE_LABELS[s.type] ?? s.type} variant="outlined" sx={{fontSize: '0.75rem'}}/>
                                    <Chip size="small" label={STAGE_LABELS[s.stage] ?? s.stage} color={STAGE_COLORS[s.stage] ?? 'default'} sx={{fontSize: '0.75rem'}}/>
                                    {s.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined"/>}
                            {s.visibility === 'PENDING' && <Chip size="small" label="În așteptarea aprobării" color="warning" variant="outlined"/>}
                                </Stack>
                                <SubjectEstimate subject={s} compact/>
                                <Typography component="span" variant="caption" color="text.secondary" sx={{overflowWrap: 'anywhere'}}>
                                    {scope === 'direct' ? '' : `în ${s.nodeName} · `}{s.authorName} · {formatDate(s.createdAt)}
                                </Typography>
                            </Stack>
                            <ChevronRightIcon fontSize="small" sx={{mt: 0.25, color: 'text.secondary', flexShrink: 0}}/>
                        </ListItemButton>
                    </Box>
                ))}
            </List>
            {pages > 1 && (
                <Stack sx={{alignItems: 'center', mt: 1.5}}>
                    <Pagination count={pages} page={page} onChange={(_, p) => setPage(p)} size="small" siblingCount={0}/>
                </Stack>
            )}

            {creating && node && (
                <SubjectForm node={node} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); setPage(1); load(); }}/>
            )}
        </Paper>
    );
}
