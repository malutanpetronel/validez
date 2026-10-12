import {render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import PrivacyPage from './PrivacyPage';

test('secțiunea despre voturi: marcată pentru verificare, fără promisiuni de anonimizare; ?section=voturi derulează', () => {
    const scroll = jest.fn();
    Element.prototype.scrollIntoView = scroll;
    render(<MemoryRouter initialEntries={['/privacy?section=voturi']}><PrivacyPage/></MemoryRouter>);
    const section = screen.getByRole('region', {name: 'Voturi'});
    expect(section).toHaveTextContent('Text de lucru, în curs de verificare de specialitate');
    expect(section).toHaveTextContent('Nu le prezentăm ca anonime');
    expect(screen.getByRole('heading', {name: 'Voturi'})).toBeInTheDocument();
    expect(scroll).toHaveBeenCalledTimes(1);
    expect(scroll.mock.instances[0]).toBe(section);
});
