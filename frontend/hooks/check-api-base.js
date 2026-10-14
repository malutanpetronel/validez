#!/usr/bin/env node
// Hook Cordova before_prepare, doar pentru Android (config.xml, <platform name="android">).
//
// Opreste build-ul daca www/ contine build-ul de dev: `npm run build` ia REACT_APP_API_BASE din .env.local
// (http://localhost:8020), iar pe telefon localhost e chiar telefonul - aplicatia porneste cu arborele gol.
// Pentru Android: `npm run android` sau `npm run build:android` (Deploy.md, sectiunea I).
//
// Verifica doar scripturile incarcate de www/index.html: `cp -r build/* www/` lasa in www/ si bundle-uri vechi.
const fs = require('fs');
const path = require('path');

module.exports = function (context) {
    const root = context.opts.projectRoot;
    const www = path.join(root, 'www');

    let devBase = 'http://localhost:8020';
    try {
        const env = fs.readFileSync(path.join(root, '.env.local'), 'utf8');
        const m = env.match(/^\s*REACT_APP_API_BASE\s*=\s*(\S+)\s*$/m);
        if (m) devBase = m[1].replace(/^["']|["']$/g, '');
    } catch (e) { /* fara .env.local: ramane adresa de dev implicita */ }

    let html;
    try {
        html = fs.readFileSync(path.join(www, 'index.html'), 'utf8');
    } catch (e) {
        throw new Error('www/index.html lipseste - ruleaza `npm run android` sau `npm run build:android`.');
    }

    const scripts = [...html.matchAll(/<script[^>]+src="\/?([^"]+\.js)"/g)].map((m) => m[1]);
    const offending = scripts.filter((src) => {
        const file = path.join(www, src);
        return fs.existsSync(file) && fs.readFileSync(file, 'utf8').includes(devBase);
    });

    if (offending.length > 0) {
        throw new Error(
            `www/ contine build-ul de dev (API ${devBase} in ${offending.join(', ')}). `
            + 'Pentru Android foloseste `npm run android` (build + run) sau `npm run build:android`.'
        );
    }
};
