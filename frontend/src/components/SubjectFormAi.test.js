import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import exifr from 'exifr';
import SubjectForm from './SubjectForm';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt, fakeJwt} from '../testUtils/fakeJwt';
import {res, routedFetch, subject} from '../testUtils/subjects';

jest.mock('exifr', () => ({__esModule: true, default: {parse: jest.fn()}}));

const NODE = {id: '01ROOT', name: 'Drumuri'};
const suggestion = (over = {}) => ({
    type: 'ISSUE', isPublicProblem: true, problemKind: 'POTHOLE', title: 'Groapă adâncă lângă trecere', description: 'Se vede o groapă largă în carosabil.',
    severity: 'HIGH', immediateDanger: false, locationClues: ['Str. Avram Iancu 12'], categoryId: '01GROPI', categoryPath: 'Drumuri > Gropi',
    confidence: 0.87, peopleOrPlatesVisible: true, ...over,
});
const photo = () => new File(['jpeg'], 'groapa.jpg', {type: 'image/jpeg'});
const renderForm = (onSaved = () => {}) => render(<AuthProvider><SubjectForm node={NODE} onClose={() => {}} onSaved={onSaved}/></AuthProvider>);

beforeEach(() => {
    window.localStorage.clear();
    global.URL.createObjectURL = jest.fn(() => 'blob:poza');
    global.URL.revokeObjectURL = jest.fn();
    exifr.parse.mockResolvedValue({latitude: 47.1412, longitude: 23.8745, DateTimeOriginal: new Date('2026-11-17T09:30:00Z')});
    global.fetch = routedFetch([
        [(u) => u.endsWith('/ai/subject-suggestion'), () => res({suggestion: suggestion(), provider: 'anthropic', model: 'claude-haiku-5-5', cached: false, costUsd: '0.000220'})],
        [(u, o) => o.method === 'POST' && u.endsWith('/media'), () => res({id: '01MEDIA'}, 201)],
        [(u, o) => o.method === 'POST' && u.endsWith('/civic_subjects'), (u, o) => res(subject({id: '01NOU', ...JSON.parse(o.body)}), 201)],
    ]);
});

test('utilizator obișnuit: fără sesizarea din fotografie', () => {
    setSession({token: fakeJwt({username: 'ion@validez.test', displayName: 'Ion', roles: ['ROLE_USER'], id: '01ION'})});
    renderForm();
    expect(screen.queryByText('Sesizare din fotografie')).not.toBeInTheDocument();
});

test('admin: fotografia completează textul, pinul din EXIF, categoria la cerere și se încarcă după salvare', async () => {
    setSession({token: adminJwt()});
    const onSaved = jest.fn();
    renderForm(onSaved);
    fireEvent.change(screen.getByTestId('ai-gallery'), {target: {files: [photo()]}});

    expect(await screen.findByDisplayValue('Groapă adâncă lângă trecere')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Se vede o groapă largă în carosabil.')).toBeInTheDocument();
    expect(screen.getByText('47.141200, 23.874500 · din fotografie')).toBeInTheDocument();
    expect(screen.getByText('Pinul a fost pus din coordonatele fotografiei. Verifică-l pe hartă.')).toBeInTheDocument();
    expect(screen.getByText(/persoane sau numere de înmatriculare/)).toBeInTheDocument();
    expect(screen.getByText('Str. Avram Iancu 12')).toBeInTheDocument();

    const [aiUrl, aiOpts] = global.fetch.mock.calls.find(([u]) => u.endsWith('/ai/subject-suggestion'));
    expect(aiUrl).toContain('/api/ai/subject-suggestion');
    expect(aiOpts.body.get('node')).toBe('01ROOT');
    expect(aiOpts.body.get('takenAt')).toBe('2026-11-17T09:30:00.000Z');

    fireEvent.click(screen.getByRole('button', {name: 'Mută aici'}));
    expect(screen.getByText('Drumuri > Gropi', {selector: 'strong'})).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    await waitFor(() => expect(onSaved).toHaveBeenCalled());

    const [, create] = global.fetch.mock.calls.find(([u, o]) => o?.method === 'POST' && u.endsWith('/civic_subjects'));
    const body = JSON.parse(create.body);
    expect(body).toMatchObject({node: '01GROPI', type: 'ISSUE', title: 'Groapă adâncă lângă trecere', location: {lat: 47.1412, lng: 23.8745, source: 'PHOTO'}});
    const upload = global.fetch.mock.calls.find(([u, o]) => o?.method === 'POST' && u.endsWith('/civic_subjects/01NOU/media'));
    expect(upload[1].body.get('file').name).toBe('groapa.jpg');
});

test('admin: textul scris deja rămâne; fără GPS se cere pinul; eroarea AI se poate reîncerca', async () => {
    setSession({token: adminJwt()});
    exifr.parse.mockResolvedValue({});
    let calls = 0;
    global.fetch = routedFetch([[(u) => u.endsWith('/ai/subject-suggestion'), () => (++calls === 1
        ? res({message: 'Ți-ai atins limita zilnică de sugestii AI. Revino mâine.'}, 429)
        : res({suggestion: suggestion(), cached: true}))]]);
    renderForm();
    fireEvent.change(screen.getByLabelText(/Titlu/), {target: {value: 'Titlul meu'}});
    fireEvent.change(screen.getByTestId('ai-gallery'), {target: {files: [photo()]}});

    expect(await screen.findByText('Ți-ai atins limita zilnică de sugestii AI. Revino mâine.')).toBeInTheDocument();
    expect(screen.getByText(/Fotografia nu are coordonate\. Dacă ești la fața locului/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Reîncearcă'}));
    expect(await screen.findByText('din cache')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Titlul meu')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Se vede o groapă largă în carosabil.')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Folosește titlul și descrierea propuse'}));
    expect(screen.getByDisplayValue('Groapă adâncă lângă trecere')).toBeInTheDocument();
});
