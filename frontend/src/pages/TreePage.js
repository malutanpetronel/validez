import {useCallback, useEffect, useState} from 'react';
import Tree from 'rc-tree';
import 'rc-tree/assets/index.css';
import {
    Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle,
    Paper, Snackbar, Stack, TextField, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import SubdirectoryArrowRightIcon from '@mui/icons-material/SubdirectoryArrowRight';
import EditIcon from '@mui/icons-material/Edit';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import DriveFileMoveIcon from '@mui/icons-material/DriveFileMove';
import MoveDialog from '../components/MoveDialog';
import SubjectPage from './SubjectPage';
import SubjectList from '../components/SubjectList';
import SubjectForm from '../components/SubjectForm';
import {fetchSubjects} from '../api/subjects';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import FolderOutlinedIcon from '@mui/icons-material/FolderOutlined';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import ListAltOutlinedIcon from '@mui/icons-material/ListAltOutlined';
import {Link as RouterLink, useNavigate, useLocation} from 'react-router-dom';
import {createNode, fetchChildren, moveNode, renameNode} from '../api/tree';
import {useAuth} from '../auth/AuthContext';
import {
    appendChild, dropTarget, findNode, indexByKey, isNoopMove, renameInTree, replaceLevel, setChildren, toTreeNode,
} from '../tree/treeData';

const DIALOG_TITLES = {root: 'Rădăcină nouă', child: 'Nod copil nou', rename: 'Redenumește nodul'};

/**
 * Plan Step 1.5: creare rădăcină/copil, redenumire, expandare cu încărcare pe ramuri,
 * drag and drop pentru schimbarea părintelui și ordonare între frați.
 * Step 1.6: „Mută în…" și Sus/Jos (Alt+↑/↓) pentru telefon și tastatură - drag and drop-ul HTML5
 * nu merge la atingere. Toate mutările trec prin performMove: după răspuns (succes sau eroare)
 * se reîncarcă de pe server nivelurile atinse, deci UI-ul arată structura confirmată de server.
 * Navigare publică; acțiunile de modificare doar pentru administratori (1.4) - serverul verifică oricum.
 */
export default function TreePage() {
    const {isAdmin, user} = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [returnContext] = useState(() => location.state?.treeReturn);
    const [subjectBranches, setSubjectBranches] = useState({});
    const [selectedSubject, setSelectedSubject] = useState(null);
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

    const loadBranch = useCallback(async (key) => {
        const [children, subjects] = await Promise.all([fetchChildren(key), fetchSubjects({node: key, scope: 'direct'})]);
        setTreeData((t) => setChildren(t, key, children.map(toTreeNode)));
        setSubjectBranches((branches) => ({...branches, [key]: subjects}));
    }, []);

    // rc-tree cere loadData doar pentru nodurile fără children și fără isLeaf.
    const onLoadData = (node) => loadBranch(node.key).catch((err) => { showError(err); throw err; });

    /**
     * Reîncarcă de pe server nivelurile date (null = rădăcinile) - structura confirmată de server,
     * după o mutare reușită sau eșuată. `previous` păstrează ramurile încărcate ale nodului mutat.
     */
    const reloadLevels = async (parentKeys, previous) => {
        const unique = [...new Set(parentKeys)];
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
    const subjectsUrl = (node) => `/arbore/${node.key}/subiecte?name=${encodeURIComponent(node.title)}`;
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
        if (item?.kind === 'all') { navigate(subjectsUrl(item.category), {state: navigationState(item.category.key)}); return; }
        if (item?.kind === 'add') {
            setSelectedKey(item.category.key); setSelectedSubject(null); setCreatingSubject(true); return;
        }
        if (item?.kind === 'subject') { setSelectedSubject(item.subject.id); setSelectedKey(null); return; }
        setSelectedSubject(null);
        setSelectedKey(keys[0] ?? null);
        if (keys[0]) {
            setExpandedKeys((expanded) => [...new Set([...expanded, keys[0]])]);
            if (!subjectBranches[keys[0]]) loadBranch(keys[0]).catch(showError);
        }
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
                setTreeData((t) => renameInTree(t, selectedKey, updated.name));
            } else {
                const parentKey = dialog.mode === 'child' ? selectedKey : null;
                const created = toTreeNode(await createNode(name, parentKey));
                if (parentKey && !findNode(treeData, parentKey)?.children) {
                    // Ramura încă neîncărcată: o aducem de pe server (include și nodul nou).
                    await loadBranch(parentKey);
                } else {
                    setTreeData((t) => appendChild(t, parentKey, created));
                }
                if (parentKey) setExpandedKeys((keys) => (keys.includes(parentKey) ? keys : [...keys, parentKey]));
                setSelectedKey(created.key);
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
            <Typography variant="h5" component="h1" color="primary">Arbore</Typography>

            {isAdmin ? (
                <>
                <Stack direction="row" spacing={1} useFlexGap sx={{flexWrap: 'wrap'}}>
                    <Button variant="contained" startIcon={<AddIcon/>} onClick={() => openDialog('root')}>
                        Rădăcină nouă
                    </Button>
                    <Button variant="outlined" startIcon={<SubdirectoryArrowRightIcon/>} disabled={!selected} onClick={() => openDialog('child')}>
                        Adaugă copil
                    </Button>
                    <Button variant="outlined" startIcon={<EditIcon/>} disabled={!selected} onClick={() => openDialog('rename')}>
                        Redenumește
                    </Button>
                    <Button variant="outlined" startIcon={<DriveFileMoveIcon/>} disabled={!selected || moving} onClick={() => setMoveOpen(true)}>
                        Mută în…
                    </Button>
                    <Button variant="outlined" startIcon={<ArrowUpwardIcon/>} disabled={!canUp} onClick={() => moveBy(-1)}
                            aria-keyshortcuts="Alt+ArrowUp" title="Mută mai sus printre frați (Alt+↑)">
                        Sus
                    </Button>
                    <Button variant="outlined" startIcon={<ArrowDownwardIcon/>} disabled={!canDown} onClick={() => moveBy(1)}
                            aria-keyshortcuts="Alt+ArrowDown" title="Mută mai jos printre frați (Alt+↓)">
                        Jos
                    </Button>
                </Stack>
                <Typography variant="body2" color="text.secondary">
                    {selected ? <>Selectat: <strong>{selected.title}</strong> · dublu-click pentru redenumire</> : 'Selectează un nod pentru a-i adăuga un copil sau a-l redenumi.'}
                        {' '}Trage un nod peste altul ca să-l muți în el, sau între rânduri ca să-l ordonezi;
                        pe telefon sau de la tastatură: „Mută în…”, „Sus”/„Jos” (Alt+↑/↓).
                </Typography>
                </>
            ) : (
                <Alert severity="info">
                    Arborele se poate naviga liber. Modificarea lui e rezervată administratorilor —{' '}
                    <RouterLink to="/login" state={{from: '/arbore'}}>intră în cont</RouterLink>.
                </Alert>
            )}

            <Box sx={{display: 'grid', gap: 2, gridTemplateColumns: {xs: '1fr', md: 'minmax(0, 5fr) minmax(0, 7fr)'}, alignItems: 'start'}}>
                <Paper sx={{p: 2, overflowX: 'auto'}}>
                    {loading && <Typography color="text.secondary">Se încarcă…</Typography>}
                    {!loading && treeData.length === 0 && (
                        <Typography color="text.secondary">Arborele e gol. Creează prima rădăcină.</Typography>
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
                                     '& .MuiSvgIcon-root': {color: 'inherit'},
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
                                    <Typography component="span" variant="body2" sx={{color: node.kind === 'all' || node.kind === 'add' ? 'primary.main' : 'inherit', whiteSpace: 'normal'}}>{node.title}</Typography>
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
                {selectedSubject ? <SubjectPage key={selectedSubject} subjectId={selectedSubject} embedded onUpdated={(subject) => loadBranch(subject.nodeId).catch(showError)}/> :
                    selected ? <SubjectList key={selected.key} node={{id: selected.key, name: selected.title}}
                        scope="direct" navigationState={navigationState()}/> :
                    <Paper sx={{p: 3}}><Stack spacing={2}>
                        <Typography variant="h6">Explorează subiectele</Typography>
                        <Typography color="text.secondary">Selectează o categorie pentru a vedea toate subiectele ei.</Typography>
                        <Button component={RouterLink} to="/subiecte" state={navigationState()}>Subiecte recente</Button>
                    </Stack></Paper>}
            </Box>

            {creatingSubject && selected && <SubjectForm node={{id: selected.key, name: selected.title}}
                onClose={() => setCreatingSubject(false)} onSaved={(subject) => {
                    setCreatingSubject(false); setSelectedSubject(subject.id);
                    setExpandedKeys((keys) => [...new Set([...keys, selected.key])]);
                    loadBranch(selected.key).catch(showError);
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

            <Snackbar open={!!message} autoHideDuration={4000} onClose={() => setMessage(null)}
                      anchorOrigin={{vertical: 'bottom', horizontal: 'center'}}>
                {message ? <Alert severity={message.severity} onClose={() => setMessage(null)}>{message.text}</Alert> : undefined}
            </Snackbar>
        </Stack>
    );
}
