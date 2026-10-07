import {useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate} from 'react-router-dom';
import {Alert, Box, Button, Paper, Stack, TextField, Typography} from '@mui/material';
import {useAuth} from '../auth/AuthContext';
import AltchaVerification from '../components/AltchaVerification';

export default function LoginPage() {
    const {user, login, logout} = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);
    const [altcha, setAltcha] = useState('');
    const [captchaVersion, setCaptchaVersion] = useState(0);
    const [needsConfirmation, setNeedsConfirmation] = useState(false);

    const after = location.state?.from || '/arbore';

    const submit = async (e) => {
        e.preventDefault();
        if (!altcha || busy) return;
        setNeedsConfirmation(false);
        setBusy(true);
        setError(null);
        try {
            await login(email, password, altcha);
            navigate(after, {replace: true});
        } catch (err) {
            setError(err.message);
            setNeedsConfirmation(Boolean(err.needsConfirmation));
            setAltcha('');
            setCaptchaVersion((value) => value + 1);
        } finally {
            setBusy(false);
        }
    };

    if (user) {
        return (
            <Paper sx={{p: 3, maxWidth: 420, mx: 'auto'}}>
                <Typography gutterBottom>Ești autentificat ca <strong>{user.displayName}</strong> ({user.email}).</Typography>
                <Stack direction="row" spacing={1}>
                    <Button variant="contained" component={RouterLink} to={after}>Continuă</Button>
                    <Button onClick={logout}>Ieși</Button>
                </Stack>
            </Paper>
        );
    }

    return (
        <Paper sx={{p: 3, maxWidth: 420, mx: 'auto'}}>
            <Typography variant="h5" component="h1" color="primary" gutterBottom>Intră</Typography>
            <Box component="form" onSubmit={submit} noValidate>
                <Stack spacing={2}>
                    {error && <Alert severity="error">{error}</Alert>}
                    <TextField label="Email" type="email" autoComplete="username" autoFocus required
                               value={email} onChange={(e) => setEmail(e.target.value)}/>
                    <TextField label="Parolă" type="password" autoComplete="current-password" required
                               value={password} onChange={(e) => setPassword(e.target.value)}/>
                    {needsConfirmation && <Button component={RouterLink} to="/inregistrare" state={{confirmEmail: email.trim(), from: after}}>Confirmă contul</Button>}
                    <AltchaVerification key={captchaVersion} onVerified={setAltcha}/>
                    <Button type="submit" variant="contained" disabled={busy || !email.trim() || !password || !altcha}>
                        {busy ? 'Se verifică…' : 'Intră'}
                    </Button>
                    <Button component={RouterLink} to="/inregistrare" state={{from: after}}>Creează un cont</Button>
                </Stack>
            </Box>
        </Paper>
    );
}
