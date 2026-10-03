import {Link as RouterLink, Outlet} from 'react-router-dom';
import {AppBar, Box, Button, Container, Toolbar, Typography} from '@mui/material';
import version from './version.json';
import {useAuth} from './auth/AuthContext';

export default function Layout() {
    const {user, logout} = useAuth();
    return (
        <Box sx={{display: 'flex', flexDirection: 'column', minHeight: '100vh'}}>
            <AppBar position="static" color="primary">
                {/* Pe ecrane înguste (Android cu „dimensiunea afișării" mărită ajunge la ~320 px) bara nu are voie
                    să depășească lățimea: altfel browserul lărgește toată pagina și o micșorează. */}
                <Toolbar sx={{gap: 1, px: {xs: 1.5, sm: 3}}}>
                    {/* Logo + nume = link spre pagina principală (fără buton separat „Acasă"). */}
                    <Box component={RouterLink} to="/" aria-label="VALIDEZ — pagina principală"
                         sx={{display: 'flex', alignItems: 'center', color: 'inherit', textDecoration: 'none', minWidth: 0}}>
                        <Box component="img" src={`${process.env.PUBLIC_URL}/logo.png`} alt="" sx={{height: 36, mr: 1.25, flexShrink: 0}}/>
                        <Typography variant="h6" component="span" noWrap
                                    sx={{fontWeight: 700, letterSpacing: 1, '@media (max-width: 359.95px)': {display: 'none'}}}>
                            VALIDEZ
                        </Typography>
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
                VEZI. VERIFICĂ. VALIDEAZĂ. · v{version.version} · AGPL-3.0-or-later
            </Box>
        </Box>
    );
}
