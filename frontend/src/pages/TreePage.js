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
import {Link as RouterLink} from 'react-router-dom';
import {createNode, fetchChildren, renameNode} from '../api/tree';
import {useAuth} from '../auth/AuthContext';
import {appendChild, findNode, renameInTree, setChildren, toTreeNode} from '../tree/treeData';

const DIALOG_TITLES = {root: 'Rădăcină nouă', child: 'Nod copil nou', rename: 'Redenumește nodul'};

/**
 * Plan Step 1.5 (parțial): creare rădăcină/copil, redenumire, expandare cu încărcare pe ramuri.
 * Drag and drop, „Mută în…" și ordonarea vin odată cu operația de mutare (1.3).
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

    const selected = selectedKey ? findNode(treeData, selectedKey) : null;

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
                </Stack>
                <Typography variant="body2" color="text.secondary">
                    {selected ? <>Selectat: <strong>{selected.title}</strong> · dublu-click pentru redenumire</> : 'Selectează un nod pentru a-i adăuga un copil sau a-l redenumi.'}
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
                    <Box sx={{'& .rc-tree-node-content-wrapper': {cursor: 'pointer', py: 0.25}, '& .rc-tree-treenode': {py: 0.25}}}>
                        <Tree
                            treeData={treeData}
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
