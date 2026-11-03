import {Box, Button, IconButton, Stack, TextField, Typography} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import ArrowDownwardIcon from '@mui/icons-material/ArrowDownward';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import {POLL_MAX_LABEL, POLL_MAX_OPTIONS, POLL_MIN_OPTIONS} from '../subjects/labels';

/**
 * Variantele unui sondaj (ADR-0013): adăugare, ștergere, sus/jos. Ordinea de aici e ordinea afișării la vot.
 * Pe telefon câmpul ia toată lățimea rămasă, butoanele rămân compacte — nimic nu iese din ecran pe orizontală.
 */
export default function PollOptionsEditor({options, onChange, disabled = false}) {
    const update = (index, value) => onChange(options.map((o, i) => (i === index ? value : o)));
    const remove = (index) => onChange(options.filter((_, i) => i !== index));
    const move = (index, delta) => {
        const next = [...options];
        [next[index], next[index + delta]] = [next[index + delta], next[index]];
        onChange(next);
    };

    return (
        <Stack spacing={1} component="fieldset" sx={{border: 0, p: 0, m: 0, minWidth: 0}}>
            <Typography component="legend" variant="subtitle1" sx={{fontWeight: 600}}>Variante de răspuns</Typography>
            <Typography variant="body2" color="text.secondary">
                Între {POLL_MIN_OPTIONS} și {POLL_MAX_OPTIONS} variante, fiecare de cel mult {POLL_MAX_LABEL} de caractere. Votanții aleg una singură.
            </Typography>
            {options.map((option, index) => (
                <Stack key={index} direction="row" spacing={0.5} sx={{alignItems: 'center', minWidth: 0}}>
                    <TextField size="small" fullWidth label={`Varianta ${index + 1}`} value={option} disabled={disabled}
                               onChange={(e) => update(index, e.target.value)} inputProps={{maxLength: POLL_MAX_LABEL}}
                               sx={{flex: 1, minWidth: 0}}/>
                    <Box sx={{display: 'flex', flexShrink: 0}}>
                        <IconButton size="small" aria-label={`Mută varianta ${index + 1} mai sus`} disabled={disabled || index === 0}
                                    onClick={() => move(index, -1)}><ArrowUpwardIcon fontSize="small"/></IconButton>
                        <IconButton size="small" aria-label={`Mută varianta ${index + 1} mai jos`} disabled={disabled || index === options.length - 1}
                                    onClick={() => move(index, 1)}><ArrowDownwardIcon fontSize="small"/></IconButton>
                        <IconButton size="small" aria-label={`Șterge varianta ${index + 1}`} disabled={disabled || options.length <= POLL_MIN_OPTIONS}
                                    onClick={() => remove(index)}><DeleteOutlineIcon fontSize="small"/></IconButton>
                    </Box>
                </Stack>
            ))}
            <div>
                <Button size="small" startIcon={<AddIcon/>} disabled={disabled || options.length >= POLL_MAX_OPTIONS}
                        onClick={() => onChange([...options, ''])}>
                    Adaugă o variantă
                </Button>
            </div>
        </Stack>
    );
}
