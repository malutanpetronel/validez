import {useCallback, useEffect, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {
    Alert, Box, Button, Chip, FormControl, InputLabel, List, ListItemButton, ListItemText, MenuItem,
    Pagination, Paper, Select, Stack, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import {useAuth} from '../auth/AuthContext';
import {fetchSubjects, PER_PAGE} from '../api/subjects';
import {formatDate, STAGE_COLORS, STAGE_LABELS, TYPE_LABELS} from '../subjects/labels';
import SubjectForm from './SubjectForm';

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
        <Paper sx={{p: 2}}>
            <Stack direction="row" spacing={1} sx={{alignItems: 'center', mb: 1, flexWrap: 'wrap'}} useFlexGap>
                <Typography variant="h6" component="h2" sx={{flex: 1, minWidth: 0}} noWrap>
                    {node ? <>Subiecte în „{node.name}”</> : 'Subiecte recente'}
                </Typography>
                {user && node && (
                    <Button variant="contained" size="small" startIcon={<AddIcon/>} onClick={() => setCreating(true)}>Subiect nou</Button>
                )}
            </Stack>
            {node && scope !== 'direct' && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>Include subiectele din toate subnodurile.</Typography>}
            {!user && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>
                <RouterLink to="/login" state={{from: '/arbore'}}>Intră în cont</RouterLink> ca să adaugi un subiect.
            </Typography>}
            {user && !node && <Typography variant="body2" color="text.secondary" sx={{mb: 1.5}}>Selectează un nod din arbore ca să adaugi un subiect.</Typography>}

            <Stack direction={{xs: 'column', sm: 'row'}} spacing={1.5} sx={{mb: 1}}>
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
            <List dense disablePadding sx={{opacity: loading ? 0.6 : 1}}>
                {data.items.map((s) => (
                    <ListItemButton key={s.id} component={RouterLink} to={`/subiecte/${s.id}`} state={navigationState ?? location.state} divider sx={{px: 1, alignItems: 'flex-start'}}>
                        <ListItemText
                            primary={<Box component="span" sx={{fontWeight: 500}}>{s.title}</Box>}
                            secondaryTypographyProps={{component: 'div'}}
                            secondary={
                                <Stack direction="row" spacing={0.75} useFlexGap sx={{flexWrap: 'wrap', alignItems: 'center', mt: 0.5}}>
                                    <Chip size="small" label={TYPE_LABELS[s.type] ?? s.type} variant="outlined"/>
                                    <Chip size="small" label={STAGE_LABELS[s.stage] ?? s.stage} color={STAGE_COLORS[s.stage] ?? 'default'}/>
                                    {s.visibility === 'HIDDEN' && <Chip size="small" label="Ascuns" color="error" variant="outlined"/>}
                                    <span>în {s.nodeName} · {s.authorName} · {formatDate(s.createdAt)}</span>
                                </Stack>
                            }
                        />
                    </ListItemButton>
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
