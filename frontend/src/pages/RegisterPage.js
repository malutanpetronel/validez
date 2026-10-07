import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation, useNavigate} from 'react-router-dom';
import {Alert, Box, Button, Paper, Stack, TextField, Typography} from '@mui/material';
import AltchaVerification from '../components/AltchaVerification';
import {confirmRegistration, register, resendRegistrationCode} from '../api/registration';
import {useAuth} from '../auth/AuthContext';

export default function RegisterPage() {
    const location = useLocation();
    const navigate = useNavigate();
    const {user} = useAuth();
    const after = location.state?.from || '/arbore';
    const [step, setStep] = useState(location.state?.confirmEmail ? 'code' : 'form');
    const [email, setEmail] = useState(location.state?.confirmEmail || '');
    const [displayName, setDisplayName] = useState('');
    const [password, setPassword] = useState('');
    const [passwordAgain, setPasswordAgain] = useState('');
    const [code, setCode] = useState('');
    const [altcha, setAltcha] = useState('');
    const [captchaVersion, setCaptchaVersion] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [info, setInfo] = useState(null);
    const [cooldown, setCooldown] = useState(0);
    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = setTimeout(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);

    const resetVerification = () => { setAltcha(''); setCaptchaVersion((value) => value + 1); };
    const valid = /\S+@\S+\.\S+/.test(email.trim()) && displayName.trim().length >= 3
        && password.length >= 10 && password === passwordAgain && Boolean(altcha);
    const submit = async (event) => {
        event.preventDefault();
        if (busy || (step === 'form' ? !valid : !/^\d{6}$/.test(code))) return;
        setBusy(true);
        setError(null);
        try {
            if (step === 'form') {
                const result = await register({email: email.trim(), displayName: displayName.trim(), password, altcha});
                setStep('code');
                setPassword(''); setPasswordAgain('');
                setCooldown(result.retryAfter || 60);
                setInfo('Dacă adresa poate fi înregistrată, vei primi un cod pe email. Verifică și folderul spam. Codul este valabil 15 minute.');
                resetVerification();
            } else {
                await confirmRegistration(email.trim(), code);
                navigate(after, {replace: true});
            }
        } catch (err) {
            setError(err.message);
            if (step === 'form') resetVerification();
            if (err.retryAfter) setCooldown(err.retryAfter);
        } finally { setBusy(false); }
    };
    const resend = async () => {
        if (!altcha || cooldown || busy) return;
        setBusy(true); setError(null); setInfo(null);
        try {
            const result = await resendRegistrationCode(email.trim(), altcha);
            setCooldown(result.retryAfter || 60);
            setInfo('Dacă există un cont neconfirmat pentru această adresă, un cod nou a fost trimis. Verifică și folderul spam.');
        } catch (err) {
            setError(err.message);
            if (err.retryAfter) setCooldown(err.retryAfter);
        } finally { resetVerification(); setBusy(false); }
    };

    if (user) return <Paper sx={{p: 3, maxWidth: 460, mx: 'auto'}}><Stack spacing={2}>
        <Typography>Ești deja autentificat ca {user.displayName}.</Typography>
        <Button component={RouterLink} to={after}>Continuă</Button>
    </Stack></Paper>;

    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 460, mx: 'auto'}}>
        <Stack spacing={2}>
            <Typography variant="h5" component="h1" color="primary">{step === 'form' ? 'Creează un cont' : 'Confirmă adresa de email'}</Typography>
            {error && <Alert severity="error">{error}</Alert>}
            {info && <Alert severity="info">{info}</Alert>}
            <Box component="form" onSubmit={submit}><Stack spacing={2}>
                {step === 'form' ? <>
                    <TextField label="Nume afișat" autoComplete="name" required value={displayName} onChange={(event) => setDisplayName(event.target.value)}
                        inputProps={{maxLength: 120}} helperText="Minimum 3 caractere. Acest nume apare lângă subiectele tale."/>
                    <TextField label="Email" type="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} inputProps={{maxLength: 180}}/>
                    <TextField label="Parolă" type="password" autoComplete="new-password" required value={password} onChange={(event) => setPassword(event.target.value)}
                        inputProps={{maxLength: 72}} helperText="Minimum 10 caractere."/>
                    <TextField label="Repetă parola" type="password" autoComplete="new-password" required value={passwordAgain} onChange={(event) => setPasswordAgain(event.target.value)}
                        error={Boolean(passwordAgain && passwordAgain !== password)} helperText={passwordAgain && passwordAgain !== password ? 'Parolele nu coincid.' : ''}/>
                    <AltchaVerification key={captchaVersion} onVerified={setAltcha}/>
                    <Button type="submit" variant="contained" disabled={!valid || busy || cooldown > 0}>{busy ? 'Se trimite…' : 'Creează contul'}</Button>
                    {cooldown > 0 && <Typography variant="body2" color="text.secondary">Poți reîncerca peste {cooldown} secunde.</Typography>}
                </> : <>
                    <Typography sx={{overflowWrap: 'anywhere'}}>Introdu codul primit la <strong>{email}</strong>.</Typography>
                    <TextField label="Cod de confirmare" required value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))}
                        autoComplete="one-time-code" inputProps={{inputMode: 'numeric', maxLength: 6}} helperText="Codul are 6 cifre."/>
                    <Button type="submit" variant="contained" disabled={busy || !/^\d{6}$/.test(code)}>{busy ? 'Se verifică…' : 'Confirmă contul'}</Button>
                </>}
            </Stack></Box>
            {step === 'code' && <Stack spacing={1.5} sx={{pt: 2, borderTop: 1, borderColor: 'divider'}}>
                <Typography variant="body2" color="text.secondary">Nu ai primit codul? Finalizează verificarea de mai jos pentru a solicita unul nou.</Typography>
                <AltchaVerification key={`resend:${captchaVersion}`} onVerified={setAltcha}/>
                <Button onClick={resend} disabled={busy || cooldown > 0 || !altcha}>{cooldown > 0 ? `Retrimite codul (${cooldown}s)` : 'Retrimite codul'}</Button>
                <Button onClick={() => { setStep('form'); setCode(''); setError(null); setInfo(null); resetVerification(); }} disabled={busy}>Schimbă adresa de email</Button>
            </Stack>}
            <Button component={RouterLink} to="/login" state={{from: after}}>Ai deja un cont? Intră</Button>
        </Stack>
    </Paper>;
}
