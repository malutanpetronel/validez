import {Box} from '@mui/material';
import {STAGE_COLORS, TYPE_COLORS} from '../subjects/labels';

/** Bulină colorată în fața unei opțiuni din dropdown (decorativă: textul opțiunii rămâne eticheta). */
export default function ColorDot({color}) {
    if (!color) return null;
    return <Box component="span" aria-hidden
        sx={{display: 'inline-block', width: 10, height: 10, borderRadius: '50%', bgcolor: color, mr: 1, flexShrink: 0, verticalAlign: 'middle'}}/>;
}

export const TypeDot = ({type}) => <ColorDot color={TYPE_COLORS[type]}/>;
// STAGE_COLORS sunt culori din paletă MUI (info, warning...); „default” = gri.
export const StageDot = ({stage}) => {
    const c = STAGE_COLORS[stage];
    return <ColorDot color={!c ? null : c === 'default' ? 'grey.500' : `${c}.main`}/>;
};
