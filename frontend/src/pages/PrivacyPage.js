import {useEffect} from 'react';
import usePageMeta from '../seo/usePageMeta';
import {Link as RouterLink, useSearchParams} from 'react-router-dom';
import {Alert, Button, Link, Paper, Stack, Typography} from '@mui/material';

const sections = [
    ['Ce date folosim', 'Pentru cont folosim numele afișat, adresa de email și parola stocată ca hash. Păstrăm confirmarea emailului, rolurile și datele necesare autentificării și recuperării parolei. Pentru contribuții păstrăm subiectele, propunerile de categorii, estimările de cost și deciziile de moderare. Datele tehnice, precum adresa IP și jurnalele de acces, sunt folosite pentru securitate și limitarea abuzurilor. Jurnalele de acces se rotesc automat, au o dimensiune limitată și nu sunt incluse în copiile de siguranță. Operațiunile de vot, acordul pentru vot și descărcarea voturilor tale nu sunt înregistrate în ele.'],
    ['Ce este public și ce rămâne privat', 'Subiectele publicate, numele afișat al autorului, categoria, datele de publicare și estimările de cost sunt vizibile tuturor. Emailul și parola nu sunt afișate public. Subiectele în așteptarea aprobării sau ascunse sunt accesibile autorului și administratorilor. Notele tehnice personale ale propunerilor au acces în aplicație exclusiv pentru autor. Nu include în contribuțiile publice date personale ale altor persoane care nu sunt necesare.'],
    ['De ce folosim datele', 'Folosim datele pentru furnizarea contului și a funcționalităților cerute de utilizator, publicarea contribuțiilor, moderare și protejarea platformei. Furnizarea serviciului se bazează pe executarea relației de utilizare a platformei; securitatea și prevenirea abuzului se bazează pe interesul legitim, cu respectarea drepturilor utilizatorilor.'],
    ['Cookie-uri și stocare locală', 'Aplicația nu integrează tracking, analytics sau publicitate. În localStorage păstrăm tokenul de acces și tokenul de reînnoire a sesiunii, preferința de temă și faptul că ai închis informarea despre cookie-uri. Aceste date nu sunt folosite pentru urmărirea activității pe alte site-uri. „Am înțeles” închide informarea; nu activează servicii opționale. Poți redeschide informarea folosind butonul din colțul paginii.'],
    ['Cum controlezi stocarea', '„Ieși” elimină tokenurile sesiunii din browser. Poți șterge toate datele locale din setările browserului; vei fi deconectat, iar tema și informarea vor reveni la valorile inițiale. Ștergerea datelor din browser nu șterge contul sau contribuțiile de pe server.'],
    ['Cine poate primi datele', 'Administratorii au acces la datele necesare moderării și administrării. Furnizorii de găzduire și email procesează date necesare funcționării serviciului și trimiterii codurilor de confirmare sau recuperare. Contribuțiile publicate pot fi consultate de orice vizitator. Trimiterea automată a sesizărilor către instituții nu este activă în versiunea actuală.'],
    ['Păstrarea și securitatea datelor', 'Datele contului și contribuțiile sunt păstrate cât timp sunt necesare furnizării serviciului, moderării și soluționării solicitărilor. Codurile de confirmare și recuperare expiră după 15 minute; tokenurile au durată limitată și pot fi revocate. În producție, datele sunt transmise prin HTTPS, parolele sunt stocate ca hash, iar accesul este verificat pe server. Solicitările de ștergere se analizează împreună cu eventualele obligații de păstrare și drepturile altor persoane.'],
    ['Drepturile tale', 'În condițiile GDPR poți solicita informare, acces, rectificare, ștergere, restricționare, portabilitate și te poți opune anumitor prelucrări. Solicitările privind contul și datele personale se adresează contactului platformei. Notele personale au și funcții de export și ștergere prin API. Ai dreptul să depui o plângere la ANSPDCP.'],
    ['Actualizări', 'Această pagină descrie funcționalitățile actuale. O vom actualiza când introducem atașamente, localizare sau trimiterea sesizărilor către instituții, pentru a explica datele folosite de fiecare funcționalitate. Secțiunea despre voturi poate fi completată după o verificare juridică de specialitate.'],
];

// ADR-0007. Versiunea 2026-10-13 (= vote_consent_notice_version_default), în vigoare cu votarea activă; poate fi
// completată după verificarea de specialitate. Nu promitem anonimizare. Copiile de siguranță: cel mult 30 de zile
// (rotația din Deploy.md, secțiunea E) — orice altă copie a bazei trebuie să respecte același termen.
const VOTES = [
    ['Acordul', 'Votul este opțional. Îl prelucrăm doar cu acordul tău explicit, cerut separat, printr-o bifă nebifată inițial, la primul vot. Înregistrăm versiunea acestei informări și momentul acordului. Dacă modul de prelucrare se schimbă semnificativ, ți se cere din nou acordul înainte de următorul vot; voturile deja exprimate rămân.'],
    ['Voturile sunt private', 'Votul poate dezvălui opinii, inclusiv politice, indiferent de tipul subiectului. De aceea votul tău individual nu este afișat altor utilizatori și nu este accesibil administratorilor prin aplicație. Doar tu îți vezi propriile voturi.'],
    ['Ce este public', 'Sunt publice numai rezultatele agregate pentru fiecare subiect: câte voturi are fiecare opțiune pe versiunea curentă a subiectului și câte voturi date pe versiuni anterioare nu au fost reconfirmate.'],
    ['Versiunile subiectului', 'Fiecare vot este legat de versiunea subiectului pe care ai votat. Dacă titlul, descrierea sau estimarea se modifică, votul tău apare ca nereconfirmat: poți vedea ce s-a schimbat, apoi îl poți reconfirma, schimba sau retrage. Istoricul versiunilor subiectului este public, la fel ca subiectul.'],
    ['Retragerea votului și a acordului', 'Poți retrage oricând un vot; rezultatele se actualizează imediat. Retragerea unui vot nu retrage acordul și nu șterge istoricul tău privat de vot. Din „Voturile mele” poți retrage acordul: se retrag toate voturile tale active și se șterge istoricul tău privat de vot, inclusiv datele care îți permit să-ți verifici înregistrările. Retragerea funcționează și pe subiecte închise sau ascunse.'],
    ['Urme de integritate', 'Pentru a putea verifica integritatea rezultatelor, păstrăm pentru fiecare subiect un jurnal de operațiuni care nu conține numele, emailul, identificatorul contului sau opțiunea ta în clar, ci amprente criptografice, ordinea și momentul operațiunilor. După retragerea acordului sau ștergerea datelor de vot, aceste urme nu mai pot fi legate de tine prin datele din aplicație. Nu le prezentăm ca anonime: subiectul, momentul operațiunilor și alte informații pot permite corelări. Le păstrăm cât timp subiectul și rezultatele lui sunt păstrate pe platformă, deoarece fără ele integritatea rezultatelor nu mai poate fi verificată.'],
    ['Copii de siguranță', 'Ștergerea din aplicație nu elimină imediat datele din copiile de siguranță. Copiile de siguranță se păstrează cel mult 30 de zile; după acest interval, datele șterse din aplicație nu mai există nici în copii. Dacă restaurăm o copie, retragerile acordului pentru voturi făcute după realizarea ei se aplică din nou înainte ca platforma să redevină accesibilă.'],
    ['Datele tale de vot', 'Din „Voturile mele” poți descărca voturile și istoricul tău privat, împreună cu datele necesare pentru a-ți verifica propriile înregistrări în jurnalul de integritate, fără acces la operațiunile altor persoane.'],
];

export default function PrivacyPage() {
    usePageMeta({title: 'Confidențialitate', path: '/privacy'});
    const [params] = useSearchParams();
    const section = params.get('section');
    useEffect(() => {
        if (section) document.getElementById(`privacy-${section}`)?.scrollIntoView?.({behavior: 'smooth', block: 'start'});
    }, [section]);

    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 860, mx: 'auto'}}><Stack spacing={2.5}>
        <Typography component="h1" variant="h4">Politica de confidențialitate</Typography>
        <Typography variant="body2" color="text.secondary">Validez · Actualizată la 14 octombrie 2026</Typography>
        <Typography>Validez este o platformă de participare civică pentru sesizări, propuneri și alte subiecte ale comunității. Poți consulta subiectele publice fără cont; pentru contribuții este necesar un cont.</Typography>
        <Stack spacing={1}>
            <Typography component="h2" variant="h6">Operatorul datelor și contact</Typography>
            <Typography>Operatorul datelor este AQUIS GRANA IMPEX SRL, CUI 53064471, cu sediul în 16 Febr. 2 / 21, 405200, Cluj, România.</Typography>
            <Typography>Contact pentru confidențialitate: <Link href="mailto:contact@webnou.ro">contact@webnou.ro</Link>.</Typography>
        </Stack>
        {sections.map(([title, text]) => <Stack key={title} spacing={1}>
            <Typography component="h2" variant="h6">{title}</Typography>
            <Typography sx={{overflowWrap: 'anywhere'}}>{text}</Typography>
        </Stack>)}
        <Stack id="privacy-voturi" role="region" aria-labelledby="privacy-voturi-title" spacing={1.5} sx={{scrollMarginTop: 80}}>
            <Typography id="privacy-voturi-title" component="h2" variant="h6">Voturi</Typography>
            <Alert severity="info">Versiunea din 13.10.2026. Textul poate fi completat după o verificare juridică de specialitate. Dacă modul de prelucrare se schimbă semnificativ, îți vom cere din nou acordul înainte de următorul vot; voturile deja exprimate rămân.</Alert>
            {VOTES.map(([title, text]) => <Stack key={title} spacing={0.5}>
                <Typography component="h3" variant="subtitle1">{title}</Typography>
                <Typography sx={{overflowWrap: 'anywhere'}}>{text}</Typography>
            </Stack>)}
        </Stack>
        <Typography variant="body2"><Link href="https://www.dataprotection.ro/" target="_blank" rel="noopener noreferrer">ANSPDCP</Link>{' · '}
            <Link href="https://commission.europa.eu/law/law-topic/data-protection/information-individuals_en" target="_blank" rel="noopener noreferrer">Informații despre drepturile tale — Comisia Europeană</Link></Typography>
        <div><Button component={RouterLink} to="/">Înapoi la arbore</Button></div>
    </Stack></Paper>;
}
