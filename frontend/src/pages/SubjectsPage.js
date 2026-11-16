import {Link as RouterLink, useLocation, useSearchParams} from 'react-router-dom';
import {Button, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import SubjectList from '../components/SubjectList';
import usePageMeta from '../seo/usePageMeta';
import {recentTitle, typeFromParam} from '../subjects/labels';

/** Subiectele recente; o categorie are propria pagină publică (/categorie/{cale}, CategoryPage). Step T: `?tip=` filtrează. */
export default function SubjectsPage() {
    const location = useLocation();
    const [searchParams, setSearchParams] = useSearchParams();
    const type = typeFromParam(searchParams.get('tip'));
    const title = recentTitle(type);
    usePageMeta({title, path: '/subiecte'});
    const changeType = (t) => setSearchParams(t ? {tip: t} : {}, {replace: true, state: location.state});
    return <Stack spacing={2}>
        <div><Button component={RouterLink} to={type ? `/?tip=${type}` : '/'} state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        <Typography variant="h5" component="h1">{title}</Typography>
        <SubjectList node={null} type={type} onTypeChange={changeType}/>
    </Stack>;
}
