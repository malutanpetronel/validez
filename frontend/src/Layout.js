import {Link as RouterLink, Outlet} from 'react-router-dom';
import {useMemo} from 'react';
import {AppBar, Box, Button, Container, CssBaseline, MenuItem, Select, ThemeProvider, Toolbar, Typography} from '@mui/material';
import {BRAND, createValidezTheme} from './theme';
import useAutomaticTheme from './useAutomaticTheme';
import version from './version.json';
import {useAuth} from './auth/AuthContext';

export default function Layout() {
    const {user, logout} = useAuth();
    const {preference, chooseTheme, mode} = useAutomaticTheme();
    const theme = useMemo(() => createValidezTheme(mode), [mode]);
    return (
        <ThemeProvider theme={theme}>
        <CssBaseline/>
        <Box data-theme={mode} sx={{display: 'flex', flexDirection: 'column', minHeight: '100vh', colorScheme: mode, bgcolor: 'background.default', color: 'text.primary'}}>
            <AppBar position="static" sx={{bgcolor: '#dfedff', color: BRAND.bleumarin, backgroundImage: 'none', colorScheme: 'light'}}>
                {/* Pe ecrane înguste (Android cu „dimensiunea afișării" mărită ajunge la ~320 px) bara nu are voie
                    să depășească lățimea: altfel browserul lărgește toată pagina și o micșorează. */}
                <Toolbar sx={{gap: 1, px: {xs: 1.5, sm: 3}}}>
                    {/* Logo-ul include numele și trimite spre pagina principală. */}
                    <Box component={RouterLink} to="/" aria-label="VALIDEZ — pagina principală"
                         sx={{display: 'flex', alignItems: 'center', color: 'inherit', textDecoration: 'none', minWidth: 0}}>
                        <Box component="img" src={`${process.env.PUBLIC_URL}/logo-sus.png`} alt=""
                             sx={{display: 'block', width: {xs: 130, sm: 158}, maxWidth: '100%', height: 'auto', flexShrink: 0}}/>
                    </Box>
                    <Box sx={{flex: 1}}/>
                    <Button color="inherit" component={RouterLink} to="/arbore" sx={{minWidth: 0}}>Arbore</Button>
                    {user ? (
                        <>
                            <Typography variant="body2" noWrap sx={{maxWidth: 160, display: {xs: 'none', sm: 'block'}}}>{user.displayName}</Typography>
                            <Button color="inherit" variant="outlined" size="small" onClick={logout} sx={{flexShrink: 0}}>Ieși</Button>
                        </>
                    ) : (
                        <Button color="inherit" variant="outlined" size="small" component={RouterLink} to="/login" sx={{flexShrink: 0}}>Intră</Button>
                    )}
                </Toolbar>
            </AppBar>
            <Container component="main" sx={{flex: 1, py: 3}}>
                <Outlet/>
            </Container>
            <Box component="footer" sx={{py: 1.5, textAlign: 'center', color: 'text.secondary', fontSize: 13}}>
                <Box sx={{mb: 1}}>
                    <Select size="small" value={preference}
                         inputProps={{'aria-label': 'Tema de afișare'}}
                         onChange={(event) => chooseTheme(event.target.value)}
                         title="Automat: nocturn între 19:00 și 07:00, ora locală"
                         MenuProps={{
                             anchorOrigin: {vertical: 'top', horizontal: 'center'},
                             transformOrigin: {vertical: 'bottom', horizontal: 'center'},
                         }}
                         sx={{bgcolor: 'background.paper', color: 'text.primary', fontSize: 14}}>
                        <MenuItem value="auto">Temă: Automat</MenuItem>
                        <MenuItem value="light">Temă: Luminos</MenuItem>
                        <MenuItem value="dark">Temă: Nocturn</MenuItem>
                    </Select>
                </Box>
                VEZI. VERIFICĂ. VALIDEAZĂ. · v{version.version} · AGPL-3.0-or-later
            </Box>
        </Box>
        </ThemeProvider>
    );
}
