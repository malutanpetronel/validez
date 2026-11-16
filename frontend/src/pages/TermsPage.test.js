import {render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import TermsPage from './TermsPage';

test('termenii: vârsta minimă, licența neexclusivă, nereprezentativitatea rezultatelor și link spre confidențialitate', () => {
    render(<MemoryRouter><TermsPage/></MemoryRouter>);
    expect(screen.getByRole('heading', {name: 'Termeni și condiții'})).toBeInTheDocument();
    expect(screen.getByText(/cel puțin 16 ani/)).toBeInTheDocument();
    expect(screen.getByText(/licență neexclusivă și gratuită/)).toBeInTheDocument();
    expect(screen.getByText(/nu sunt sondaje reprezentative statistic/)).toBeInTheDocument();
    expect(screen.getByRole('link', {name: 'Politica de confidențialitate'})).toHaveAttribute('href', '/privacy');
});
