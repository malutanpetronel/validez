import {Link as RouterLink, useLocation, useParams, useSearchParams} from 'react-router-dom';
import {Button, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import SubjectList from '../components/SubjectList';

export default function SubjectsPage() {
    const {nodeId} = useParams();
    const location = useLocation();
    const [params] = useSearchParams();
    const node = nodeId ? {id: nodeId, name: params.get('name') || 'Categoria selectată'} : null;
    return <Stack spacing={2}>
        <div><Button component={RouterLink} to="/arbore" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        <Typography variant="h5" component="h1">{node ? `Subiecte în „${node.name}”` : 'Subiecte recente'}</Typography>
        <SubjectList node={node} scope={node ? 'direct' : ''}/>
    </Stack>;
}
