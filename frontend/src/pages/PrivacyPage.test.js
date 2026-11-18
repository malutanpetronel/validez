import {render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import PrivacyPage from './PrivacyPage';

test('secțiunea despre voturi: versiunea curentă, termene concrete, fără promisiuni de anonimizare; ?section=voturi derulează', () => {
    const scroll = jest.fn();
    Element.prototype.scrollIntoView = scroll;
    render(<MemoryRouter initialEntries={['/privacy?section=voturi']}><PrivacyPage/></MemoryRouter>);
    const section = screen.getByRole('region', {name: 'Voturi'});
    expect(section).toHaveTextContent('Versiunea din 13.10.2026');
    expect(section).not.toHaveTextContent('nu este activă');
    expect(section).toHaveTextContent('cel mult 30 de zile');
    expect(section).toHaveTextContent('cât timp subiectul și rezultatele lui sunt păstrate');
    expect(section).toHaveTextContent('Nu le prezentăm ca anonime');
    expect(screen.getByRole('heading', {name: 'Voturi'})).toBeInTheDocument();
    expect(scroll).toHaveBeenCalledTimes(1);
    expect(scroll.mock.instances[0]).toBe(section);
});

test('acoperă fotografiile, localizarea cu OpenStreetMap și sondajele', () => {
    render(<MemoryRouter><PrivacyPage/></MemoryRouter>);
    expect(screen.getByRole('heading', {name: 'Fotografii și linkuri'})).toBeInTheDocument();
    expect(screen.getByText(/se elimină toate metadatele/)).toBeInTheDocument();
    expect(screen.getByRole('heading', {name: 'Localizare'})).toBeInTheDocument();
    expect(screen.getByText(/OpenStreetMap Foundation, care primesc adresa IP/)).toBeInTheDocument();
    expect(screen.getByRole('region', {name: 'Voturi'})).toHaveTextContent('La un sondaj alegi o singură variantă');
});

test('ștergerea contului: link direct pentru Google Play, termen, contribuțiile rămân ca „Cont șters”', () => {
    const scroll = jest.fn();
    Element.prototype.scrollIntoView = scroll;
    render(<MemoryRouter initialEntries={['/privacy?section=stergere-cont']}><PrivacyPage/></MemoryRouter>);
    const heading = screen.getByRole('heading', {name: 'Ștergerea contului'});
    expect(scroll).toHaveBeenCalledTimes(1);
    expect(scroll.mock.instances[0]).toContainElement(heading);
    expect(screen.getByText(/rezolvăm cererea în cel mult 30 de zile/)).toHaveTextContent('autorul apare ca „Cont șters”');
    expect(screen.getByRole('heading', {name: 'Sugestii AI din fotografii'})).toBeInTheDocument();
});
