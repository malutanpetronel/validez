import {createHashRouter} from 'react-router-dom';
import Layout from './Layout';
import Home from './pages/Home';

// Hash router: functioneaza identic in browser, pe nginx si in WebView-ul Cordova (file:// / http://localhost),
// fara configurare de rescriere pe server (acelasi tipar ca ArtaNFT).
export const routes = [
    {
        path: '/',
        element: <Layout/>,
        children: [
            {index: true, element: <Home/>},
        ],
    },
];

export const createAppRouter = () => createHashRouter(routes);
