import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App';

const renderApp = () => {
    ReactDOM.createRoot(document.getElementById('root')).render(
        <React.StrictMode>
            <App/>
        </React.StrictMode>
    );
    // Cordova (Android): ascunde ecranul de pornire imediat ce interfața e randată (în browser nu există).
    window.requestAnimationFrame(() => navigator.splashscreen?.hide());
};

// cordova.js exista DOAR in build-urile Cordova (android/browser); pe web (npm start / nginx) lipseste.
// Verificam Content-Type inainte de a-l injecta: dev-server-ul poate raspunde cu fallback SPA (text/html),
// iar un <script> cu HTML da "Unexpected token '<'" (lectie ArtaNFT).
const loadCordovaScriptIfReal = () => new Promise((resolve) => {
    fetch(`${process.env.PUBLIC_URL}/cordova.js`)
        .then((res) => {
            const contentType = res.headers.get('content-type') || '';
            if (res.ok && contentType.includes('javascript')) {
                const tag = document.createElement('script');
                tag.src = `${process.env.PUBLIC_URL}/cordova.js`;
                tag.onload = resolve;
                tag.onerror = resolve;
                document.body.appendChild(tag);
            } else {
                resolve();
            }
        })
        .catch(resolve);
});

// Cordova: asteptam deviceready; browser/web: randam direct.
loadCordovaScriptIfReal().then(() => {
    if (window.cordova) {
        document.addEventListener('deviceready', renderApp, false);
    } else {
        renderApp();
    }
});
