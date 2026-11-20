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
 * Toate se combină (ex. București + „canalizare”); pe desktop stau pe un singur rând cu Tip și Stadiu.
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
    const changeType = (t) => update({tip: t});
    const changeArea = (option) => update({zona: option?.code ?? ''});

    useEffect(() => {
        const text = searchInput.trim();
        const wanted = text.length >= 2 ? text : '';
        if (wanted === query) return undefined;
        const timer = setTimeout(() => update({cauta: wanted}), 350);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchInput, query]);

    const back = new URLSearchParams();
    if (type) back.set('tip', type);
    if (area) back.set('zona', area);

    return <Stack spacing={2}>
        <div><Button component={RouterLink} to={back.toString() ? `/?${back}` : '/'} state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        <Typography variant="h5" component="h1" sx={{overflowWrap: 'anywhere'}}>{title}</Typography>
        <SubjectList node={null} type={type} onTypeChange={changeType} showHeading={false} area={area} q={query} extraFilters={<>
            <AreaFilter value={area} onChange={changeArea} sx={{minWidth: 0, flex: {xs: '1 1 100%', sm: '1 1 200px'}}}/>
            <TextField size="small" value={searchInput} onChange={(e) => setSearchInput(e.target.value)}
                placeholder="Caută în titluri și descrieri"
                inputProps={{'aria-label': 'Caută subiecte', maxLength: 100}}
                sx={{minWidth: 0, flex: {xs: '1 1 100%', sm: '2 1 220px'}}}
                InputProps={{
                    startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small"/></InputAdornment>,
                    endAdornment: searchInput ? <InputAdornment position="end">
                        <IconButton size="small" aria-label="Golește căutarea" onClick={() => setSearchInput('')}><ClearIcon fontSize="small"/></IconButton>
                    </InputAdornment> : null,
                }}/>
        </>}/>
    </Stack>;
}
