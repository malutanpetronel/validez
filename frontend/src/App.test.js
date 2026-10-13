import {render, screen} from '@testing-library/react';
import App from './App';

beforeEach(() => {
    global.fetch = jest.fn(() => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({member: [
            {id: '01AAAAAAAAAAAAAAAAAAAAAAAA', name: 'Drumuri', hasChildren: false, parentId: null},
        ]}),
    }));
});

afterEach(() => { delete window.cordova; window.history.replaceState(null, '', '/'); });

test('afiseaza brandul si arborele pe pagina principala (web: URL-uri reale)', async () => {
    render(<App/>);
    expect(screen.getByRole('link', {name: 'VALIDEZ — pagina principală'})).toHaveAttribute('href', '/');
    expect(screen.queryByRole('link', {name: 'Arbore'})).not.toBeInTheDocument();
    expect(await screen.findByText('Drumuri')).toBeInTheDocument();
});

test('in Cordova routerul ramane pe hash', async () => {
    window.cordova = {};
    render(<App/>);
    expect(screen.getByRole('link', {name: 'VALIDEZ — pagina principală'})).toHaveAttribute('href', '#/');
    expect(await screen.findByText('Drumuri')).toBeInTheDocument();
});
