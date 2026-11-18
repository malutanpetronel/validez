import {fireEvent, render, screen} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import AiSettingsPage from './AiSettingsPage';
import {AuthProvider} from '../auth/AuthContext';
import {setSession} from '../auth/session';
import {adminJwt} from '../testUtils/fakeJwt';
import {res, routedFetch} from '../testUtils/subjects';

const settings = (over = {}) => ({
    enabled: false, provider: 'anthropic', models: {anthropic: 'claude-haiku-5-5', openai: 'gpt-6-luna'}, keys: {anthropic: true, openai: false},
    prompt: 'Prompt implicit {limba}', defaultPrompt: 'Prompt implicit {limba}', isDefaultPrompt: true, language: 'română',
    modelPrices: {'claude-haiku-5-5': {input: 0.1, output: 0.5}}, dailyLimitPerAdmin: 30, dailyRequestsCap: 30, dailyUsdCap: '1.00', monthlyUsdCap: '10.00',
    usage: {todayRequests: 2, todayUsd: 0.0004, monthUsd: 0.01, mineToday: 2}, updatedAt: '2026-11-18T10:00:00+00:00', ...over,
});

test('admin: cheile ca stare, salvarea trimite promptul implicit ca null și limitele ca numere', async () => {
    window.localStorage.clear();
    setSession({token: adminJwt()});
    global.fetch = routedFetch([
        [(u, o) => o.method === 'PATCH', (u, o) => res(settings({...JSON.parse(o.body), prompt: 'Prompt implicit {limba}'}))],
        [(u) => u.endsWith('/admin/ai-settings'), () => res(settings())],
    ]);
    render(<AuthProvider><MemoryRouter><AiSettingsPage/></MemoryRouter></AuthProvider>);
    expect(await screen.findByText(/Anthropic \(Claude\) · claude-haiku-5-5 · cheie configurată ✓/)).toBeInTheDocument();
    expect(screen.getByText(/OpenAI \(ChatGPT\) · gpt-6-luna · cheie lipsă/)).toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Sugestiile AI sunt oprite'));
    fireEvent.change(screen.getByLabelText('Cereri pe zi per admin'), {target: {value: '12'}});
    fireEvent.click(screen.getByRole('button', {name: 'Salvează'}));
    expect(await screen.findByText('Setările au fost salvate.')).toBeInTheDocument();
    const [, patch] = global.fetch.mock.calls.find(([, o]) => o?.method === 'PATCH');
    expect(JSON.parse(patch.body)).toMatchObject({enabled: true, prompt: null, dailyLimitPerAdmin: 12, modelPrices: {'claude-haiku-5-5': {input: 0.1, output: 0.5}}});
    expect(await screen.findByText('Sugestiile AI sunt pornite')).toBeInTheDocument();
});
