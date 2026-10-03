import {CssBaseline, ThemeProvider} from '@mui/material';
import {RouterProvider} from 'react-router-dom';
import theme from './theme';
import {createAppRouter} from './router';
import {AuthProvider} from './auth/AuthContext';

const router = createAppRouter();

export default function App() {
    return (
        <ThemeProvider theme={theme}>
            <CssBaseline/>
            <AuthProvider>
                <RouterProvider router={router}/>
            </AuthProvider>
        </ThemeProvider>
    );
}
