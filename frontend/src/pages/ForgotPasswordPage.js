import {useEffect, useState} from 'react';
import {Link as RouterLink, useLocation} from 'react-router-dom';
import {Alert, Box, Button, Paper, Stack, TextField, Typography} from '@mui/material';
import AltchaVerification from '../components/AltchaVerification';
import {requestPasswordReset, confirmPasswordReset} from '../api/passwordReset';
import {clearSession} from '../auth/session';

export default function ForgotPasswordPage() {
    const location = useLocation();
    const [step, setStep] = useState('email');
    const [email, setEmail] = useState(location.state?.email || '');
    const [code, setCode] = useState('');
    const [password, setPassword] = useState('');
    const [passwordAgain, setPasswordAgain] = useState('');
    const [altcha, setAltcha] = useState('');
    const [captchaVersion, setCaptchaVersion] = useState(0);
    const [cooldown, setCooldown] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [info, setInfo] = useState(null);
    const after = location.state?.from || '/';
    useEffect(() => {
        if (cooldown <= 0) return;
        const timer = setTimeout(() => setCooldown((value) => Math.max(0, value - 1)), 1000);
        return () => clearTimeout(timer);
    }, [cooldown]);
    const resetVerification = () => { setAltcha(''); setCaptchaVersion((value) => value + 1); };
    const validEmail = /\S+@\S+\.\S+/.test(email.trim());
    const passwordBytes = new TextEncoder().encode(password).length;
    const passwordError = passwordBytes > 72 ? 'Parola depășește limita de 72 de octeți. Folosește o parolă mai scurtă.' : '';
    const validPassword = Array.from(password).length >= 10 && !passwordError && password === passwordAgain;
    const sendCode = async (event) => {
        event?.preventDefault();
        if (busy || cooldown > 0 || !validEmail || !altcha) return;
        setBusy(true); setError(null); setInfo(null);
        try {
            const result = await requestPasswordReset(email.trim(), altcha);
            setStep('code'); setCode(''); setCooldown(result.retryAfter || 60);
            setInfo('Dacă există un cont confirmat pentru această adresă, vei primi un cod pe email. Verifică și folderul spam. Codul este valabil 15 minute.');
        } catch (err) {
            setError(err.message);
            if (err.retryAfter) setCooldown(err.retryAfter);
        } finally { resetVerification(); setBusy(false); }
    };
    const reset = async (event) => {
        event.preventDefault();
        if (busy || !/^\d{6}$/.test(code) || !validPassword) return;
        setBusy(true); setError(null);
        try {
            await confirmPasswordReset(email.trim(), code, password);
            clearSession(); setPassword(''); setPasswordAgain(''); setCode(''); setInfo(null); setStep('done');
        } catch (err) {
            setError(err.message);
            if (err.retryAfter) setCooldown(err.retryAfter);
        } finally { setBusy(false); }
    };
    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 460, mx: 'auto'}}><Stack spacing={2}>
        <Typography variant="h5" component="h1" color="primary">Am uitat parola</Typography>
        {error && <Alert severity="error">{error}</Alert>}
        {info && <Alert severity="info">{info}</Alert>}
        {step === 'done' ? <>
            <Alert severity="success">Parola a fost schimbată. Autentifică-te cu noua parolă. Sesiunile anterioare au fost închise.</Alert>
            <Button component={RouterLink} to="/login" state={{email: email.trim(), from: after}} variant="contained">Intră cu noua parolă</Button>
        </> : step === 'email' ? <Box component="form" onSubmit={sendCode}><Stack spacing={2}>
            <Typography>Introdu adresa de email a contului pentru a solicita un cod de resetare.</Typography>
            <TextField label="Email" type="email" autoComplete="email" required value={email} onChange={(event) => setEmail(event.target.value)} inputProps={{maxLength: 180}} disabled={busy}/>
            <AltchaVerification key={captchaVersion} onVerified={setAltcha}/>
            <Button type="submit" variant="contained" disabled={busy || cooldown > 0 || !validEmail || !altcha}>{busy ? 'Se trimite…' : 'Trimite codul de resetare'}</Button>
            {cooldown > 0 && <Typography variant="body2">Poți reîncerca peste {cooldown} secunde.</Typography>}
        </Stack></Box> : <>
            <Box component="form" onSubmit={reset}><Stack spacing={2}>
                <Typography sx={{overflowWrap: 'anywhere'}}>Introdu codul primit la <strong>{email}</strong> și noua parolă.</Typography>
                <TextField label="Cod de resetare" required autoComplete="one-time-code" value={code} disabled={busy}
                    onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} inputProps={{inputMode: 'numeric', maxLength: 6}}/>
                <TextField label="Parola nouă" type="password" required autoComplete="new-password" value={password} disabled={busy}
                    onChange={(event) => setPassword(event.target.value)} error={Boolean(passwordError)} helperText={passwordError || 'Minimum 10 caractere, maximum 72 de octeți.'}/>
                <TextField label="Repetă parola nouă" type="password" required autoComplete="new-password" value={passwordAgain} disabled={busy}
                    onChange={(event) => setPasswordAgain(event.target.value)} error={Boolean(passwordAgain && passwordAgain !== password)}
                    helperText={passwordAgain && passwordAgain !== password ? 'Parolele nu coincid.' : ''}/>
                <Button type="submit" variant="contained" disabled={busy || !/^\d{6}$/.test(code) || !validPassword}>{busy ? 'Se salvează…' : 'Schimbă parola'}</Button>
            </Stack></Box>
            <Stack spacing={1.5} sx={{pt: 2, borderTop: 1, borderColor: 'divider'}}>
                <Typography variant="body2">Nu ai primit codul? Finalizează verificarea pentru a solicita unul nou.</Typography>
                <AltchaVerification key={`resend:${captchaVersion}`} onVerified={setAltcha}/>
                <Button onClick={sendCode} disabled={busy || cooldown > 0 || !altcha}>{cooldown > 0 ? `Retrimite codul (${cooldown}s)` : 'Retrimite codul'}</Button>
                <Button disabled={busy} onClick={() => { setStep('email'); setCode(''); setPassword(''); setPasswordAgain(''); setError(null); setInfo(null); resetVerification(); }}>Schimbă adresa de email</Button>
            </Stack>
        </>}
        {step !== 'done' && <Button component={RouterLink} to="/login" state={{email: email.trim(), from: after}}>Înapoi la autentificare</Button>}
    </Stack></Paper>;
}
