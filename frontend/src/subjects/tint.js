import {alpha} from '@mui/material/styles';
import {TYPE_COLORS} from './labels';

/** Fundalul colorat după tipul subiectului (aceeași nuanță în cardurile din liste și pe pagina subiectului). */
export const typeTint = (type) => (t) => {
    const c = TYPE_COLORS[type];
    if (!c) return {};
    const a = alpha(c, t.palette.mode === 'dark' ? 0.1 : 0.07);
    return {backgroundImage: `linear-gradient(${a}, ${a})`, borderColor: alpha(c, t.palette.mode === 'dark' ? 0.5 : 0.45)};
};

/** Chip-urile din carduri și din pagina subiectului (tip colorat, stadiu, vizibilitate) — același aspect. */
export const CHIP = {maxWidth: '100%', fontSize: '0.72rem', '& .MuiChip-label': {px: 1, overflow: 'hidden', textOverflow: 'ellipsis'}};
export const typeChip = (type) => (t) => {
    const c = TYPE_COLORS[type];
    return c ? {...CHIP, borderColor: c, bgcolor: alpha(c, t.palette.mode === 'dark' ? 0.18 : 0.12)} : CHIP;
};
