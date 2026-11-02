import {act, render} from '@testing-library/react';
import {createMemoryRouter, RouterProvider} from 'react-router-dom';
import useRestaurareScroll from './useRestaurareScroll';

function Pagina() {
    useRestaurareScroll();
    return null;
}

const renderAt = (path) => {
    const router = createMemoryRouter([{path: '*', element: <Pagina/>}], {initialEntries: [path]});
    render(<RouterProvider router={router}/>);
    return router;
};

beforeEach(() => {
    window.sessionStorage.clear();
    window.scrollTo.mockClear();
});

test('o pagina noua porneste de sus; paginarea (pastreazaScroll) si selectiile din arbore nu', async () => {
    const router = renderAt('/');
    await act(() => router.navigate('/?pagina=2', {state: {pastreazaScroll: true}}));
    await act(() => router.navigate('/categorie/drumuri'));
    await act(() => router.navigate('/categorie/drumuri/subiect/gropi', {replace: true}));
    expect(window.scrollTo).not.toHaveBeenCalled();

    await act(() => router.navigate('/help'));
    expect(window.scrollTo).toHaveBeenCalledWith(0, 0);
});

test('Inapoi reface pozitia salvata a intrarii din istoric', async () => {
    const router = renderAt('/?pagina=2');
    // Pagina e destul de inalta ca pozitia sa poata fi refacuta imediat.
    Object.defineProperty(document.documentElement, 'scrollHeight', {configurable: true, value: 5000});
    window.scrollY = 1200;
    window.dispatchEvent(new Event('scroll'));
    await act(() => new Promise((r) => requestAnimationFrame(r)));

    await act(() => router.navigate('/categorie/drumuri/subiect/gropi'));
    window.scrollTo.mockClear();
    await act(() => router.navigate(-1));
    expect(window.scrollTo).toHaveBeenCalledWith(0, 1200);
});
