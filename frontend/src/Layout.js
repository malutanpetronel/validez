import {Link as RouterLink, Outlet} from 'react-router-dom';
import {useMemo, useState} from 'react';
import {AppBar, Box, Button, Container, CssBaseline, Divider, Menu, MenuItem, Select, ThemeProvider, Toolbar, Typography} from '@mui/material';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import {createValidezTheme} from './theme';
import useAutomaticTheme from './useAutomaticTheme';
import version from './version.json';
import {useAuth} from './auth/AuthContext';

export default function Layout() {
    const {user, isAdmin, logout} = useAuth();
    const [userMenuAnchor, setUserMenuAnchor] = useState(null);
    const closeUserMenu = () => setUserMenuAnchor(null);
    const {preference, chooseTheme, mode} = useAutomaticTheme();
    const theme = useMemo(() => createValidezTheme(mode), [mode]);
    return (
        <ThemeProvider theme={theme}>
        <CssBaseline/>
        <Box data-theme={mode} sx={{display: 'flex', flexDirection: 'column', minHeight: '100vh', colorScheme: mode, bgcolor: 'background.default', color: 'text.primary'}}>
            <AppBar position="static" elevation={0}
                    sx={{bgcolor: 'background.paper', color: 'text.primary', backgroundImage: 'none',
                        borderBottom: 1, borderColor: 'divider', colorScheme: mode}}>
                {/* Pe ecrane înguste (Android cu „dimensiunea afișării" mărită ajunge la ~320 px) bara nu are voie
                    să depășească lățimea: altfel browserul lărgește toată pagina și o micșorează. */}
                <Toolbar sx={{gap: 1, px: {xs: 1.5, sm: 3}, py: 1}}>
                    {/* Logo-ul include numele și trimite spre pagina principală. */}
                    <Box component={RouterLink} to="/" aria-label="VALIDEZ — pagina principală"
                         sx={{display: 'flex', alignItems: 'center', color: 'inherit', textDecoration: 'none', minWidth: 0,
                             borderRadius: 1.5, py: 0.25, flexShrink: 0,
                             '&:focus-visible': {outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 3}}}>
                        <Box sx={{position: 'relative', width: {xs: 130, sm: 158}, maxWidth: '100%', flexShrink: 0}}>
                            <Box component="img" src={`${process.env.PUBLIC_URL}/logo-sus.png`} alt=""
                                 sx={{display: 'block', width: '100%', height: 'auto',
                                     filter: mode === 'dark' ? 'brightness(0) invert(1)' : 'none'}}/>
                            {mode === 'dark' && <Box component="img" src={`${process.env.PUBLIC_URL}/logo-sus.png`} alt="" aria-hidden="true"
                                 sx={{position: 'absolute', inset: 0, width: '100%', height: '100%', pointerEvents: 'none',
                                     clipPath: 'polygon(22.5% 23.9%, 15.9375% 54.35%, 14.0625% 67.39%, 12.1875% 55.43%, 17.5% 36.96%)'}}/>}
                        </Box>
                    </Box>
                    <Box sx={{flex: 1}}/>
                    {user ? (
                        <>
                            <Button color="inherit" variant="outlined" size="small"
                                    id="user-menu-button" aria-label="Meniu utilizator"
                                    aria-controls={userMenuAnchor ? 'user-menu' : undefined}
                                    aria-haspopup="true" aria-expanded={userMenuAnchor ? 'true' : undefined}
                                    onClick={(event) => setUserMenuAnchor(event.currentTarget)}
                                    endIcon={<ExpandMoreIcon/>} sx={{minWidth: 0, maxWidth: {xs: 150, sm: 240}}}>
                                <Typography component="span" variant="body2" noWrap>Logged in</Typography>
                            </Button>
                            <Menu id="user-menu" anchorEl={userMenuAnchor} open={Boolean(userMenuAnchor)}
                                  onClose={closeUserMenu} MenuListProps={{'aria-labelledby': 'user-menu-button'}}
                                  anchorOrigin={{vertical: 'bottom', horizontal: 'right'}}
                                  transformOrigin={{vertical: 'top', horizontal: 'right'}}>
                                <MenuItem disabled>{user.displayName}</MenuItem>
                                <Divider/>
                                {!isAdmin && <MenuItem component={RouterLink} to="/help" onClick={closeUserMenu}>Help</MenuItem>}
                                <MenuItem component={RouterLink} to="/categorii-propuse" onClick={closeUserMenu}>{isAdmin ? 'Propuneri de categorii' : 'Propune o categorie'}</MenuItem>
                                {isAdmin && <MenuItem component={RouterLink} to="/moderare" onClick={closeUserMenu}>Moderare subiecte</MenuItem>}
                                {isAdmin && <MenuItem component={RouterLink} to="/check-api" onClick={closeUserMenu}>Check API</MenuItem>}
                                <MenuItem onClick={() => { closeUserMenu(); logout(); }}>Ieși</MenuItem>
                            </Menu>
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
