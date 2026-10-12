import {Link as RouterLink} from 'react-router-dom';
import {Button, Link, Paper, Stack, Typography} from '@mui/material';

const sections = [
    ['Ce date folosim', 'Pentru cont folosim numele afișat, adresa de email și parola stocată ca hash. Păstrăm confirmarea emailului, rolurile și datele necesare autentificării și recuperării parolei. Pentru contribuții păstrăm subiectele, propunerile de categorii, estimările de cost și deciziile de moderare. Datele tehnice, precum adresa IP și jurnalele de acces, sunt folosite pentru securitate și limitarea abuzurilor.'],
    ['Ce este public și ce rămâne privat', 'Subiectele publicate, numele afișat al autorului, categoria, datele de publicare și estimările de cost sunt vizibile tuturor. Emailul și parola nu sunt afișate public. Subiectele în așteptarea aprobării sau ascunse sunt accesibile autorului și administratorilor. Notele tehnice personale ale propunerilor au acces în aplicație exclusiv pentru autor. Nu include în contribuțiile publice date personale ale altor persoane care nu sunt necesare.'],
    ['De ce folosim datele', 'Folosim datele pentru furnizarea contului și a funcționalităților cerute de utilizator, publicarea contribuțiilor, moderare și protejarea platformei. Furnizarea serviciului se bazează pe executarea relației de utilizare a platformei; securitatea și prevenirea abuzului se bazează pe interesul legitim, cu respectarea drepturilor utilizatorilor.'],
    ['Cookie-uri și stocare locală', 'Aplicația nu integrează tracking, analytics sau publicitate. În localStorage păstrăm tokenul de acces și tokenul de reînnoire a sesiunii, preferința de temă și faptul că ai închis informarea despre cookie-uri. Aceste date nu sunt folosite pentru urmărirea activității pe alte site-uri. „Am înțeles” închide informarea; nu activează servicii opționale. Poți redeschide informarea folosind butonul din colțul paginii.'],
    ['Cum controlezi stocarea', '„Ieși” elimină tokenurile sesiunii din browser. Poți șterge toate datele locale din setările browserului; vei fi deconectat, iar tema și informarea vor reveni la valorile inițiale. Ștergerea datelor din browser nu șterge contul sau contribuțiile de pe server.'],
    ['Cine poate primi datele', 'Administratorii au acces la datele necesare moderării și administrării. Furnizorii de găzduire și email procesează date necesare funcționării serviciului și trimiterii codurilor de confirmare sau recuperare. Contribuțiile publicate pot fi consultate de orice vizitator. Trimiterea automată a sesizărilor către instituții nu este activă în versiunea actuală.'],
    ['Păstrarea și securitatea datelor', 'Datele contului și contribuțiile sunt păstrate cât timp sunt necesare furnizării serviciului, moderării și soluționării solicitărilor. Codurile de confirmare și recuperare expiră după 15 minute; tokenurile au durată limitată și pot fi revocate. În producție, datele sunt transmise prin HTTPS, parolele sunt stocate ca hash, iar accesul este verificat pe server. Solicitările de ștergere se analizează împreună cu eventualele obligații de păstrare și drepturile altor persoane.'],
    ['Drepturile tale', 'În condițiile GDPR poți solicita informare, acces, rectificare, ștergere, restricționare, portabilitate și te poți opune anumitor prelucrări. Solicitările privind contul și datele personale se adresează contactului platformei. Notele personale au și funcții de export și ștergere prin API. Ai dreptul să depui o plângere la ANSPDCP.'],
    ['Actualizări', 'Această pagină descrie funcționalitățile actuale. O vom actualiza când introducem voturi, atașamente, localizare sau trimiterea sesizărilor către instituții, pentru a explica datele folosite de fiecare funcționalitate.'],
];
export default function PrivacyPage() {
    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 860, mx: 'auto'}}><Stack spacing={2.5}>
        <Typography component="h1" variant="h4">Politica de confidențialitate</Typography>
        <Typography variant="body2" color="text.secondary">Validez · Actualizată la 12 octombrie 2026</Typography>
        <Typography>Validez este o platformă de participare civică pentru sesizări, propuneri și alte subiecte ale comunității. Poți consulta subiectele publice fără cont; pentru contribuții este necesar un cont.</Typography>
        <Stack spacing={1}>
            <Typography component="h2" variant="h6">Contact pentru confidențialitate</Typography>
            <Typography>Contact: <Link href="mailto:contact@webnou.ro">contact@webnou.ro</Link>.</Typography>
        </Stack>
        {sections.map(([title, text]) => <Stack key={title} spacing={1}>
            <Typography component="h2" variant="h6">{title}</Typography>
            <Typography sx={{overflowWrap: 'anywhere'}}>{text}</Typography>
        </Stack>)}
        <Typography variant="body2"><Link href="https://www.dataprotection.ro/" target="_blank" rel="noopener noreferrer">ANSPDCP</Link>{' · '}
            <Link href="https://commission.europa.eu/law/law-topic/data-protection/information-individuals_en" target="_blank" rel="noopener noreferrer">Informații despre drepturile tale — Comisia Europeană</Link></Typography>
        <div><Button component={RouterLink} to="/">Înapoi la arbore</Button></div>
    </Stack></Paper>;
}
