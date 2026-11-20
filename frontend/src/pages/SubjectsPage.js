import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation, useSearchParams} from 'react-router-dom';
import {Button, IconButton, InputAdornment, Stack, TextField, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import SearchIcon from '@mui/icons-material/Search';
import ClearIcon from '@mui/icons-material/Clear';
import SubjectList from '../components/SubjectList';
import AreaFilter from '../components/AreaFilter';
import usePageMeta from '../seo/usePageMeta';
import {recentTitle, typeFromParam} from '../subjects/labels';

/**
 * Subiectele recente; o categorie are propria pagină publică (/categorie/{cale}, CategoryPage).
 * Filtre în adresă, ca pe `/`: `?tip=`, `?zona=` (RO / cod județ / cod localitate) și `?cauta=` (titlu/descriere).
 * Căutarea golește tipul și zona; alegerea unui tip sau a unei zone golește căutarea.
 */
export default function SubjectsPage() {
    const location = useLocation();
    const [searchParams, setSearchParams] = useSearchParams();
    const type = typeFromParam(searchParams.get('tip'));
    const area = searchParams.get('zona') ?? '';
    const query = (searchParams.get('cauta') ?? '').trim();
    const [searchInput, setSearchInput] = useState(query);
    const title = query ? `Rezultate pentru „${query}”` : recentTitle(type);
    usePageMeta({title, path: '/subiecte'});

    const update = (changes) => setSearchParams((prev) => {
        const next = new URLSearchParams(prev);
        Object.entries(changes).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
        return next;
    }, {replace: true, state: location.state});
    const changeType = (t) => { if (t) setSearchInput(''); update(t ? {tip: t, cauta: ''} : {tip: ''}); };
    const changeArea = (option) => { const code = option?.code ?? ''; if (code) setSearchInput(''); update(code ? {zona: code, cauta: ''} : {zona: ''}); };

    useEffect(() => {
        const text = searchInput.trim();
        const wanted = text.length >= 2 ? text : '';
        if (wanted === query) return undefined;
        const timer = setTimeout(() => update(wanted ? {cauta: wanted, tip: '', zona: ''} : {cauta: ''}), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchInput, query]);

    const back = new URLSearchParams();
    if (type) back.set('tip', type);
    if (area) back.set('zona', area);

    return <Stack spacing={2}>
        <div><Button component={RouterLink} to={back.toString() ? `/?${back}` : '/'} state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        <Typography variant="h5" component="h1" sx={{overflowWrap: 'anywhere'}}>{title}</Typography>
        <Stack direction={{xs: 'column', sm: 'row'}} spacing={1.5}>
            <TextField size="small" fullWidth value={searchInput} onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Caută în titlurile și descrierile subiectelor"
                inputProps={{'aria-label': 'Caută subiecte', maxLength: 100}}
                InputProps={{
                    startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small"/></InputAdornment>,
                    endAdornment: searchInput ? <InputAdornment position="end">
                        <IconButton size="small" aria-label="Golește căutarea" onClick={() => setSearchInput('')}><ClearIcon fontSize="small"/></IconButton>
                    </InputAdornment> : null,
                }}/>
            <AreaFilter value={area} onChange={changeArea} sx={{width: '100%', minWidth: 0, flex: {sm: '0 0 320px'}}}/>
        </Stack>
        <SubjectList node={null} type={type} onTypeChange={changeType} showHeading={false} area={area} q={query}/>
    </Stack>;
}
