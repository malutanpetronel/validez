import {createHashRouter} from 'react-router-dom';
import Layout from './Layout';
import Home from './pages/Home';
import TreePage from './pages/TreePage';
import LoginPage from './pages/LoginPage';
import SubjectPage from './pages/SubjectPage';

// Hash router: functioneaza identic in browser, pe nginx si in WebView-ul Cordova (file:// / http://localhost),
// fara configurare de rescriere pe server (acelasi tipar ca ArtaNFT).
export const routes = [
    {
        path: '/',
        element: <Layout/>,
        children: [
            {index: true, element: <Home/>},
            {path: 'arbore', element: <TreePage/>},
            {path: 'login', element: <LoginPage/>},
            {path: 'subiecte/:id', element: <SubjectPage/>},
        ],
    },
];

export const createAppRouter = () => createHashRouter(routes);
