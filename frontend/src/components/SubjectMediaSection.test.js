import {render, screen, waitFor} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SubjectMediaSection from './SubjectMediaSection';
import {reorderPhotos} from '../api/media';

jest.mock('../api/media');

const subject = {id: 'S1', title: 'Groapa', visibility: 'PUBLISHED'};
const photo = (id, status = 'PUBLISHED') => ({id, kind: 'PHOTO', status, thumbUrl: `/api/media/${id}/thumb`, displayUrl: `/api/media/${id}/display`});

function setup({canManage = true, photos = [photo('A'), photo('B'), photo('C')]} = {}) {
    const reload = jest.fn();
    const media = {data: {items: photos, max: 10, maxLinks: 10}, items: photos, photos, links: [], version: 0, reload, error: null};
    render(<SubjectMediaSection subject={subject} canManage={canManage} media={media} viewer={null} onViewer={() => {}}/>);
    return {reload};
}

beforeEach(() => {
    reorderPhotos.mockReset().mockResolvedValue({items: []});
});

test('„Fă copertă” trimite fotografia pe primul loc, apoi reîncarcă lista', async () => {
    const {reload} = setup();
    await userEvent.click(screen.getByRole('button', {name: 'Fă fotografia 3 copertă'}));
    expect(reorderPhotos).toHaveBeenCalledWith('S1', ['C', 'A', 'B']);
    await waitFor(() => expect(reload).toHaveBeenCalled());
});

test('săgețile mută cu o poziție; capetele sunt dezactivate', async () => {
    setup();
    expect(screen.getByRole('button', {name: 'Mută fotografia 1 înainte'})).toBeDisabled();
    expect(screen.getByRole('button', {name: 'Fă fotografia 1 copertă'})).toBeDisabled();
    expect(screen.getByRole('button', {name: 'Mută fotografia 3 după'})).toBeDisabled();
    await userEvent.click(screen.getByRole('button', {name: 'Mută fotografia 1 după'}));
    expect(reorderPhotos).toHaveBeenCalledWith('S1', ['B', 'A', 'C']);
});

test('eroarea serverului apare, iar ordinea rămâne cea de pe server', async () => {
    reorderPhotos.mockRejectedValue(new Error('Lista fotografiilor s-a schimbat între timp. Reîncarcă pagina.'));
    const {reload} = setup();
    await userEvent.click(screen.getByRole('button', {name: 'Mută fotografia 2 înainte'}));
    expect(await screen.findByText('Lista fotografiilor s-a schimbat între timp. Reîncarcă pagina.')).toBeInTheDocument();
    expect(reload).not.toHaveBeenCalled();
});

test('fără drept de modificare sau cu o singură fotografie nu apar controale de ordonare', () => {
    setup({canManage: false});
    expect(screen.queryByRole('button', {name: /copertă/})).not.toBeInTheDocument();
});

test('o singură fotografie: nimic de ordonat', () => {
    setup({photos: [photo('A')]});
    expect(screen.queryByRole('button', {name: /copertă/})).not.toBeInTheDocument();
    expect(screen.queryByText('Copertă')).not.toBeInTheDocument();
});
