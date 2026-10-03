import {createContext, useContext, useEffect, useMemo, useState} from 'react';
import {currentUser, isAdmin, SESSION_EVENT} from './session';
import {login, logout} from '../api/http';

const AuthContext = createContext({user: null, isAdmin: false, login, logout});

export function AuthProvider({children}) {
    const [user, setUser] = useState(currentUser);

    // Sesiunea se schimba din login/logout/refresh (SESSION_EVENT) sau din alt tab (storage).
    useEffect(() => {
        const sync = () => setUser(currentUser());
        window.addEventListener(SESSION_EVENT, sync);
        window.addEventListener('storage', sync);
        return () => {
            window.removeEventListener(SESSION_EVENT, sync);
            window.removeEventListener('storage', sync);
        };
    }, []);

    const value = useMemo(() => ({user, isAdmin: isAdmin(user), login, logout}), [user]);

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export const useAuth = () => useContext(AuthContext);
