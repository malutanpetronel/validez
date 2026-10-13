import {Link as RouterLink, useLocation} from 'react-router-dom';
import {Button, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import SubjectList from '../components/SubjectList';
import usePageMeta from '../seo/usePageMeta';

/** Subiectele recente; o categorie are propria pagină publică (/categorie/{cale}, CategoryPage). */
export default function SubjectsPage() {
    usePageMeta({title: 'Subiecte recente', path: '/subiecte'});
    const location = useLocation();
    return <Stack spacing={2}>
        <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        <Typography variant="h5" component="h1">Subiecte recente</Typography>
        <SubjectList node={null}/>
    </Stack>;
}
