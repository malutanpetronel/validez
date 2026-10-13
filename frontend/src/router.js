import {createBrowserRouter, createHashRouter} from 'react-router-dom';
import Layout from './Layout';
import PrivacyPage from './pages/PrivacyPage';
import TreePage from './pages/TreePage';
import ForgotPasswordPage from './pages/ForgotPasswordPage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import SubjectPage from './pages/SubjectPage';
import SubjectsPage from './pages/SubjectsPage';
import HelpPage from './pages/HelpPage';
import CategorySuggestionsPage from './pages/CategorySuggestionsPage';
import ModerationPage from './pages/ModerationPage';
import CheckApiPage from './pages/CheckApiPage';
import MyVotesPage from './pages/MyVotesPage';
import LegacyCategoryRedirect from './pages/LegacyCategoryRedirect';

// ADR-0008: pe web BrowserRouter (URL-uri reale, indexabile; nginx are fallback SPA), in Cordova HashRouter
// (WebView din file://, unde caile nu ajung la un server). Rutele sunt aceleasi.
export const routes = [
    {
        path: '/',
        element: <Layout/>,
        children: [
            {index: true, element: <TreePage/>},
            {path: 'help', element: <HelpPage/>},
            {path: 'privacy', element: <PrivacyPage/>},
            {path: 'categorii-propuse', element: <CategorySuggestionsPage/>},
            {path: 'moderare', element: <ModerationPage/>},
            {path: 'check-api', element: <CheckApiPage/>},
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
            {path: 'voturile-mele', element: <MyVotesPage/>},
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
