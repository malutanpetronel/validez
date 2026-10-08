import {createHashRouter} from 'react-router-dom';
import Layout from './Layout';
import TreePage from './pages/TreePage';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import SubjectPage from './pages/SubjectPage';
import SubjectsPage from './pages/SubjectsPage';
import CheckApiPage from './pages/CheckApiPage';

// Hash router: functioneaza identic in browser, pe nginx si in WebView-ul Cordova (file:// / http://localhost),
// fara configurare de rescriere pe server (acelasi tipar ca ArtaNFT).
export const routes = [
    {
        path: '/',
        element: <Layout/>,
        children: [
            {index: true, element: <TreePage/>},
            {path: 'arbore', element: <TreePage/>},
            {path: 'check-api', element: <CheckApiPage/>},
            {path: 'login', element: <LoginPage/>},
            {path: 'inregistrare', element: <RegisterPage/>},
            {path: 'subiecte', element: <SubjectsPage/>},
            {path: 'arbore/:nodeId/subiecte', element: <SubjectsPage/>},
            {path: 'subiecte/:id', element: <SubjectPage/>},
        ],
    },
];

export const createAppRouter = () => createHashRouter(routes);
