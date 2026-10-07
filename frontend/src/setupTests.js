import '@testing-library/jest-dom';

// react-router 7 foloseste TextEncoder/TextDecoder, absente in jsdom-ul din Jest 27 (CRA) - ca la ArtaNFT.
import {TextEncoder, TextDecoder} from 'util';
Object.assign(global, {TextEncoder, TextDecoder});

// ALTCHA is an ESM Web Component using browser workers/WebCrypto. Tests exercise
// our event bridge with a plain custom element; backend tests solve real challenges.
jest.mock('altcha', () => ({}));
jest.mock('altcha/i18n/ro', () => ({}), {virtual: true});
