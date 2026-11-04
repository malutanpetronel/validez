import '@testing-library/jest-dom';

// react-router 7 foloseste TextEncoder/TextDecoder, absente in jsdom-ul din Jest 27 (CRA) - ca la ArtaNFT.
import {TextEncoder, TextDecoder} from 'util';
Object.assign(global, {TextEncoder, TextDecoder});

// ALTCHA is an ESM Web Component using browser workers/WebCrypto. Tests exercise
// our event bridge with a plain custom element; backend tests solve real challenges.
jest.mock('altcha', () => ({}));
jest.mock('altcha/i18n/ro', () => ({}), {virtual: true});

// Testele arborelui (MUI + rc-tree, ~2 s) depășesc 5 s doar sub încărcare mare a mașinii (rulare paralelă).
jest.setTimeout(15000);

// react-zoom-pan-pinch (PhotoViewer, ca PhotoModal din ArtaNFT) folosește ResizeObserver, absent în jsdom.
if (!global.ResizeObserver) {
    global.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
}
// useRestaurareScroll (Layout): jsdom nu implementează window.scrollTo.
window.scrollTo = jest.fn();
// api/votes ține sumarele voturilor în cache ~15 s; între teste îl golim (evenimentul de sesiune îl curăță).
beforeEach(() => window.dispatchEvent(new Event('validez-session-change')));
