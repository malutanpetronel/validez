import {useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate} from 'react-router-dom';
import {Alert, Box, Button, Paper, Stack, TextField, Typography} from '@mui/material';
import {useAuth} from '../auth/AuthContext';

export default function LoginPage() {
    const {user, login, logout} = useAuth();
    const navigate = useNavigate();
    const location = useLocation();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(false);

    const after = location.state?.from || '/arbore';

    const submit = async (e) => {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await login(email, password);
            navigate(after, {replace: true});
        } catch (err) {
            setError(err.message);
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
                    <Button type="submit" variant="contained" disabled={busy || !email.trim() || !password}>
                        {busy ? 'Se verifică…' : 'Intră'}
                    </Button>
                </Stack>
            </Box>
        </Paper>
    );
}
