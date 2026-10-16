import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import PinAdministrative from './PinAdministrative';
import {resolvePinClassification} from '../api/geo';

jest.mock('../api/geo');

const ambiguous = {lat: 47.1, lng: 23.9, administrative: {state: 'AMBIGUOUS', unit: null, county: null, provisional: true,
    candidates: [{code: '55008', name: 'MUNICIPIUL DEJ'}, {code: '55286', name: 'COMUNA CUZDRIOARA'}]}};

beforeEach(() => jest.resetAllMocks());

test('autorul sau adminul alege una dintre variante (ADR-0012 p.6)', async () => {
    const resolved = {state: 'RESOLVED_MANUALLY', unit: {code: '55008', name: 'MUNICIPIUL DEJ'}, county: {name: 'JUDEȚUL CLUJ'}, candidates: [], provisional: true};
    resolvePinClassification.mockResolvedValue({administrative: resolved});
    const onResolved = jest.fn();
    render(<PinAdministrative subjectId="S1" location={ambiguous} canResolve onResolved={onResolved}/>);
    expect(screen.getByText(/Încadrare neclară, aproape de o limită administrativă/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'MUNICIPIUL DEJ'}));
    expect(resolvePinClassification).toHaveBeenCalledWith('S1', '55008');
    await waitFor(() => expect(onResolved).toHaveBeenCalledWith(resolved));
    await waitFor(() => expect(screen.getByRole('button', {name: 'MUNICIPIUL DEJ'})).toBeEnabled());
});

test('fără drept de editare, doar textul; după alegere, „ales manual”', () => {
    const {rerender} = render(<PinAdministrative subjectId="S1" location={ambiguous} canResolve={false}/>);
    expect(screen.queryByRole('button')).toBeNull();
    rerender(<PinAdministrative subjectId="S1" canResolve location={{...ambiguous, administrative: {state: 'RESOLVED_MANUALLY', unit: {name: 'MUNICIPIUL DEJ'}, county: {name: 'JUDEȚUL CLUJ'}, candidates: []}}}/>);
    expect(screen.getByText('MUNICIPIUL DEJ, JUDEȚUL CLUJ (ales manual)')).toBeInTheDocument();
    expect(screen.queryByRole('button')).toBeNull();
});

test('eroarea serverului apare lângă variante', async () => {
    resolvePinClassification.mockRejectedValue(new Error('Unitatea aleasă nu e printre variante.'));
    render(<PinAdministrative subjectId="S1" location={ambiguous} canResolve/>);
    fireEvent.click(screen.getByRole('button', {name: 'COMUNA CUZDRIOARA'}));
    expect(await screen.findByText('Unitatea aleasă nu e printre variante.')).toBeInTheDocument();
});
