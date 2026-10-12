import {fireEvent, render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import CookieNotice from './CookieNotice';

beforeEach(() => localStorage.clear());
const show = () => render(<MemoryRouter><CookieNotice/></MemoryRouter>);
test('informarea poate fi închisă, rămâne închisă la revenire și se poate redeschide', () => {
    const {unmount} = show();
    expect(screen.getByRole('region', {name: 'Informații despre cookie-uri'})).toBeInTheDocument();
    expect(screen.getByRole('link', {name: 'Confidențialitate'})).toHaveAttribute('href', '/privacy');
    fireEvent.click(screen.getByRole('button', {name: 'Am înțeles'}));
    unmount();
    show();
    expect(screen.queryByRole('region')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', {name: 'Informații despre cookie-uri'}));
    expect(screen.getByRole('region')).toBeInTheDocument();
});
test('stocarea indisponibilă nu blochează pagina sau închiderea informării', () => {
    const read = jest.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
    const write = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
    show();
    fireEvent.click(screen.getByRole('button', {name: 'Am înțeles'}));
    expect(screen.queryByRole('region')).not.toBeInTheDocument();
    read.mockRestore();
    write.mockRestore();
});
