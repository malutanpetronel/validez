import {render, screen} from '@testing-library/react';
import App from './App';

beforeEach(() => {
    global.fetch = jest.fn(() => Promise.resolve({ok: true, status: 200}));
});

test('afiseaza brandul si starea API', async () => {
    render(<App/>);
    expect(screen.getByText('VALIDEZ')).toBeInTheDocument();
    expect(await screen.findByText(/API disponibil/)).toBeInTheDocument();
});
