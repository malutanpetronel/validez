import {useEffect, useState} from 'react';
import {Box, Typography} from '@mui/material';
import {absoluteMediaUrl, fetchProtectedImage} from '../api/media';

/**
 * Imaginea unei fotografii: publică (subiect publicat, media aprobată) direct prin <img>, cu cache; altfel cu
 * autorizare, ca blob URL - <img> nu trimite tokenul JWT.
 * Cu `sizes` + `item.displayUrl` (doar publice): `srcset` thumb 400w / afișare 1600w - browserul alege după ecran.
 */
export default function MediaImage({item, variant = 'thumb', isPublic, alt, sizes, sx, ...imgProps}) {
    const path = variant === 'thumb' ? item.thumbUrl : item.displayUrl;
    const srcSet = isPublic && sizes && variant === 'thumb' && item.displayUrl
        ? `${absoluteMediaUrl(item.thumbUrl)} 400w, ${absoluteMediaUrl(item.displayUrl)} 1600w` : undefined;
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
