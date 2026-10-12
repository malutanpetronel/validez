import {useLayoutEffect, useRef, useState} from 'react';
import {Link as RouterLink} from 'react-router-dom';
import {Box, Button, IconButton, Stack, Typography} from '@mui/material';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

const STORAGE_KEY = 'validez.cookieNotice.v1';
export default function CookieNotice() {
    const [open, setOpen] = useState(() => {
        try { return localStorage.getItem(STORAGE_KEY) !== '1'; } catch { return true; }
    });
    const banner = useRef(null);
    useLayoutEffect(() => {
        if (!open) return;
        const previous = document.body.style.paddingBottom;
        const measure = () => { document.body.style.paddingBottom = `${banner.current?.getBoundingClientRect().height || 0}px`; };
        measure();
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
        if (banner.current) observer?.observe(banner.current);
        window.addEventListener('resize', measure);
        return () => {
            observer?.disconnect();
            window.removeEventListener('resize', measure);
            document.body.style.paddingBottom = previous;
        };
    }, [open]);
    const dismiss = () => {
        try { localStorage.setItem(STORAGE_KEY, '1'); } catch { /* Informarea poate fi inchisa si fara stocare. */ }
        setOpen(false);
    };
    if (!open) return <IconButton aria-label="Informații despre cookie-uri" onClick={() => setOpen(true)}
        sx={{position: 'fixed', bottom: 16, right: 16, zIndex: 1200, bgcolor: 'background.paper', color: 'text.secondary', border: 1, borderColor: 'divider'}}>
        <InfoOutlinedIcon/>
    </IconButton>;
    return <Box ref={banner} role="region" aria-label="Informații despre cookie-uri"
        sx={{position: 'fixed', bottom: 0, left: 0, right: 0, zIndex: 1200, bgcolor: 'background.paper', color: 'text.primary',
            borderTop: 1, borderColor: 'divider', boxShadow: 3, px: {xs: 2, sm: 3}, py: 1.5,
            display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 1.5}}>
        <Typography variant="body2" sx={{flex: '1 1 280px'}}>Validez nu folosește cookie-uri de tracking sau analytics. Stocarea locală păstrează sesiunea, tema aleasă și închiderea acestei informări.</Typography>
        <Stack direction="row" spacing={1}>
            <Button component={RouterLink} to="/privacy" size="small">Confidențialitate</Button>
            <Button variant="outlined" size="small" onClick={dismiss}>Am înțeles</Button>
        </Stack>
    </Box>;
}
