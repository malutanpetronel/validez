import {useEffect, useRef} from 'react';
import {Box} from '@mui/material';
import {API_ENDPOINT} from '../config';

// Viteză (audit 18.11.2026): widgetul ALTCHA (~120 KB) se descarcă doar pe paginile care îl afișează.
// <altcha-widget> e un custom element: se activează singur când definiția ajunge (ascultătorii rămân atașați).
let widgetLoading = null;
const loadWidget = () => (widgetLoading ??= import('altcha').then(() => import('altcha/i18n/ro')));

/** The payload is sent unchanged to the server, which consumes it once. */
export default function AltchaVerification({onVerified}) {
    const ref = useRef(null);
    useEffect(() => { loadWidget(); }, []);
    useEffect(() => {
        const widget = ref.current;
        if (!widget) return;
        const stateChanged = (event) => onVerified(event.detail?.state === 'verified'
            ? event.detail.payload || widget.querySelector('input[name="altcha"]')?.value || '' : '');
        widget.addEventListener('statechange', stateChanged);
        return () => widget.removeEventListener('statechange', stateChanged);
    }, [onVerified]);
    return <Box sx={{minWidth: 0, '--altcha-max-width': '100%', '--altcha-color-base-bg': (theme) => theme.palette.background.paper,
        '--altcha-color-base-text': (theme) => theme.palette.text.primary, '--altcha-color-border': (theme) => theme.palette.divider,
        '--altcha-color-primary': (theme) => theme.palette.primary.main}}>
        <altcha-widget ref={ref} aria-label="Verificare ALTCHA" challenge={`${API_ENDPOINT}/captcha/challenge`} name="altcha" language="ro"/>
    </Box>;
}
