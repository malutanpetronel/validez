import {useEffect, useState} from 'react';
import {Box, Typography} from '@mui/material';
import {absoluteMediaUrl, fetchProtectedImage} from '../api/media';

/**
 * Imaginea unei fotografii: publică (subiect publicat, media aprobată) direct prin <img>, cu cache; altfel cu
 * autorizare, ca blob URL - <img> nu trimite tokenul JWT.
 * Variante (audit viteză 18.11.2026): `thumb` 400 px, `card` 1200 px (coperta din liste și din pagina subiectului),
 * `display` 1600 px doar în vizualizator (click pe poză). Cu `sizes` (doar publice): `srcset` thumb 400w / card 1200w.
 * Fără `cardUrl` (răspuns vechi), `card` cade pe `display`.
 */
export default function MediaImage({item, variant = 'thumb', isPublic, alt, sizes, sx, ...imgProps}) {
    const cardUrl = item.cardUrl ?? item.displayUrl;
    const path = variant === 'thumb' ? item.thumbUrl : variant === 'card' ? cardUrl : item.displayUrl;
    // Fără `cardUrl` (răspuns vechi) descriptorul rămâne lățimea reală a variantei de afișare.
    const srcSet = isPublic && sizes && variant !== 'display' && item.thumbUrl && cardUrl
        ? `${absoluteMediaUrl(item.thumbUrl)} 400w, ${absoluteMediaUrl(cardUrl)} ${item.cardUrl ? 1200 : 1600}w` : undefined;
    const [src, setSrc] = useState(isPublic ? absoluteMediaUrl(path) : null);
    const [failed, setFailed] = useState(false);
    useEffect(() => {
        setFailed(false);
        if (isPublic) { setSrc(absoluteMediaUrl(path)); return undefined; }
        setSrc(null);
        let active = true;
        let url = null;
        fetchProtectedImage(path).then((u) => { url = u; if (active) setSrc(u); else URL.revokeObjectURL(u); })
            .catch(() => { if (active) setFailed(true); });
        return () => { active = false; if (url) URL.revokeObjectURL(url); };
    }, [path, isPublic]);
    if (failed) return <Box sx={{...sx, display: 'grid', placeItems: 'center', bgcolor: 'action.hover'}}><Typography variant="caption">Indisponibilă</Typography></Box>;
    return src ? <Box component="img" src={src} srcSet={srcSet} sizes={srcSet ? sizes : undefined} alt={alt} loading="lazy" {...imgProps}
        sx={{objectFit: 'cover', display: 'block', ...sx}}/>
        : <Box sx={{...sx, bgcolor: 'action.hover'}}/>;
}
