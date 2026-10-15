import {useCallback, useEffect, useRef, useState} from 'react';
import Tree from 'rc-tree';
import 'rc-tree/assets/index.css';
import {
    Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle,
    IconButton, ListItemIcon, ListItemText, Menu, MenuItem, Paper, Snackbar, Stack, TextField, Tooltip, Typography,
    useMediaQuery, useTheme,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import MoreHorizIcon from '@mui/icons-material/MoreHoriz';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import SubdirectoryArrowRightIcon from '@mui/icons-material/SubdirectoryArrowRight';
import EditIcon from '@mui/icons-material/Edit';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import DriveFileMoveIcon from '@mui/icons-material/DriveFileMove';
import MoveDialog from '../components/MoveDialog';
import SubjectPage from './SubjectPage';
import TreeVoteSummary from '../components/TreeVoteSummary';
import SubjectList from '../components/SubjectList';
import SubjectForm from '../components/SubjectForm';
import {fetchSubjectBySlug, fetchSubjects} from '../api/subjects';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import FolderOutlinedIcon from '@mui/icons-material/FolderOutlined';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import ListAltOutlinedIcon from '@mui/icons-material/ListAltOutlined';
import {Link as RouterLink, useNavigate, useLocation} from 'react-router-dom';
import {createNode, fetchChildren, fetchNode, fetchNodeByPath, moveNode, renameNode} from '../api/tree';
import {useAuth} from '../auth/AuthContext';
import usePageMeta from '../seo/usePageMeta';
import {categoryPathOf, categoryUrl, parseTarget} from '../seo/paths';
import CategoryBreadcrumbs from '../components/CategoryBreadcrumbs';
import {
    ancestorsOf, appendChild, dropTarget, findBySlugPath, findNode, indexByKey, isNoopMove, rebaseSlugPaths, renameInTree,
    replaceLevel, setChildren, toTreeNode,
} from '../tree/treeData';

const DIALOG_TITLES = {root: 'Tematică nouă', child: 'Nod copil nou', rename: 'Redenumește nodul'};

/**
 * Plan Step 1.5: creare rădăcină/copil, redenumire, expandare cu încărcare pe ramuri,
 * drag and drop pentru schimbarea părintelui și ordonare între frați.
 * Step 1.6: „Mută în…" și Sus/Jos (Alt+↑/↓) pentru telefon și tastatură - drag and drop-ul HTML5
 * nu merge la atingere. Toate mutările trec prin performMove: după răspuns (succes sau eroare)
 * se reîncarcă de pe server nivelurile atinse, deci UI-ul arată structura confirmată de server.
 * Navigare publică; acțiunile de modificare doar pentru administratori (1.4) - serverul verifică oricum.
 *
 * ADR-0008: arborele e pagina pentru `/`, `/categorie/{cale}` și `/categorie/{cale}/subiect/{slug}`.
 * Selecția schimbă adresa (intrare nouă în istoric); adresa (încărcare, Back/Forward, linkuri) schimbă
 * selecția: ramurile lipsă se încarcă până la nod. Căile/slug-urile vechi devin canonice (replace).
 */
export default function TreePage() {
    const {isAdmin, user} = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [returnContext] = useState(() => location.state?.treeReturn);
    const [subjectBranches, setSubjectBranches] = useState({});
    const [selectedSubject, setSelectedSubject] = useState(null);
    // Contor pentru SubjectPage.focusTitle: crește la fiecare selectare a unui subiect după încărcarea paginii
    // (click în arbore sau în lista categoriei), nu la deschiderea directă dintr-un URL.
    const [focusSubject, setFocusSubject] = useState(0);
    // Subiect deschis din lista categoriei (link → adresa nouă): aceeași aducere în vedere. Prima intrare în aplicație
    // are location.key „default” - acolo (adresă deschisă direct sau reîncărcare) nu derulăm.
    useEffect(() => {
        if (selectedSubject && location.key !== 'default') setFocusSubject((n) => n + 1);
    }, [selectedSubject, location.key]);
    const [subjectNodeKey, setSubjectNodeKey] = useState(null);
    const [urlError, setUrlError] = useState(null);
    // Doar un 404 real scoate pagina din index; o eroare de retea / API indisponibil nu (ADR-0008).
    const [urlNotFound, setUrlNotFound] = useState(false);
    const [creatingSubject, setCreatingSubject] = useState(false);
    const [treeData, setTreeData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [expandedKeys, setExpandedKeys] = useState([]);
    const [selectedKey, setSelectedKey] = useState(null);
    const [dialog, setDialog] = useState(null); // {mode, value}
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState(null); // {severity, text}
    const [moving, setMoving] = useState(false);
    const [moveOpen, setMoveOpen] = useState(false);
    const [actionsAnchor, setActionsAnchor] = useState(null);
    const [helpOpen, setHelpOpen] = useState(false);
    const theme = useTheme();
    const mobile = useMediaQuery(theme.breakpoints.down('sm'));
    const runMenuAction = (action) => { setActionsAnchor(null); action(); };

    const showError = (err) => setMessage({severity: 'error', text: err.message || 'Eroare necunoscută'});

    useEffect(() => {
        let active = true;
        const restore = async () => {
            let tree = (await fetchChildren()).map(toTreeNode);
            const branches = {};
            const pending = new Set(returnContext?.expandedKeys ?? []);
            if (returnContext?.selectedKey) pending.add(returnContext.selectedKey);
            while (pending.size && active) {
                const currentTree = tree;
                const reachable = [...pending].filter((key) => findNode(currentTree, key));
                if (!reachable.length) break;
                const results = await Promise.all(reachable.map(async (key) => {
                    const [children, subjects] = await Promise.all([
                        fetchChildren(key), fetchSubjects({node: key, scope: 'direct'}),
                    ]);
                    return {key, children, subjects};
                }));
                for (const {key, children, subjects} of results) {
                    tree = setChildren(tree, key, children.map(toTreeNode));
                    branches[key] = subjects;
                    pending.delete(key);
                }
            }
            if (!active) return;
            setTreeData(tree);
            setSubjectBranches(branches);
            setExpandedKeys((returnContext?.expandedKeys ?? []).filter((key) => findNode(tree, key)));
            setSelectedKey(findNode(tree, returnContext?.selectedKey) ? returnContext.selectedKey : null);
            setSelectedSubject(returnContext?.selectedSubject ?? null);
        };
        restore().catch((err) => { if (active) showError(err); }).finally(() => { if (active) setLoading(false); });
        return () => { active = false; };
    }, [returnContext]);

    const loadBranch = useCallback(async (key, refreshCounts = false) => {
        const [children, subjects] = await Promise.all([fetchChildren(key), fetchSubjects({node: key, scope: 'direct'})]);
        setTreeData((t) => setChildren(t, key, children.map(toTreeNode)));
        setSubjectBranches((branches) => ({...branches, [key]: subjects}));
        if (refreshCounts) await reloadLevels([key], indexByKey(treeData));
    }, [treeData]);

    // rc-tree cere loadData doar pentru nodurile fără children și fără isLeaf.
    const onLoadData = (node) => loadBranch(node.key).catch((err) => { showError(err); throw err; });

    // ---- adresa <-> selectie (ADR-0008) ----
    const treeRef = useRef(treeData);
    treeRef.current = treeData;
    const branchesRef = useRef(subjectBranches);
    branchesRef.current = subjectBranches;
    /** Adresa care corespunde selectiei curente; o adresa egala nu se mai rezolva (vine dintr-un click). */
    const lastSyncedUrl = useRef(null);

    const goTo = (url, replace = false) => {
        lastSyncedUrl.current = url ?? location.pathname;
        if (url && location.pathname !== url) navigate(url, {replace});
    };

    /** Incarca, in ordine de la radacina, copiii si subiectele lipsa ale nodurilor date. */
    const ensureLoaded = async (keys) => {
        let tree = treeRef.current;
        const branches = {};
        for (const key of keys) {
            const node = findNode(tree, key);
            if (!node) break;
            const needChildren = !node.children && !node.isLeaf;
            const needSubjects = !branchesRef.current[key];
            if (!needChildren && !needSubjects) continue;
            const [children, subjects] = await Promise.all([
                needChildren ? fetchChildren(key) : null, needSubjects ? fetchSubjects({node: key, scope: 'direct'}) : null,
            ]);
            if (children) tree = setChildren(tree, key, children.map(toTreeNode));
            if (subjects) branches[key] = subjects;
        }
        treeRef.current = tree;
        setTreeData(tree);
        setSubjectBranches((b) => ({...b, ...branches}));
    };

    const clearSelection = () => {
        setSelectedKey(null);
        setSelectedSubject(null);
        setSubjectNodeKey(null);
    };

    useEffect(() => {
        if (loading) return undefined;
        const path = location.pathname;
        if (path === lastSyncedUrl.current) return undefined;
        const target = parseTarget(path);
        if (!target) {
            // `/` dupa o selectie (Back): golim selectia. La prima incarcare pastram revenirea din alta pagina.
            if (lastSyncedUrl.current !== null) clearSelection();
            lastSyncedUrl.current = path;
            setUrlError(null);
            return undefined;
        }
        if (target.invalid) {
            navigate(categoryUrl(target.categoryPath) ?? '/', {replace: true});
            return undefined;
        }
        lastSyncedUrl.current = path;
        let active = true;
        (async () => {
            const subject = target.subjectSlug ? await fetchSubjectBySlug(target.subjectSlug) : null;
            const categoryPath = categoryPathOf(subject?.canonicalPath) ?? target.categoryPath;
            const local = findBySlugPath(treeRef.current, categoryPath);
            let keys;
            let slugPath;
            if (local) {
                keys = ancestorsOf(treeRef.current, local.key).map((n) => n.key);
                slugPath = local.slugPath;
            } else {
                const node = await fetchNodeByPath(categoryPath);
                keys = (node.breadcrumbs ?? [{id: node.id}]).map((c) => c.id);
                slugPath = node.slugPath;
            }
            if (!active) return;
            await ensureLoaded(keys);
            if (!active) return;
            const nodeKey = keys[keys.length - 1];
            setExpandedKeys((expanded) => [...new Set([...expanded, ...keys])]);
            setUrlError(null);
            if (subject) {
                setSelectedKey(null);
                setSelectedSubject(subject.id);
                setSubjectNodeKey(nodeKey);
            } else {
                setSelectedSubject(null);
                setSubjectNodeKey(null);
                setSelectedKey(nodeKey);
            }
            goTo(subject?.canonicalPath ?? categoryUrl(slugPath), true);
        })().catch((err) => {
            if (!active) return;
            clearSelection();
            setUrlNotFound(err.status === 404);
            setUrlError(err.status === 404
                ? (target.subjectSlug ? 'Subiectul nu există sau nu e vizibil.' : 'Categoria nu există.')
                : err.message || 'Eroare necunoscută');
        });
        return () => { active = false; };
        // goTo/ensureLoaded/navigate citesc starea curenta prin ref-uri; efectul urmareste doar adresa.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [location.pathname, loading]);

    /**
     * Reîncarcă de pe server nivelurile date (null = rădăcinile) - structura confirmată de server,
     * după o mutare reușită sau eșuată. `previous` păstrează ramurile încărcate ale nodului mutat.
     */
    const reloadLevels = async (parentKeys, previous) => {
        const ancestors = [];
        for (const key of parentKeys) {
            let node = key ? previous.get(key) : null;
            while (node) {
                ancestors.push(node.parentId ?? null);
                node = node.parentId ? previous.get(node.parentId) : null;
            }
        }
        const unique = [...new Set([...parentKeys, ...ancestors])];
        const levels = await Promise.all(unique.map((k) => fetchChildren(k)));
        setTreeData((t) => unique.reduce((acc, k, i) => replaceLevel(acc, k, levels[i], previous), t));
    };

    const performMove = async (key, target) => {
        if (isNoopMove(treeData, key, target)) return;

        const oldParent = findNode(treeData, key)?.parentId ?? null;
        const previous = indexByKey(treeData);
        setMoving(true);
        try {
            await moveNode(key, target.parentId, target.position);
            if (target.parentId) setExpandedKeys((keys) => (keys.includes(target.parentId) ? keys : [...keys, target.parentId]));
            // Calea publica a nodului mutat (si a descendentilor incarcati) se schimba.
            const fresh = await fetchNode(key).catch(() => null);
            if (fresh?.slugPath) setTreeData((t) => rebaseSlugPaths(t, key, fresh.slugPath));
            setMessage({severity: 'success', text: 'Nod mutat.'});
        } catch (err) {
            showError(err);
        } finally {
            // Si la esec: arborele afisat = ce a confirmat serverul (poate s-a schimbat intre timp).
            await reloadLevels([oldParent, target.parentId], previous).catch(showError);
            setMoving(false);
        }
    };

    const onDrop = (info) => {
        if (info.node.kind || info.dragNode.kind) return;
        const dragKey = info.dragNode.key;
        const pos = info.node.pos.split('-');
        const relative = info.dropPosition - Number(pos[pos.length - 1]);
        return performMove(dragKey, dropTarget(treeData, {dragKey, dropKey: info.node.key, relative, dropExpanded: info.node.expanded}));
    };

    const selected = selectedKey ? findNode(treeData, selectedKey) : null;

    const navigationState = (categoryKey = selectedKey) => {
        const expanded = new Set(expandedKeys);
        let current = categoryKey ? findNode(treeData, categoryKey) : null;
        while (current) {
            expanded.add(current.key);
            current = current.parentId ? findNode(treeData, current.parentId) : null;
        }
        return {treeReturn: {expandedKeys: [...expanded], selectedKey: categoryKey,
            selectedSubject: categoryKey ? null : selectedSubject}};
    };
    // Adresa urmeaza calea selectiei si dupa redenumirea/mutarea ei (sau a unui stramos).
    const selectedUrl = selectedSubject ? null : categoryUrl(selected?.slugPath);
    useEffect(() => {
        if (selectedUrl && lastSyncedUrl.current?.startsWith('/categorie/') && lastSyncedUrl.current !== selectedUrl) goTo(selectedUrl, true);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selectedUrl]);

    const subjectNode = subjectNodeKey ? findNode(treeData, subjectNodeKey) : null;
    const crumbs = ancestorsOf(treeData, (selected ?? subjectNode)?.key)
        .filter((n) => n.slugPath).map((n) => ({name: n.title, slugPath: n.slugPath}));
    usePageMeta(urlError ? {title: urlNotFound ? 'Pagină negăsită' : null, noindex: urlNotFound}
        : selected ? {
            title: crumbs.slice(-2).reverse().map((c) => c.name).join(' · ') || selected.title,
            description: `Subiecte din categoria ${crumbs.map((c) => c.name).join(' › ') || selected.title} pe VALIDEZ.`,
            path: selectedUrl,
        } : {path: '/'}, !selectedSubject);

    const selectCategory = (key) => {
        setSelectedSubject(null);
        setSubjectNodeKey(null);
        setSelectedKey(key);
        setUrlError(null);
        setExpandedKeys((expanded) => [...new Set([...expanded, key])]);
        if (!subjectBranches[key]) loadBranch(key).catch(showError);
        goTo(categoryUrl(findNode(treeData, key)?.slugPath));
    };
    const selectSubject = (subject) => {
        setSelectedKey(null);
        setSelectedSubject(subject.id);
        setSubjectNodeKey(subject.nodeId ?? null);
        setUrlError(null);
        // Și la un nou click pe subiectul deja deschis: titlul revine în vedere.
        setFocusSubject((n) => n + 1);
        goTo(subject.canonicalPath ?? null);
    };
    const decorate = (nodes) => nodes.map((node) => {
        const branch = subjectBranches[node.key];
        const children = node.children ? decorate(node.children) : [];
        if (branch) {
            children.push(...branch.items.slice(0, 5).map((subject) => ({
                key: `subject:${subject.id}`, kind: 'subject', subject, isLeaf: true, title: subject.title,
            })));
            if (branch.total > 5) children.push({key: `all:${node.key}`, kind: 'all', category: node, isLeaf: true,
                title: `Vezi tot (${branch.total} subiecte)`});
            if (user) children.push({key: `add:${node.key}`, kind: 'add', category: node, isLeaf: true,
                title: 'Adaugă un subiect aici'});
        }
        return {...node, isLeaf: branch ? children.length === 0 : false, children: branch ? children : undefined};
    });
    const displayTree = decorate(treeData);
    const selectTreeItem = (keys, info) => {
        const item = info?.node;
        // „Vezi tot”: panoul categoriei are deja lista completa, paginata.
        if (item?.kind === 'all') { selectCategory(item.category.key); return; }
        if (item?.kind === 'add') { selectCategory(item.category.key); setCreatingSubject(true); return; }
        if (item?.kind === 'subject') { selectSubject(item.subject); return; }
        if (keys[0]) { selectCategory(keys[0]); return; }
        clearSelection();
        setUrlError(null);
        goTo('/');
    };

    // Ordonare intre frati fara drag (Sus/Jos, Alt+↑/↓). Pozitia API exclude nodul mutat:
    // sus = index-1, jos = index+1 (dupa fratele urmator).
    const siblingKeys = selected
        ? ((selected.parentId ?? null) === null ? treeData : findNode(treeData, selected.parentId)?.children ?? []).map((n) => n.key)
        : [];
    const selectedIndex = selected ? siblingKeys.indexOf(selected.key) : -1;
    const canUp = isAdmin && !moving && selectedIndex > 0;
    const canDown = isAdmin && !moving && selectedIndex >= 0 && selectedIndex < siblingKeys.length - 1;
    const moveBy = (delta) => {
        if (!selected || (delta < 0 ? !canUp : !canDown)) return;
        performMove(selected.key, {parentId: selected.parentId ?? null, position: selectedIndex + delta});
    };

    const onTreeKeyDown = (e) => {
        if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
        e.preventDefault();
        moveBy(e.key === 'ArrowUp' ? -1 : 1);
    };

    const openDialog = (mode) => setDialog({mode, value: mode === 'rename' ? selected?.title ?? '' : ''});

    const submit = async () => {
        const name = dialog.value.trim();
        if (!name) return;
        setSaving(true);
        try {
            if (dialog.mode === 'rename') {
                const updated = await renameNode(selectedKey, name);
                setTreeData((t) => {
                    const renamed = renameInTree(t, selectedKey, updated.name);
                    return updated.slugPath ? rebaseSlugPaths(renamed, selectedKey, updated.slugPath) : renamed;
                });
            } else {
                const parentKey = dialog.mode === 'child' ? selectedKey : null;
                const created = toTreeNode(await createNode(name, parentKey));
                if (parentKey && !findNode(treeData, parentKey)?.children) {
                    // Ramura încă neîncărcată: o aducem de pe server (include și nodul nou).
                    await loadBranch(parentKey);
                } else {
                    setTreeData((t) => appendChild(t, parentKey, created));
                }
                await reloadLevels([parentKey], indexByKey(treeData));
                if (parentKey) setExpandedKeys((keys) => (keys.includes(parentKey) ? keys : [...keys, parentKey]));
                selectCategory(created.key);
            }
            setDialog(null);
            setMessage({severity: 'success', text: dialog.mode === 'rename' ? 'Nod redenumit.' : 'Nod creat.'});
        } catch (err) {
            showError(err);
        } finally {
            setSaving(false);
        }
    };

    return (
        <Stack spacing={2}>
            <Typography variant="h5" component="h1" color="primary">Tematici</Typography>

            {!isAdmin && (
                <Alert severity="info">
                    Poți explora liber tematicile. Organizarea lor este gestionată de administratori.
                </Alert>
            )}

            <Box sx={{display: 'grid', gap: 2, gridTemplateColumns: {xs: '1fr', md: 'minmax(0, 5fr) minmax(0, 7fr)'}, alignItems: 'start'}}>
                <Paper sx={{p: 2, overflowX: 'auto'}}>
                    {isAdmin && <Box sx={{mb: 2, pb: 1.5, borderBottom: 1, borderColor: 'divider'}}>
                        <Stack direction="row" spacing={0.5} useFlexGap sx={{alignItems: 'center', flexWrap: 'wrap'}}>
                            <Button size="small" startIcon={<AddIcon/>} aria-label="Tematică nouă" onClick={() => openDialog('root')}>Tematică</Button>
                            {selected && <>
                                <Button size="small" startIcon={<SubdirectoryArrowRightIcon/>} aria-label="Adaugă copil" onClick={() => openDialog('child')}>Copil</Button>
                                {!mobile && <Tooltip title="Redenumește nodul">
                                    <IconButton aria-label="Redenumește" onClick={() => openDialog('rename')}><EditIcon fontSize="small"/></IconButton>
                                </Tooltip>}
                                <Tooltip title="Mai multe acțiuni">
                                    <IconButton aria-label="Acțiuni pentru nodul selectat" aria-controls={actionsAnchor ? 'tree-actions' : undefined}
                                        aria-haspopup="menu" aria-expanded={Boolean(actionsAnchor)} onClick={(event) => setActionsAnchor(event.currentTarget)}>
                                        <MoreHorizIcon/>
                                    </IconButton>
                                </Tooltip>
                            </>}
                            <Box sx={{flex: 1}}/>
                            <Tooltip title="Ajutor pentru arbore">
                                <IconButton aria-label="Ajutor pentru arbore" onClick={() => setHelpOpen(true)}><HelpOutlineIcon fontSize="small"/></IconButton>
                            </Tooltip>
                        </Stack>
                        <Typography variant="body2" color="text.secondary" sx={{mt: 0.5, overflowWrap: 'anywhere'}}>
                            {selected ? <>Selectat: <strong>{selected.title}</strong></> : 'Selectează un nod pentru a-l modifica.'}
                        </Typography>
                        <Menu id="tree-actions" anchorEl={actionsAnchor} open={Boolean(actionsAnchor) && Boolean(selected)} onClose={() => setActionsAnchor(null)}>
                            {mobile && <MenuItem onClick={() => runMenuAction(() => openDialog('rename'))}>
                                <ListItemIcon><EditIcon fontSize="small"/></ListItemIcon><ListItemText>Redenumește</ListItemText>
                            </MenuItem>}
                            <MenuItem disabled={moving} onClick={() => runMenuAction(() => setMoveOpen(true))}>
                                <ListItemIcon><DriveFileMoveIcon fontSize="small"/></ListItemIcon><ListItemText>Mută în…</ListItemText>
                            </MenuItem>
                            <MenuItem disabled={!canUp} aria-keyshortcuts="Alt+ArrowUp" onClick={() => runMenuAction(() => moveBy(-1))}>
                                <ListItemIcon><ArrowUpwardIcon fontSize="small"/></ListItemIcon><ListItemText>Mai sus</ListItemText>
                            </MenuItem>
                            <MenuItem disabled={!canDown} aria-keyshortcuts="Alt+ArrowDown" onClick={() => runMenuAction(() => moveBy(1))}>
                                <ListItemIcon><ArrowDownwardIcon fontSize="small"/></ListItemIcon><ListItemText>Mai jos</ListItemText>
                            </MenuItem>
                        </Menu>
                    </Box>}
                    {loading && <Typography color="text.secondary">Se încarcă…</Typography>}
                    {!loading && treeData.length === 0 && (
                        <Typography color="text.secondary">{isAdmin ? 'Nu există tematici. Creează prima tematică.' : 'Nu există tematici încă.'}</Typography>
                    )}
                    {treeData.length > 0 && (
                        <Box onKeyDown={isAdmin ? onTreeKeyDown : undefined}
                             sx={{
                                 '& .rc-tree-treenode': {display: 'flex', alignItems: 'center', py: 0.25},
                                 '& .rc-tree-indent': {height: 'auto', flexShrink: 0},
                                 '& .rc-tree-indent-unit': {width: 24},
                                 '& .rc-tree .rc-tree-treenode .rc-tree-switcher': {
                                     display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                                     width: 24, height: 36, flexShrink: 0, mr: 0,
                                     backgroundImage: 'none !important', color: 'text.secondary',
                                 },
                                 '& .rc-tree .rc-tree-treenode .rc-tree-node-content-wrapper': {
                                     display: 'flex', alignItems: 'center', flex: 1, minWidth: 0,
                                     cursor: 'pointer', height: 'auto', minHeight: 36,
                                     px: 1, borderRadius: 1, borderLeft: '3px solid transparent',
                                 },
                                 '& .rc-tree-node-content-wrapper:hover': {bgcolor: 'action.hover'},
                                 '& .rc-tree .rc-tree-treenode .rc-tree-node-content-wrapper.rc-tree-node-selected': {
                                     bgcolor: 'primary.main', color: 'primary.contrastText', opacity: 1,
                                     boxShadow: 'none', borderLeftColor: 'primary.contrastText',
                                     '& .MuiTypography-root': {color: 'inherit', fontWeight: 700},
                                     '& .MuiSvgIcon-root:not(.MuiRating-icon .MuiSvgIcon-root)': {color: 'inherit'},
                                     '& .MuiSvgIcon-root[data-hidden="true"]': {color: 'error.main'},
                                     '&:hover': {bgcolor: 'primary.dark'},
                                 },
                             }}>
                            <Tree
                                treeData={displayTree}
                                showIcon={false}
                                blockNode
                                switcherIcon={(node) => node.isLeaf ? null : node.expanded ? <ExpandMoreIcon fontSize="small"/> : <ChevronRightIcon fontSize="small"/>}
                                draggable={isAdmin && !moving ? {icon: false, nodeDraggable: (node) => !node.kind} : false}
                                onDrop={onDrop}
                                allowDrop={({dropNode, dragNode}) => !dropNode.kind && !dragNode.kind}
                                titleRender={(node) => <Stack component="span" direction="row" spacing={1} sx={{alignItems: 'center', minWidth: 0, '& .MuiSvgIcon-root': {flexShrink: 0}}}>
                                    {node.kind === 'subject' ? <DescriptionOutlinedIcon fontSize="small" color={node.subject.visibility === 'HIDDEN' ? 'error' : 'primary'} data-hidden={node.subject.visibility === 'HIDDEN' ? 'true' : undefined}/> : !node.kind ? <FolderOutlinedIcon fontSize="small" color="action"/> : node.kind === 'add' ? <AddIcon fontSize="small" color="primary"/> : <ListAltOutlinedIcon fontSize="small" color="primary"/>}
                                    <Box component="span" sx={{display: 'flex', flexDirection: 'column', gap: 0.25, minWidth: 0}}>
                                    <Typography component="span" variant="body2" sx={{color: node.kind === 'all' || node.kind === 'add' ? 'primary.main' : 'inherit', whiteSpace: 'normal'}}>{node.title}{!node.kind && node.subjectCount > 0 && <Tooltip title="Total subiecte în această ramură, inclusiv subnivelurile"><span> ({node.subjectCount})</span></Tooltip>}</Typography>
                                    {node.kind === 'subject' && <TreeVoteSummary subjectId={node.subject.id}/>}
                                    </Box>
                                </Stack>}
                                loadData={onLoadData}
                                expandedKeys={expandedKeys}
                                onExpand={setExpandedKeys}
                                selectedKeys={selectedSubject ? [`subject:${selectedSubject}`] : selectedKey ? [selectedKey] : []}
                                onSelect={selectTreeItem}
                                onDoubleClick={isAdmin ? (_, node) => { if (node.kind) return; setSelectedKey(node.key); setDialog({mode: 'rename', value: node.title}); } : undefined}
                            />
                        </Box>
                    )}
                </Paper>
                {urlError ? <Alert severity="warning">{urlError}</Alert> :
                    selectedSubject ? <Stack spacing={1.5} sx={{minWidth: 0}}>
                        <CategoryBreadcrumbs items={crumbs}/>
                        <SubjectPage key={selectedSubject} subjectId={selectedSubject} embedded meta focusTitle={focusSubject} onUpdated={(subject) => {
                            loadBranch(subject.nodeId, true).catch(showError);
                            if (subject.canonicalPath) goTo(subject.canonicalPath, true);
                        }}/>
                    </Stack> :
                    selected ? <Stack spacing={1.5} sx={{minWidth: 0}}>
                        <CategoryBreadcrumbs items={crumbs} currentIsLast/>
                        {(selected.children ?? []).some((c) => c.slugPath) && (
                            <Stack component="nav" aria-label="Subcategorii" direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                                {selected.children.filter((c) => c.slugPath).map((c) => (
                                    <Chip key={c.key} component={RouterLink} to={categoryUrl(c.slugPath)} clickable variant="outlined" label={c.title}/>
                                ))}
                            </Stack>
                        )}
                        <SubjectList key={selected.key} node={{id: selected.key, name: selected.title}}
                            scope="direct" navigationState={navigationState()}/>
                    </Stack> :
                    <Paper sx={{p: 3}}><Stack spacing={2}>
                        <Typography variant="h6">Explorează subiectele</Typography>
                        <Typography color="text.secondary">Selectează o categorie pentru a vedea toate subiectele ei.</Typography>
                        <Button component={RouterLink} to="/subiecte" state={navigationState()}>Subiecte recente</Button>
                    </Stack></Paper>}
            </Box>

            {creatingSubject && selected && <SubjectForm node={{id: selected.key, name: selected.title}}
                onClose={() => setCreatingSubject(false)} onSaved={(subject) => {
                    setCreatingSubject(false);
                    if (subject.visibility === 'PENDING') setMessage({severity: 'info', text: 'Subiectul a fost salvat și este în așteptarea aprobării.'});
                    setExpandedKeys((keys) => [...new Set([...keys, selected.key])]);
                    loadBranch(selected.key, true).catch(showError);
                    selectSubject(subject);
                }}/>}

            {moveOpen && selected && (
                <MoveDialog
                    node={selected}
                    onClose={() => setMoveOpen(false)}
                    onConfirm={(target) => {
                        setMoveOpen(false);
                        if (isNoopMove(treeData, selected.key, target)) {
                            setMessage({severity: 'info', text: 'Nodul este deja în acest loc.'});
                            return;
                        }
                        performMove(selected.key, target);
                    }}
                />
            )}

            <Dialog open={!!dialog} onClose={() => !saving && setDialog(null)} fullWidth maxWidth="xs">
                {dialog && (
                    <form onSubmit={(e) => { e.preventDefault(); submit(); }}>
                        <DialogTitle>{DIALOG_TITLES[dialog.mode]}</DialogTitle>
                        <DialogContent>
                            {dialog.mode === 'child' && (
                                <Typography variant="body2" color="text.secondary" sx={{mb: 1}}>
                                    Sub: <strong>{selected?.title}</strong>
                                </Typography>
                            )}
                            <TextField
                                autoFocus fullWidth margin="dense" label="Nume"
                                value={dialog.value}
                                inputProps={{maxLength: 255}}
                                onChange={(e) => setDialog({...dialog, value: e.target.value})}
                            />
                        </DialogContent>
                        <DialogActions>
                            <Button onClick={() => setDialog(null)} disabled={saving}>Renunță</Button>
                            <Button type="submit" variant="contained" disabled={saving || !dialog.value.trim()}>Salvează</Button>
                        </DialogActions>
                    </form>
                )}
            </Dialog>

            <Dialog open={helpOpen} onClose={() => setHelpOpen(false)} maxWidth="xs" fullWidth>
                <DialogTitle>Lucrul cu arborele</DialogTitle>
                <DialogContent><Stack spacing={2}>
                    <Typography>Selectează o categorie pentru a vedea subiectele ei. Selectează un subiect pentru detalii.</Typography>
                    <Typography>„Tematică” adaugă o categorie la nivelul principal, iar „Copil” adaugă o categorie sub nodul selectat.</Typography>
                    <Typography>Pe calculator, folosește creionul sau dublu-click pentru redenumire. Poți trage un nod peste altul pentru a-l muta în el, sau între rânduri pentru a-l ordona.</Typography>
                    <Typography>Din meniul „⋯” poți muta nodul în altă categorie sau îl poți ordona cu „Mai sus” și „Mai jos”. Pe telefon, aici găsești și „Redenumește”.</Typography>
                    <Typography>De la tastatură, Alt+↑ și Alt+↓ mută nodul selectat printre frați când arborele are focus.</Typography>
                </Stack></DialogContent>
                <DialogActions><Button onClick={() => setHelpOpen(false)}>Închide</Button></DialogActions>
            </Dialog>

            <Snackbar open={!!message} autoHideDuration={4000} onClose={() => setMessage(null)}
                      anchorOrigin={{vertical: 'bottom', horizontal: 'center'}}>
                {message ? <Alert severity={message.severity} onClose={() => setMessage(null)}>{message.text}</Alert> : undefined}
            </Snackbar>
        </Stack>
    );
}
