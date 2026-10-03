import {render, screen} from '@testing-library/react';
import App from './App';

beforeEach(() => {
    global.fetch = jest.fn(() => Promise.resolve({ok: true, status: 200}));
});

test('afiseaza brandul si starea API', async () => {
    render(<App/>);
    expect(screen.getByRole('link', {name: 'VALIDEZ — pagina principală'})).toHaveAttribute('href', '#/');
    expect(await screen.findByText(/API disponibil/)).toBeInTheDocument();
});
