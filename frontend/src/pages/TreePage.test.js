import {render, screen} from '@testing-library/react';
import TreePage from './TreePage';

beforeEach(() => {
    global.fetch = jest.fn(() => Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({member: [
            {id: '01AAAAAAAAAAAAAAAAAAAAAAAA', name: 'Drumuri', hasChildren: true, parentId: null},
            {id: '01BBBBBBBBBBBBBBBBBBBBBBBB', name: 'Sănătate', hasChildren: false, parentId: null},
        ]}),
    }));
});

test('incarca radacinile si dezactiveaza actiunile pe nod pana la selectie', async () => {
    render(<TreePage/>);
    expect(await screen.findByText('Drumuri')).toBeInTheDocument();
    expect(screen.getByText('Sănătate')).toBeInTheDocument();
    expect(global.fetch).toHaveBeenCalledWith(expect.stringMatching(/\/api\/tree_nodes$/), expect.anything());
    expect(screen.getByRole('button', {name: /Adaugă copil/})).toBeDisabled();
    expect(screen.getByRole('button', {name: /Rădăcină nouă/})).toBeEnabled();
});
