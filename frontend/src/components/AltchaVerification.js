import {useEffect, useRef} from 'react';
import {Box} from '@mui/material';
import 'altcha';
import 'altcha/i18n/ro';
import {API_ENDPOINT} from '../config';

/** The payload is sent unchanged to the server, which consumes it once. */
export default function AltchaVerification({onVerified}) {
    const ref = useRef(null);
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
