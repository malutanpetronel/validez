import {createTheme} from '@mui/material/styles';

// Paleta din docs/paleta_culori.md
export const BRAND = {
    bleumarin: '#073568',
    albastru: '#0877C5',
    verde: '#20AE6C',
};

export const automaticMode = (date = new Date()) => date.getHours() >= 19 || date.getHours() < 7 ? 'dark' : 'light';

export const createValidezTheme = (mode) => createTheme({
    palette: {
        mode,
        background: mode === 'light' ? {default: '#dfedff', paper: '#FFFFFF'} : {default: '#121212', paper: '#1E1E1E'},
        primary: {main: mode === 'dark' ? '#79B8F3' : BRAND.bleumarin},
        secondary: {main: BRAND.verde, contrastText: '#FFFFFF'},
        info: {main: BRAND.albastru},
        success: {main: BRAND.verde},
    },
    typography: {
        fontFamily: '"Roboto", "Helvetica", "Arial", sans-serif',
    },
});

export default createValidezTheme('light');
