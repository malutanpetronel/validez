import {act, render, screen} from '@testing-library/react';
import TreeVoteSummary from './TreeVoteSummary';
import {fetchVotes} from '../api/votes';

jest.mock('../api/votes', () => ({fetchVotes: jest.fn()}));

test('propunerea arată susținerile și voturile împotrivă și se actualizează după vot', async () => {
    const summary = (yes, no) => ({types: [{code: 'YES_NO', result: {counts: {1: yes, 0: no}, total: yes + no}}]});
    fetchVotes.mockResolvedValue(summary(3, 1));
    render(<TreeVoteSummary subjectId="proposal"/>);
    expect(await screen.findByLabelText('3 susțineri, 1 voturi împotrivă')).toBeInTheDocument();
    act(() => window.dispatchEvent(new CustomEvent('subject-votes-updated', {
        detail: {subjectId: 'proposal', summary: summary(4, 1)},
    })));
    expect(screen.getByLabelText('4 susțineri, 1 voturi împotrivă')).toBeInTheDocument();
});
