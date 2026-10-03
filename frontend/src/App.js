import {RouterProvider} from 'react-router-dom';
import {createAppRouter} from './router';
import {AuthProvider} from './auth/AuthContext';

const router = createAppRouter();

export default function App() {
    return (
            <AuthProvider>
                <RouterProvider router={router}/>
            </AuthProvider>
    );
}
