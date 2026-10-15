import {useCallback, useEffect, useRef} from 'react';
import {Box, Button, Chip, Dialog, DialogActions, DialogContent, IconButton, Stack, Typography, useMediaQuery} from '@mui/material';
import {useTheme} from '@mui/material/styles';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import CloseIcon from '@mui/icons-material/Close';
import MediaImage from './MediaImage';
import {formatDate} from '../subjects/labels';

const arrow = (side) => ({
    position: 'absolute', top: '50%', [side]: 8, transform: 'translateY(-50%)', zIndex: 1,
    color: '#fff', bgcolor: 'rgba(0,0,0,0.45)', '&:hover': {bgcolor: 'rgba(0,0,0,0.7)'},
});

/**
 * Vizualizatorul fotografiilor unui subiect: anterioara/următoarea cu săgețile de pe imagine, cu ← → de la
 * tastatură sau cu swipe pe telefon; navigarea e circulară. `index` null = închis.
 */
export default function PhotoViewer({photos, index, onIndex, onClose, isPublic, title, actions}) {
    const theme = useTheme();
    const fullScreen = useMediaQuery(theme.breakpoints.down('sm'));
    const count = photos.length;
    const open = index !== null && index !== undefined && count > 0;
    const i = open ? Math.min(index, count - 1) : 0;
    const photo = open ? photos[i] : null;
    const go = useCallback((step) => onIndex((i + step + count) % count), [i, count, onIndex]);
    const touchX = useRef(null);

    useEffect(() => {
        if (!open || count < 2) return undefined;
        const onKey = (e) => {
            if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, count, go]);

    const onTouchEnd = (e) => {
        const start = touchX.current;
        touchX.current = null;
        const dx = start === null ? 0 : e.changedTouches[0].clientX - start;
        if (count > 1 && Math.abs(dx) > 50) go(dx < 0 ? 1 : -1);
    };

    return (
        <Dialog open={open} onClose={onClose} maxWidth="lg" fullScreen={fullScreen}
                PaperProps={{sx: {bgcolor: fullScreen ? '#000' : undefined}}}>
            {photo && <>
                <DialogContent sx={{p: 1, position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center'}}
                               onTouchStart={(e) => { touchX.current = e.touches[0].clientX; }} onTouchEnd={onTouchEnd}>
                    {fullScreen && <IconButton aria-label="Închide" onClick={onClose} sx={{...arrow('right'), top: 8, transform: 'none'}}><CloseIcon/></IconButton>}
                    {count > 1 && <IconButton aria-label="Fotografia anterioară" onClick={() => go(-1)} sx={arrow('left')}><ChevronLeftIcon fontSize="large"/></IconButton>}
                    <MediaImage key={photo.id} item={photo} variant="display" isPublic={isPublic(photo)} alt={`Fotografia ${i + 1} din ${count} la „${title}”`}
                                sx={{maxWidth: '100%', maxHeight: fullScreen ? 'calc(100vh - 120px)' : '78vh', width: 'auto', objectFit: 'contain', mx: 'auto'}}/>
                    {count > 1 && <IconButton aria-label="Fotografia următoare" onClick={() => go(1)} sx={arrow('right')}><ChevronRightIcon fontSize="large"/></IconButton>}
                </DialogContent>
                <DialogActions sx={{flexWrap: 'wrap', gap: 1}}>
                    <Stack direction="row" spacing={1} sx={{alignItems: 'center', flex: 1, minWidth: 0, pl: 1}}>
                        <Typography variant="caption" sx={{color: fullScreen ? '#fff' : 'text.secondary'}} aria-live="polite">
                            {count > 1 ? `${i + 1} / ${count} · ` : ''}Adăugată {formatDate(photo.createdAt)}
                        </Typography>
                        {photo.status === 'PENDING' && <Chip size="small" color="warning" label="În așteptare"/>}
                    </Stack>
                    <Box>{actions?.(photo)}</Box>
                    {!fullScreen && <Button onClick={onClose}>Închide</Button>}
                </DialogActions>
            </>}
        </Dialog>
    );
}
