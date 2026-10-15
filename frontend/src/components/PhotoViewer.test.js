import {useState} from 'react';
import {fireEvent, render, screen} from '@testing-library/react';
import PhotoViewer from './PhotoViewer';

const photos = [1, 2, 3].map((n) => ({id: `p${n}`, kind: 'PHOTO', status: 'PUBLISHED', createdAt: '2026-10-15T10:00:00+00:00',
    displayUrl: `/api/media/p${n}/display`, thumbUrl: `/api/media/p${n}/thumb`}));

function Harness({start = 0, list = photos}) {
    const [index, setIndex] = useState(start);
    return <PhotoViewer photos={list} index={index} onIndex={setIndex} onClose={() => setIndex(null)} isPublic={() => true} title="Groapă"/>;
}

const counter = () => screen.getByText(/\d \/ 3 ·/).textContent.slice(0, 5);
const shown = () => screen.getByRole('img').getAttribute('src');

test('următoarea/anterioara cu butoanele și cu tastatura, circular', () => {
    render(<Harness/>);
    expect(counter()).toBe('1 / 3');
    expect(shown()).toMatch(/p1\/display$/);
    fireEvent.click(screen.getByLabelText('Fotografia următoare'));
    expect(counter()).toBe('2 / 3');
    expect(shown()).toMatch(/p2\/display$/);
    fireEvent.keyDown(window, {key: 'ArrowRight'});
    fireEvent.keyDown(window, {key: 'ArrowRight'});
    expect(counter()).toBe('1 / 3');
    fireEvent.click(screen.getByLabelText('Fotografia anterioară'));
    expect(counter()).toBe('3 / 3');
    fireEvent.keyDown(window, {key: 'ArrowLeft'});
    expect(counter()).toBe('2 / 3');
});

test('o singură fotografie: fără săgeți și fără contor', () => {
    render(<Harness list={[photos[0]]}/>);
    expect(screen.queryByLabelText('Fotografia următoare')).toBeNull();
    expect(screen.queryByText(/1 \/ 1/)).toBeNull();
});
