/** @vitest-environment jsdom */

import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SupportComposer from './SupportComposer';

afterEach(cleanup);

describe('support composer', () => {
    it('sends the message and clears it once sent', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn(() => Promise.resolve(true));

        render(<SupportComposer submitLabel='Send reply' onSubmit={onSubmit} />);

        const send = screen.getByRole('button', { name: 'Send reply' });

        expect(send).toBeDisabled();
        await user.type(screen.getByLabelText('Reply'), 'It still crashes');
        await user.click(send);

        expect(onSubmit).toHaveBeenCalledWith('It still crashes', []);
        expect(screen.getByLabelText('Reply')).toHaveValue('');
    });

    it('keeps the text when sending failed', async () => {
        const user = userEvent.setup();

        render(<SupportComposer submitLabel='Send reply' onSubmit={() => Promise.resolve(false)} />);

        await user.type(screen.getByLabelText('Reply'), 'Hello there');
        await user.click(screen.getByRole('button', { name: 'Send reply' }));

        expect(screen.getByLabelText('Reply')).toHaveValue('Hello there');
    });

    it('appends a canned reply each time one is picked', () => {
        const props = { submitLabel: 'Send reply', onSubmit: () => Promise.resolve(true) };
        const { rerender } = render(<SupportComposer {...props} insert={{ key: 1, text: 'Restart first.' }} />);

        expect(screen.getByLabelText('Reply')).toHaveValue('Restart first.');

        rerender(<SupportComposer {...props} insert={{ key: 2, text: 'Then check the logs.' }} />);
        expect(screen.getByLabelText('Reply')).toHaveValue('Restart first.\n\nThen check the logs.');
    });
});
