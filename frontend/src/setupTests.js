import '@testing-library/jest-dom';

// react-router 7 foloseste TextEncoder/TextDecoder, absente in jsdom-ul din Jest 27 (CRA) - ca la ArtaNFT.
import {TextEncoder, TextDecoder} from 'util';
Object.assign(global, {TextEncoder, TextDecoder});
