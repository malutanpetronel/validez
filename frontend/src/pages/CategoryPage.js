import {useEffect, useRef, useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate} from 'react-router-dom';
import {Alert, Button, Chip, Stack, Typography} from '@mui/material';
import ArrowBackIcon from '@mui/icons-material/ArrowBack';
import {fetchChildren, fetchNodeByPath} from '../api/tree';
import SubjectList from '../components/SubjectList';
import CategoryBreadcrumbs from '../components/CategoryBreadcrumbs';
import usePageMeta from '../seo/usePageMeta';

/**
 * Pagina publică a unei categorii (ADR-0008): breadcrumbs, subcategorii ca linkuri reale, subiectele categoriei.
 * O cale veche (categorie redenumită/mutată) e înlocuită cu cea canonică, fără intrare nouă în istoric.
 */
export default function CategoryPage({path}) {
    const location = useLocation();
    const navigate = useNavigate();
    const returnState = useRef(location.state);
    const [node, setNode] = useState(null);
    const [children, setChildren] = useState([]);
    const [error, setError] = useState(null);

    useEffect(() => {
        let active = true;
        setNode(null);
        setChildren([]);
        setError(null);
        fetchNodeByPath(path)
            .then(async (n) => {
                if (!active) return;
                if (n.slugPath !== path) {
                    navigate(`/categorie/${n.slugPath}`, {replace: true, state: returnState.current});
                    return;
                }
                setNode(n);
                const kids = n.hasChildren ? await fetchChildren(n.id) : [];
                if (active) setChildren(kids);
            })
            .catch((e) => { if (active) setError(e.status === 404 ? 'Categoria nu există.' : e.message); });
        return () => { active = false; };
    }, [path, navigate]);

    const crumbs = node?.breadcrumbs ?? [];
    usePageMeta(node
        ? {title: crumbs.slice(-2).reverse().map((c) => c.name).join(' · ') || node.name,
            description: `Subiecte din categoria ${crumbs.map((c) => c.name).join(' › ') || node.name} pe VALIDEZ.`,
            path: `/categorie/${node.slugPath}`}
        : {title: error ? 'Categorie negăsită' : null, noindex: Boolean(error)});

    return <Stack spacing={2}>
        <div><Button component={RouterLink} to="/" state={location.state} startIcon={<ArrowBackIcon/>}>Înapoi la arbore</Button></div>
        {error && <Alert severity="warning">{error}</Alert>}
        {!error && !node && <Typography color="text.secondary">Se încarcă…</Typography>}
        {node && <>
            <CategoryBreadcrumbs items={crumbs} currentIsLast/>
            <Typography variant="h5" component="h1">{node.name}</Typography>
            {children.length > 0 && <Stack component="nav" aria-label="Subcategorii" direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                {children.map((c) => <Chip key={c.id} component={RouterLink} to={`/categorie/${c.slugPath}`} clickable variant="outlined" label={c.name}/>)}
            </Stack>}
            <SubjectList node={{id: node.id, name: node.name}} scope="direct"/>
        </>}
    </Stack>;
}
