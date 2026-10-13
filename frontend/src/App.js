import {useState} from 'react';
import {RouterProvider} from 'react-router-dom';
import {createAppRouter} from './router';
import {AuthProvider} from './auth/AuthContext';

export default function App() {
    // Creat la prima randare, nu la importul modulului: in Cordova randarea vine dupa deviceready,
    // cand window.cordova exista deja (alegerea Hash vs Browser router depinde de el).
    const [router] = useState(() => createAppRouter());
    return (
            <AuthProvider>
                <RouterProvider router={router}/>
            </AuthProvider>
    );
}
