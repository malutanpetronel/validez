import {fireEvent, render, screen} from '@testing-library/react';
import AltchaVerification from './AltchaVerification';

test('evenimentul verified transmite payloadul neschimbat; resetarea îl golește', () => {
    const onVerified = jest.fn();
    const {unmount} = render(<AltchaVerification onVerified={onVerified}/>);
    const widget = screen.getByLabelText('Verificare ALTCHA');
    expect(widget).toHaveAttribute('challenge', expect.stringMatching(/\/api\/captcha\/challenge$/));
    fireEvent(widget, new CustomEvent('statechange', {detail: {state: 'verified', payload: 'payload-base64'}}));
    expect(onVerified).toHaveBeenLastCalledWith('payload-base64');
    fireEvent(widget, new CustomEvent('statechange', {detail: {state: 'unverified'}}));
    expect(onVerified).toHaveBeenLastCalledWith('');
    onVerified.mockClear(); unmount();
    fireEvent(widget, new CustomEvent('statechange', {detail: {state: 'verified', payload: 'tardiv'}}));
    expect(onVerified).not.toHaveBeenCalled();
});
