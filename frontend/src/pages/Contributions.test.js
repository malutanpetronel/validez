import {fireEvent, render, screen, waitFor} from '@testing-library/react';
import {MemoryRouter, Route, Routes} from 'react-router-dom';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt, userJwt} from '../testUtils/fakeJwt';
import HelpPage from './HelpPage';
import ModerationPage from './ModerationPage';
import CategorySuggestionsPage from './CategorySuggestionsPage';
import AuthorPublishing from '../components/AuthorPublishing';
import {fetchChildren} from '../api/tree';
import {fetchSubjects, updateSubject} from '../api/subjects';
import {fetchCategorySuggestions, reviewCategory, suggestCategory, publishingStatus, setPublishingRevoked} from '../api/contributions';

jest.mock('../api/tree');
jest.mock('../api/subjects');
jest.mock('../api/contributions');

const mount = (element) => render(<AuthProvider><MemoryRouter initialEntries={['/test']}><Routes>
    <Route path="/test" element={element}/>
    <Route path="/" element={<div>Pagina principală</div>}/>
    <Route path="/login" element={<div>Autentificare</div>}/>
</Routes></MemoryRouter></AuthProvider>);

beforeEach(() => {
    window.localStorage.clear();
    jest.resetAllMocks();
    fetchChildren.mockResolvedValue([{id: 'ROOT', name: 'Drumuri'}]);
    fetchCategorySuggestions.mockResolvedValue({items: [], total: 0});
    fetchSubjects.mockResolvedValue({items: [{id: 'SUBJECT', title: 'Gropi', description: 'Descriere', authorName: 'Ion', nodeName: 'Drumuri'}], total: 1});
    updateSubject.mockResolvedValue({});
    suggestCategory.mockResolvedValue({id: 'SUGGESTION', status: 'PENDING'});
    reviewCategory.mockResolvedValue({});
});

test('Help explică aprobările, dreptul revocabil și propunerile de categorii', () => {
    setSession({token: userJwt()});
    mount(<HelpPage/>);
    expect(screen.getByText(/Primele trei subiecte trebuie aprobate/)).toBeInTheDocument();
    expect(screen.getByText(/Administratorul poate retrage acest drept/)).toBeInTheDocument();
    expect(screen.getByText(/Poți propune categorii noi, inclusiv rădăcini/)).toBeInTheDocument();
    expect(screen.getByRole('link', {name: 'Propune o categorie'})).toHaveAttribute('href', '/categorii-propuse');
});

test('utilizatorul trimite propunerea sub părintele ales fără să creeze un nod', async () => {
    setSession({token: userJwt()});
    mount(<CategorySuggestionsPage/>);
    await waitFor(() => expect(screen.getByLabelText('Caută categoria părinte')).not.toHaveAttribute('aria-disabled', 'true'));
    fireEvent.mouseDown(screen.getByLabelText('Caută categoria părinte'));
    fireEvent.click(await screen.findByRole('option', {name: 'Drumuri'}));
    await waitFor(() => expect(fetchChildren).toHaveBeenCalledWith('ROOT'));
    await waitFor(() => expect(screen.getByRole('button', {name: 'Un nivel mai sus'})).toBeEnabled());
    fireEvent.change(screen.getByLabelText('Numele categoriei'), {target: {value: 'Transport'}});
    fireEvent.change(screen.getByLabelText('De ce este necesară'), {target: {value: 'Categorie utilă'}});
    await waitFor(() => expect(screen.getByRole('button', {name: 'Trimite propunerea'})).toBeEnabled());
    fireEvent.click(screen.getByRole('button', {name: 'Trimite propunerea'}));
    expect(await screen.findByText('Propunerea a fost trimisă administratorilor.')).toBeInTheDocument();
    expect(suggestCategory).toHaveBeenCalledWith({name: 'Transport', reason: 'Categorie utilă', parent: 'ROOT'});
});

test('administratorul aprobă categoria iar utilizatorul obișnuit nu vede acțiunile', async () => {
    fetchCategorySuggestions.mockResolvedValue({items: [{id: 'SUGGESTION', name: 'Transport', reason: 'Motiv', status: 'PENDING', parentName: 'Drumuri', authorName: 'Ion'}], total: 1});
    setSession({token: adminJwt()});
    const {unmount} = mount(<CategorySuggestionsPage/>);
    fireEvent.click(await screen.findByRole('button', {name: 'Aprobă și creează categoria'}));
    await waitFor(() => expect(reviewCategory).toHaveBeenCalledWith('SUGGESTION', 'APPROVED'));
    await waitFor(() => expect(screen.getByRole('button', {name: 'Aprobă și creează categoria'})).toBeEnabled());
    unmount();
    setSession({token: userJwt()});
    mount(<CategorySuggestionsPage/>);
    await screen.findByText('Transport');
    expect(screen.queryByRole('button', {name: 'Aprobă și creează categoria'})).not.toBeInTheDocument();
});

test('administratorul publică un subiect în așteptare', async () => {
    setSession({token: adminJwt()});
    mount(<ModerationPage/>);
    await waitFor(() => expect(screen.getByRole('button', {name: 'Aprobă și publică'})).toBeEnabled());
    expect(fetchSubjects).toHaveBeenCalledWith({visibility: 'PENDING', page: 1});
    fireEvent.click(screen.getByRole('button', {name: 'Aprobă și publică'}));
    await waitFor(() => expect(updateSubject).toHaveBeenCalledWith('SUBJECT', {visibility: 'PUBLISHED'}));
    await waitFor(() => expect(fetchSubjects).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.getByRole('button', {name: 'Aprobă și publică'})).toBeEnabled());
});

test('coada de moderare nu este încărcată pentru utilizatorul obișnuit', () => {
    setSession({token: userJwt()});
    mount(<ModerationPage/>);
    expect(fetchSubjects).not.toHaveBeenCalled();
    expect(screen.getByText('Pagina principală')).toBeInTheDocument();
});

test('administratorul poate retrage și ridica restricția autorului', async () => {
    publishingStatus.mockResolvedValue({approvedCount: 3, canPublishDirectly: true, revoked: false});
    setPublishingRevoked.mockResolvedValueOnce({approvedCount: 3, canPublishDirectly: false, revoked: true})
        .mockResolvedValueOnce({approvedCount: 3, canPublishDirectly: true, revoked: false});
    render(<AuthorPublishing authorId="AUTHOR"/>);
    fireEvent.click(screen.getByRole('button', {name: 'Drepturi de publicare ale autorului'}));
    fireEvent.click(await screen.findByRole('button', {name: 'Retrage dreptul de publicare directă'}));
    expect(await screen.findByText(/Publicarea necesită aprobare/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Ridică restricția de publicare'}));
    expect(await screen.findByText(/Poate publica direct/)).toBeInTheDocument();
    expect(setPublishingRevoked.mock.calls).toEqual([['AUTHOR', true], ['AUTHOR', false]]);
});

test('o eroare de moderare păstrează subiectul și permite reîncercarea', async () => {
    updateSubject.mockRejectedValueOnce(new Error('Aprobarea nu a putut fi salvată.'));
    setSession({token: adminJwt()});
    mount(<ModerationPage/>);
    await waitFor(() => expect(screen.getByRole('button', {name: 'Respinge'})).toBeEnabled());
    fireEvent.click(screen.getByRole('button', {name: 'Respinge'}));
    expect(await screen.findByText('Aprobarea nu a putut fi salvată.')).toBeInTheDocument();
    expect(screen.getByText('Gropi')).toBeInTheDocument();
    expect(screen.getByRole('button', {name: 'Respinge'})).toBeEnabled();
});
