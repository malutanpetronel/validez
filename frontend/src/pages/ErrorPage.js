import {isRouteErrorResponse, Link as RouterLink, useRouteError} from 'react-router-dom';
import {Box, Button, Stack, Typography} from '@mui/material';
import usePageMeta from '../seo/usePageMeta';

/**
 * errorElement pe ruta principală: în locul ecranului tehnic al React Router (eroare neprevăzută într-o
 * pagină), un mesaj normal. Înlocuiește Layout-ul, deci are propriul link spre pagina principală.
 */
export default function ErrorPage() {
    const error = useRouteError();
    const notFound = isRouteErrorResponse(error) && error.status === 404;
    usePageMeta({title: notFound ? 'Pagină negăsită' : 'Eroare', noindex: true});
    if (!notFound) console.error(error); // eslint-disable-line no-console
    return <Box sx={{p: 3, maxWidth: 640, mx: 'auto'}}>
        <Stack spacing={2}>
            <Typography variant="h5" component="h1">{notFound ? 'Pagina nu există' : 'A apărut o eroare'}</Typography>
            <Typography color="text.secondary">{notFound
                ? 'Adresa nu corespunde niciunei pagini VALIDEZ.'
                : 'Pagina nu a putut fi afișată. Încearcă din nou; dacă problema se repetă, revino mai târziu.'}</Typography>
            <div><Button component={RouterLink} to="/" variant="contained">Înapoi la tematici</Button></div>
        </Stack>
    </Box>;
}
