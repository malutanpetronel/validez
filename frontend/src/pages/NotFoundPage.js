import {Link as RouterLink} from 'react-router-dom';
import {Button, Stack, Typography} from '@mui/material';
import usePageMeta from '../seo/usePageMeta';

/** Orice adresă necunoscută (ruta `*`), în interiorul Layout-ului: antetul aplicației rămâne. */
export default function NotFoundPage() {
    usePageMeta({title: 'Pagină negăsită', noindex: true});
    return <Stack spacing={2}>
        <Typography variant="h5" component="h1">Pagina nu există</Typography>
        <Typography color="text.secondary">Adresa nu corespunde niciunei pagini VALIDEZ. Poate linkul e greșit sau pagina a fost mutată.</Typography>
        <div><Button component={RouterLink} to="/" variant="contained">Înapoi la tematici</Button></div>
    </Stack>;
}
