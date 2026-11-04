import {act, fireEvent, render, screen} from '@testing-library/react';
import TreeVoteSummary, {votesLabel} from './TreeVoteSummary';
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

test('acordul numeralului cu „voturi”', () => {
    expect([0, 1, 2, 19, 20, 100, 101, 119, 120].map(votesLabel)).toEqual(
        ['0 voturi', '1 vot', '2 voturi', '19 voturi', '20 de voturi', '100 de voturi', '101 voturi', '119 voturi', '120 de voturi']);
});

test('rândul de sub titlu: medie, număr de voturi, îndemn; clicul duce la vot', async () => {
    fetchVotes.mockResolvedValue({votingOpen: true, types: [
        {code: 'IMPORTANCE', result: {counts: {4: 1, 5: 1}, total: 2}},
        {code: 'VALIDITY', result: {counts: {1: 2}, total: 2}},
    ]});
    const onVote = jest.fn();
    render(<TreeVoteSummary subjectId="s1" variant="header" onVote={onVote}/>);
    const row = await screen.findByTestId('subject-vote-header');
    expect(row).toHaveTextContent('4,5/5');
    expect(row).toHaveTextContent('2 voturi');
    expect(row).toHaveTextContent('Votează');
    fireEvent.click(row);
    expect(onVote).toHaveBeenCalled();
});

test('rândul de sub titlu fără voturi îndeamnă la primul vot', async () => {
    fetchVotes.mockResolvedValue({votingOpen: true, types: [{code: 'IMPORTANCE', result: {counts: {}, total: 0}}]});
    render(<TreeVoteSummary subjectId="s2" variant="header" onVote={() => {}}/>);
    expect(await screen.findByTestId('subject-vote-header')).toHaveTextContent('Fii primul care votează');
});
