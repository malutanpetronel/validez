import {useEffect, useState} from 'react';
import {Box, Typography} from '@mui/material';
import {absoluteMediaUrl, fetchProtectedImage} from '../api/media';

/**
 * Imaginea unei fotografii: publică (subiect publicat, media aprobată) direct prin <img>, cu cache; altfel cu
 * autorizare, ca blob URL - <img> nu trimite tokenul JWT.
 */
export default function MediaImage({item, variant = 'thumb', isPublic, alt, sx, ...imgProps}) {
    const path = variant === 'thumb' ? item.thumbUrl : item.displayUrl;
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
    return src ? <Box component="img" src={src} alt={alt} loading="lazy" {...imgProps} sx={{objectFit: 'cover', display: 'block', ...sx}}/>
        : <Box sx={{...sx, bgcolor: 'action.hover'}}/>;
}
