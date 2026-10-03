import {Link as RouterLink, Outlet} from 'react-router-dom';
import {AppBar, Box, Button, Container, Toolbar, Typography} from '@mui/material';
import version from './version.json';

export default function Layout() {
    return (
        <Box sx={{display: 'flex', flexDirection: 'column', minHeight: '100vh'}}>
            <AppBar position="static" color="primary">
                <Toolbar>
                    <Box component="img" src={`${process.env.PUBLIC_URL}/logo.png`} alt="" sx={{height: 36, mr: 1.5}}/>
                    <Typography variant="h6" component="div" sx={{fontWeight: 700, letterSpacing: 1}}>
                        VALIDEZ
                    </Typography>
                    <Box sx={{flex: 1}}/>
                    <Button color="inherit" component={RouterLink} to="/">Acasă</Button>
                    <Button color="inherit" component={RouterLink} to="/arbore">Arbore</Button>
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
