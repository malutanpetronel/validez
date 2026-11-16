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
        background: mode === 'light' ? {default: '#F4F7FB', paper: '#FFFFFF'} : {default: '#111A25', paper: '#1B2734'},
        primary: {main: mode === 'dark' ? '#9ACBFF' : BRAND.bleumarin},
        secondary: {main: BRAND.verde, contrastText: '#FFFFFF'},
        info: {main: BRAND.albastru},
        success: {main: BRAND.verde},
    },
    shape: {borderRadius: 12},
    typography: {
        fontFamily: '"Roboto", "Helvetica", "Arial", sans-serif',
    },
    components: {
        MuiButton: {styleOverrides: {root: {textTransform: 'none', fontWeight: 700, borderRadius: 10}}},
        MuiPaper: {styleOverrides: {root: {backgroundImage: 'none'}}},
    },
});

export default createValidezTheme('light');
