import {Button, Stack, Typography} from '@mui/material';

/** Paginare „← Anterior · Pagina X din Y · Următor →” (ca PublicPagination din ArtaNFT); ascunsă la o singură pagină. */
export default function Paginare({pagina, totalPagini, onChange}) {
    if (totalPagini <= 1) return null;
    return <Stack component="nav" aria-label="Paginare" direction="row" useFlexGap spacing={1}
        sx={{mt: 1.5, flexWrap: 'wrap', justifyContent: 'center', alignItems: 'center'}}>
        <Button variant="outlined" size="small" disabled={pagina <= 1} onClick={() => onChange(pagina - 1)}>← Anterior</Button>
        <Typography variant="body2" color="text.secondary" aria-live="polite">Pagina {pagina} din {totalPagini}</Typography>
        <Button variant="outlined" size="small" disabled={pagina >= totalPagini} onClick={() => onChange(pagina + 1)}>Următor →</Button>
    </Stack>;
}
