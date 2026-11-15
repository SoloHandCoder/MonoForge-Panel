/** @vitest-environment jsdom */

import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import SupportImagePicker from './SupportImagePicker';

afterEach(cleanup);

function Harness() {
    const [files, setFiles] = useState<File[]>([]);

    return (
        <>
            <SupportImagePicker files={files} onChange={setFiles} />
            <output data-testid='picked'>{files.map((file) => file.name).join(',')}</output>
        </>
    );
}

const pick = (...files: File[]) => fireEvent.change(screen.getByTestId('support-image-input'), { target: { files } });
const image = (name: string, type = 'image/png') => new File(['x'], name, { type, lastModified: name.length });

describe('support image picker', () => {
    it('keeps images, refuses other files with a reason and stops at three', async () => {
        render(<Harness />);

        pick(image('a.png'), image('b.gif', 'image/gif'));
        expect(screen.getByTestId('picked').textContent).toBe('a.png');
        expect(screen.getByRole('alert').textContent).toContain('b.gif is not a PNG, JPEG or WebP image.');

        pick(image('bb.jpg', 'image/jpeg'), image('ccc.webp', 'image/webp'), image('dddd.png'));
        expect(screen.getByTestId('picked').textContent).toBe('a.png,bb.jpg,ccc.webp');
        expect(screen.getByRole('alert').textContent).toContain('up to 3 images');
        expect(screen.getByRole('button', { name: /Attach images/ })).toBeDisabled();

        await userEvent.setup().click(screen.getByRole('button', { name: 'Remove bb.jpg' }));
        expect(screen.getByTestId('picked').textContent).toBe('a.png,ccc.webp');
    });
});
