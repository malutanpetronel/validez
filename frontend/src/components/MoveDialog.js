import {useEffect, useState} from 'react';
import Tree from 'rc-tree';
import 'rc-tree/assets/index.css';
import {
    Alert, AppBar, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, FormControl, IconButton,
    InputLabel, MenuItem, Paper, Select, Toolbar, Typography, useMediaQuery,
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import {useTheme} from '@mui/material/styles';
import {fetchChildren} from '../api/tree';
import {findNode, setChildren, toTreeNode} from '../tree/treeData';

const ROOT = '__root__';

/**
 * Plan Step 1.6: „Mută în…" — alegerea părintelui și a poziției, fără drag and drop
 * (telefon, tastatură). Nodul mutat lipsește din lista de destinații, deci nici descendenții
 * lui nu pot fi aleși; serverul respinge oricum ciclurile.
 *
 * onConfirm({parentId, position}): position = indexul printre frații destinației, fără nodul mutat.
 *
 * Pe telefon (pe tot ecranul): tiparul Material pentru dialoguri full-screen — Renunță (✕) și Mută
 * în bara de sus. Jos, butoanele ajungeau sub bara browserului / bara de gesturi și erau departe
 * de alegerea făcută sus.
 */
export default function MoveDialog({node, onClose, onConfirm}) {
    const theme = useTheme();
    const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
    const [tree, setTree] = useState([]);
    const [expandedKeys, setExpandedKeys] = useState([]);
    const [dest, setDest] = useState(node.parentId ?? ROOT);
    const [siblings, setSiblings] = useState(null); // fratii destinatiei, fara nodul mutat
    const [position, setPosition] = useState('');
    const [error, setError] = useState(null);

    const without = (list) => list.filter((n) => n.id !== node.key);

    useEffect(() => {
        fetchChildren()
            .then((roots) => setTree(without(roots).map(toTreeNode)))
            .catch((e) => setError(e.message));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [node.key]);

    // Fratii destinatiei alese -> optiunile de pozitie (implicit: la sfarsit).
    useEffect(() => {
        let activ = true;
        setSiblings(null);
        fetchChildren(dest === ROOT ? null : dest)
            .then((list) => {
                if (!activ) return;
                const rest = without(list);
                setSiblings(rest);
                const current = list.findIndex((n) => n.id === node.key);
                setPosition(current >= 0 ? current : rest.length);
            })
            .catch((e) => activ && setError(e.message));
        return () => { activ = false; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [dest, node.key]);

    const loadData = async (n) => {
        const children = without(await fetchChildren(n.key));
        setTree((t) => setChildren(t, n.key, children.map(toTreeNode)));
    };

    const destTitle = dest === ROOT ? 'Nivelul principal' : findNode(tree, dest)?.title ?? '…';
    const canConfirm = siblings !== null && position !== '';
    const confirm = () => onConfirm({parentId: dest === ROOT ? null : dest, position});

    return (
        <Dialog open onClose={onClose} fullScreen={fullScreen} fullWidth maxWidth="sm">
            {fullScreen ? (
                <AppBar position="sticky" color="primary" elevation={1}>
                    <Toolbar sx={{gap: 1, px: 1}}>
                        <IconButton edge="start" color="inherit" onClick={onClose} aria-label="Renunță">
                            <CloseIcon/>
                        </IconButton>
                        <Typography variant="subtitle1" component="h2" noWrap sx={{flex: 1, minWidth: 0}}>
                            Mută „{node.title}” în…
                        </Typography>
                        <Button color="inherit" variant="outlined" onClick={confirm} disabled={!canConfirm}
                                sx={{flexShrink: 0, '&.Mui-disabled': {color: 'rgba(255,255,255,0.5)', borderColor: 'rgba(255,255,255,0.3)'}}}>
                            Mută
                        </Button>
                    </Toolbar>
                </AppBar>
            ) : (
                <DialogTitle>Mută „{node.title}” în…</DialogTitle>
            )}
            <DialogContent sx={{pt: fullScreen ? 2 : undefined}}>
                {error && <Alert severity="error" sx={{mb: 2}}>{error}</Alert>}
                <Typography variant="body2" color="text.secondary" gutterBottom>
                    Alege noul părinte (Enter sau atingere), apoi poziția printre frați.
                </Typography>
                <Paper variant="outlined" sx={{p: 1, maxHeight: fullScreen ? 'none' : 320, overflow: 'auto'}}>
                    <Button
                        size="small" fullWidth sx={{justifyContent: 'flex-start'}}
                        variant={dest === ROOT ? 'contained' : 'text'}
                        onClick={() => setDest(ROOT)}
                    >
                        Nivelul principal (rădăcină)
                    </Button>
                    <Box sx={{'& .rc-tree-node-content-wrapper': {cursor: 'pointer', py: 0.5}}}>
                        <Tree
                            treeData={tree}
                            loadData={loadData}
                            expandedKeys={expandedKeys}
                            onExpand={setExpandedKeys}
                            selectedKeys={dest === ROOT ? [] : [dest]}
                            onSelect={(keys) => keys[0] && setDest(keys[0])}
                        />
                    </Box>
                </Paper>
                <FormControl fullWidth sx={{mt: 2}} disabled={siblings === null}>
                    <InputLabel id="move-position-label">Poziția în „{destTitle}”</InputLabel>
                    <Select
                        labelId="move-position-label"
                        label={`Poziția în „${destTitle}”`}
                        value={siblings === null ? '' : position}
                        onChange={(e) => setPosition(e.target.value)}
                    >
                        {(siblings ?? []).map((s, i) => (
                            <MenuItem key={s.id} value={i}>{i === 0 ? `Primul (înainte de „${s.name}”)` : `După „${siblings[i - 1].name}”`}</MenuItem>
                        ))}
                        {siblings !== null && (
                            <MenuItem value={siblings.length}>
                                {siblings.length === 0 ? 'Singurul copil' : `La sfârșit (după „${siblings[siblings.length - 1].name}”)`}
                            </MenuItem>
                        )}
                    </Select>
                </FormControl>
            </DialogContent>
            {!fullScreen && (
                <DialogActions>
                    <Button onClick={onClose}>Renunță</Button>
                    <Button variant="contained" disabled={!canConfirm} onClick={confirm}>Mută</Button>
                </DialogActions>
            )}
        </Dialog>
    );
}
