import {createTheme} from '@mui/material/styles';

// Paleta din docs/paleta_culori.md
export const BRAND = {
    bleumarin: '#073568',
    albastru: '#0877C5',
    verde: '#20AE6C',
};

const theme = createTheme({
    palette: {
        primary: {main: BRAND.bleumarin},
        secondary: {main: BRAND.verde, contrastText: '#FFFFFF'},
        info: {main: BRAND.albastru},
        success: {main: BRAND.verde},
    },
    typography: {
        fontFamily: '"Roboto", "Helvetica", "Arial", sans-serif',
    },
});

export default theme;
