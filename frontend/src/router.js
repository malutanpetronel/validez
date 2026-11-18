import {lazy, Suspense} from 'react';
import {LinearProgress} from '@mui/material';
import {createBrowserRouter, createHashRouter} from 'react-router-dom';
import Layout from './Layout';
import TreePage from './pages/TreePage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import SubjectPage from './pages/SubjectPage';
import SubjectsPage from './pages/SubjectsPage';
import LegacyCategoryRedirect from './pages/LegacyCategoryRedirect';
import NotFoundPage from './pages/NotFoundPage';
import ErrorPage from './pages/ErrorPage';

// Viteză (audit 18.11.2026): paginile rare (legale, cont, admin) se descarcă doar când sunt deschise.
// Arborele, subiectul, listele și login-ul rămân în pachetul principal (sunt prima pagină a vizitatorilor).
const page = (load, name = 'default') => {
    const Page = lazy(() => load().then((m) => ({default: m[name]})));
    return <Suspense fallback={<LinearProgress aria-label="Se încarcă pagina"/>}><Page/></Suspense>;
};

// ADR-0008: pe web BrowserRouter (URL-uri reale, indexabile; nginx are fallback SPA), in Cordova HashRouter
// (WebView din file://, unde caile nu ajung la un server). Rutele sunt aceleasi.
export const routes = [
    {
        path: '/',
        element: <Layout/>,
        errorElement: <ErrorPage/>,
        children: [
            {index: true, element: <TreePage/>},
            {path: 'help', element: page(() => import('./pages/HelpPage'))},
            {path: 'privacy', element: page(() => import('./pages/PrivacyPage'))},
            {path: 'terms', element: page(() => import('./pages/TermsPage'))},
            {path: 'categorii-propuse', element: page(() => import('./pages/CategorySuggestionsPage'))},
            {path: 'moderare', element: page(() => import('./pages/ModerationPage'))},
            {path: 'setari-ai', element: page(() => import('./pages/AiSettingsPage'))},
            {path: 'sterge-cont', element: page(() => import('./pages/DeleteAccountPage'))},
            {path: 'sterge-cont/confirmare', element: page(() => import('./pages/DeleteAccountPage'), 'DeleteAccountConfirmPage')},
            {path: 'check-api', element: page(() => import('./pages/CheckApiPage'))},
            {path: 'am-uitat-parola', element: <ForgotPasswordPage/>},
            {path: 'login', element: <LoginPage/>},
            {path: 'inregistrare', element: <RegisterPage/>},
            {path: 'subiecte', element: <SubjectsPage/>},
            // URL-uri publice canonice: /categorie/{cale} si /categorie/{cale}/subiect/{slug} - tot arborele,
            // cu selectia data de adresa (acelasi element ca pe `/`, deci arborele nu se reconstruieste la click).
            {path: 'categorie/*', element: <TreePage/>},
            // Vechi: raman valide si duc la URL-ul canonic dupa incarcare.
            {path: 'categorii/:nodeId/subiecte', element: <LegacyCategoryRedirect/>},
            {path: 'subiecte/:id', element: <SubjectPage/>},
            {path: 'voturile-mele', element: page(() => import('./pages/MyVotesPage'))},
            // Orice alta adresa: pagina „nu exista” (noindex), cu antetul aplicatiei.
            {path: '*', element: <NotFoundPage/>},
        ],
    },
];

/** Pe web, linkurile vechi `/#/subiecte/…` devin `/subiecte/…` înainte de pornirea routerului. */
export function migrateLegacyHash(loc = window.location, history = window.history) {
    if (loc.hash.startsWith('#/')) history.replaceState(history.state, '', loc.hash.slice(1));
}

/** De apelat dupa deviceready (App, la prima randare): abia atunci window.cordova exista in Android. */
export const createAppRouter = (isCordova = Boolean(window.cordova)) => {
    if (isCordova) return createHashRouter(routes);
    migrateLegacyHash();
    return createBrowserRouter(routes);
};
