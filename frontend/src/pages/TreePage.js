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
import {Link as RouterLink} from 'react-router-dom';
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
    const {isAdmin} = useAuth();
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
        fetchChildren()
            .then((roots) => setTreeData(roots.map(toTreeNode)))
            .catch(showError)
            .finally(() => setLoading(false));
    }, []);

    const loadBranch = useCallback(async (key) => {
        const children = await fetchChildren(key);
        setTreeData((t) => setChildren(t, key, children.map(toTreeNode)));
    }, []);

    // rc-tree cere loadData doar pentru nodurile fără children și fără isLeaf.
    const onLoadData = (node) => loadBranch(node.key).catch(showError);

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
        const dragKey = info.dragNode.key;
        const pos = info.node.pos.split('-');
        const relative = info.dropPosition - Number(pos[pos.length - 1]);
        return performMove(dragKey, dropTarget(treeData, {dragKey, dropKey: info.node.key, relative, dropExpanded: info.node.expanded}));
    };

    const selected = selectedKey ? findNode(treeData, selectedKey) : null;

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

            <Paper sx={{p: 2, overflowX: 'auto'}}>
                {loading && <Typography color="text.secondary">Se încarcă…</Typography>}
                {!loading && treeData.length === 0 && (
                    <Typography color="text.secondary">Arborele e gol. Creează prima rădăcină.</Typography>
                )}
                {treeData.length > 0 && (
                    <Box onKeyDown={isAdmin ? onTreeKeyDown : undefined}
                         sx={{'& .rc-tree-node-content-wrapper': {cursor: 'pointer', py: 0.25}, '& .rc-tree-treenode': {py: 0.25}}}>
                        <Tree
                            treeData={treeData}
                            draggable={isAdmin && !moving}
                            onDrop={onDrop}
                            loadData={onLoadData}
                            expandedKeys={expandedKeys}
                            onExpand={setExpandedKeys}
                            selectedKeys={selectedKey ? [selectedKey] : []}
                            onSelect={(keys) => setSelectedKey(keys[0] ?? null)}
                            onDoubleClick={isAdmin ? (_, node) => { setSelectedKey(node.key); setDialog({mode: 'rename', value: node.title}); } : undefined}
                        />
                    </Box>
                )}
            </Paper>

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
