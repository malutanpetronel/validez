import {act, renderHook} from '@testing-library/react';
import useAutomaticTheme from './useAutomaticTheme';
import {automaticMode} from './theme';

beforeEach(() => {
    localStorage.removeItem('validez-theme');
    jest.useFakeTimers();
});

afterEach(() => jest.useRealTimers());

test.each([[6, 59, 'dark'], [7, 0, 'light'], [18, 59, 'light'], [19, 0, 'dark']])(
    'tema automată la %s:%s este %s', (hour, minute, expected) => {
        expect(automaticMode(new Date(2026, 9, 3, hour, minute))).toBe(expected);
    }
);

test('trece automat la nocturn și păstrează alegerea manuală după remontare', () => {
    jest.setSystemTime(new Date(2026, 9, 3, 18, 59, 45));
    const {result, unmount} = renderHook(useAutomaticTheme);
    expect(result.current.mode).toBe('light');
    act(() => jest.advanceTimersByTime(30000));
    expect(result.current.mode).toBe('dark');
    act(() => result.current.chooseTheme('light'));
    expect(result.current.mode).toBe('light');
    expect(localStorage.getItem('validez-theme')).toBe('light');
    unmount();
    const restored = renderHook(useAutomaticTheme);
    expect(restored.result.current.mode).toBe('light');
    act(() => restored.result.current.chooseTheme('auto'));
    expect(restored.result.current.mode).toBe('dark');
});

test('recalculează ora când aplicația mobilă revine din fundal', () => {
    jest.setSystemTime(new Date(2026, 9, 3, 6));
    const {result} = renderHook(useAutomaticTheme);
    expect(result.current.mode).toBe('dark');
    jest.setSystemTime(new Date(2026, 9, 3, 8));
    act(() => document.dispatchEvent(new Event('resume')));
    expect(result.current.mode).toBe('light');
});
