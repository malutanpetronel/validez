import usePageMeta from '../seo/usePageMeta';
import {Link as RouterLink} from 'react-router-dom';
import {Button, Link, Paper, Stack, Typography} from '@mui/material';

// Step L.2 (16.11.2026). Ciornă de lucru; poate fi completată după verificarea juridică de specialitate.
// Acceptarea explicită la înregistrare (cu versiunea acceptată) e pasul L2b, separat.
const sections = [
    ['Ce este Validez', 'Validez este o platformă de participare civică pentru sesizări, propuneri, sondaje și alte subiecte ale comunității. Validez nu este o instituție publică și nu acționează în numele vreunei instituții. Publicarea unui subiect pe Validez nu ține loc de petiție sau sesizare depusă la o autoritate în condițiile legii (OG 27/2002). Trimiterea sesizărilor către instituții nu este activă în versiunea actuală.'],
    ['Contul', 'Poți consulta subiectele publice fără cont. Pentru contribuții și vot ai nevoie de un cont, care aparține unei singure persoane cu vârsta de cel puțin 16 ani. Ești responsabil pentru păstrarea parolei și pentru activitatea din contul tău. Nu sunt permise conturile multiple ale aceleiași persoane și nici folosirea automatizărilor pentru creare de conturi, contribuții sau vot.'],
    ['Reguli pentru conținut', 'Contribuie cu bună-credință și cu informații pe care le consideri adevărate. Nu publica date personale ale altor persoane decât dacă sunt necesare subiectului. Nu sunt permise defăimarea, amenințările, instigarea la ură sau violență, discriminarea, spamul, reclama, conținutul ilegal sau cel care încalcă drepturile altora. Încarcă doar fotografii făcute de tine sau pe care ai dreptul să le publici.'],
    ['Moderare', 'Contribuțiile conturilor noi trec prin aprobarea administratorilor înainte de publicare. Administratorii pot ascunde sau scoate conținut care încalcă aceste reguli și pot restricționa sau suspenda conturi. Scoaterile de conținut apar în istoricul public al subiectului.'],
    ['Drepturile asupra conținutului', 'Păstrezi drepturile asupra conținutului pe care îl publici. Prin publicare acorzi operatorului o licență neexclusivă și gratuită de a afișa, stoca și distribui acest conținut pe platformă, inclusiv prin linkurile și imaginile de share, cât timp conținutul rămâne publicat. Istoricul versiunilor și jurnalul de integritate al voturilor se păstrează conform politicii de confidențialitate. Codul aplicației are licență separată, AGPL-3.0-or-later.'],
    ['Voturi și sondaje', 'Fiecare cont are un singur vot pe fiecare întrebare. Rezultatele arată doar opinia celor care au participat și nu sunt sondaje reprezentative statistic. Regulile despre acordul pentru vot, confidențialitatea votului și retragerea lui sunt în politica de confidențialitate.'],
    ['Răspundere', 'Conținutul publicat aparține autorilor și reprezintă opinia lor. Platforma este oferită „ca atare”: depunem eforturi rezonabile pentru funcționarea ei, dar nu garantăm că va fi disponibilă neîntrerupt sau fără erori.'],
    ['Modificări și legea aplicabilă', 'Putem actualiza acești termeni; modificările importante se anunță pe platformă, iar data de la începutul paginii arată ultima actualizare. Acești termeni sunt guvernați de legea română.'],
];

export default function TermsPage() {
    usePageMeta({title: 'Termeni și condiții', path: '/terms'});

    return <Paper sx={{p: {xs: 2, sm: 3}, maxWidth: 860, mx: 'auto'}}><Stack spacing={2.5}>
        <Typography component="h1" variant="h4">Termeni și condiții</Typography>
        <Typography variant="body2" color="text.secondary">Validez · Actualizați la 16 noiembrie 2026</Typography>
        <Stack spacing={1}>
            <Typography component="h2" variant="h6">Operator și contact</Typography>
            <Typography>Platforma este operată de AQUIS GRANA IMPEX SRL, CUI 53064471, cu sediul în 16 Febr. 2 / 21, 405200, Cluj, România.</Typography>
            <Typography>Contact: <Link href="mailto:contact@webnou.ro">contact@webnou.ro</Link>.</Typography>
        </Stack>
        {sections.map(([title, text]) => <Stack key={title} spacing={1}>
            <Typography component="h2" variant="h6">{title}</Typography>
            <Typography sx={{overflowWrap: 'anywhere'}}>{text}</Typography>
        </Stack>)}
        <Typography>Despre datele personale: <Link component={RouterLink} to="/privacy">Politica de confidențialitate</Link>.</Typography>
        <div><Button component={RouterLink} to="/">Înapoi la arbore</Button></div>
    </Stack></Paper>;
}
