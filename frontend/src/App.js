import {CssBaseline, ThemeProvider} from '@mui/material';
import {RouterProvider} from 'react-router-dom';
import theme from './theme';
import {createAppRouter} from './router';

const router = createAppRouter();

export default function App() {
    return (
        <ThemeProvider theme={theme}>
            <CssBaseline/>
            <RouterProvider router={router}/>
        </ThemeProvider>
    );
}
