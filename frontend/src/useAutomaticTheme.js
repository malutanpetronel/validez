import {useEffect, useState} from 'react';
import {automaticMode} from './theme';

export default function useAutomaticTheme() {
    const [preference, setPreference] = useState(() => {
        try { const value = localStorage.getItem('validez-theme'); return ['light', 'dark', 'auto'].includes(value) ? value : 'auto'; }
        catch { return 'auto'; }
    });
    const [clockMode, setClockMode] = useState(automaticMode);
    useEffect(() => {
        const update = () => setClockMode(automaticMode());
        const timer = setInterval(update, 30000);
        window.addEventListener('focus', update);
        document.addEventListener('visibilitychange', update);
        document.addEventListener('resume', update);
        return () => { clearInterval(timer); window.removeEventListener('focus', update); document.removeEventListener('visibilitychange', update); document.removeEventListener('resume', update); };
    }, []);
    const chooseTheme = (value) => {
        setPreference(value);
        setClockMode(automaticMode());
        try { localStorage.setItem('validez-theme', value); } catch { /* Storage may be unavailable. */ }
    };
    return {preference, chooseTheme, mode: preference === 'auto' ? clockMode : preference};
}
