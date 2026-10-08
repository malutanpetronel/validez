import {Alert, Button, Stack, Typography} from '@mui/material';
import {Link as RouterLink, Navigate} from 'react-router-dom';
import {useAuth} from '../auth/AuthContext';

export default function HelpPage() {
    const {user} = useAuth();
    if (!user) return <Navigate to="/login" replace/>;
    return <Stack spacing={2}>
        <Typography variant="h4" component="h1">Help — contribuțiile tale</Typography>
        <Typography>Poți crea Probleme, Propuneri și Petiții în categoriile existente din arbore.</Typography>
        <Alert severity="info">Primele trei subiecte trebuie aprobate de un administrator. Până atunci, doar tu și administratorii le vedeți, cu starea „În așteptarea aprobării”.</Alert>
        <Typography>După trei contribuții aprobate, subiectele următoare se publică direct. Administratorul poate retrage acest drept în caz de abuz; atunci contribuțiile tale au din nou nevoie de aprobare.</Typography>
        <Typography>Poți propune categorii noi, inclusiv rădăcini. Administratorul verifică dacă există deja o categorie potrivită și creează categoria dacă aprobă propunerea. Structura arborelui este administrată doar de administratori.</Typography>
        <div><Button component={RouterLink} to="/categorii-propuse" variant="outlined">Propune o categorie</Button></div>
        <div><Button component={RouterLink} to="/">Înapoi la arbore</Button></div>
    </Stack>;
}
