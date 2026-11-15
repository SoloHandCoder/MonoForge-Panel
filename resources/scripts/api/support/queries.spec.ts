import { describe, expect, it } from 'vitest';
import { openSupportTicketInput, replySupportTicketInput, supportImageProblem } from './queries';

const file = (name: string, type: string, size = 10): File => new File([new Uint8Array(size)], name, { type });

const serialize = (
    input: ReturnType<typeof openSupportTicketInput> | ReturnType<typeof replySupportTicketInput>
): FormData => {
    const data = input.bodySerializer?.(input.body);

    if (!(data instanceof FormData)) {
        throw new TypeError('The serializer did not build a form.');
    }

    return data;
};

describe('support inputs', () => {
    it('sends the images as attachments[] so PHP reads them as a list', () => {
        const images = [file('a.png', 'image/png'), file('b.jpg', 'image/jpeg')];
        const data = serialize(
            openSupportTicketInput({
                subject: ' Help ',
                categoryId: 2,
                server: '1a7ce997',
                body: ' Broken ',
                attachments: images,
            })
        );

        expect(data.get('subject')).toBe('Help');
        expect(data.get('category_id')).toBe('2');
        expect(data.get('server')).toBe('1a7ce997');
        expect(data.get('body')).toBe('Broken');
        expect(data.getAll('attachments[]').map((entry) => (entry instanceof File ? entry.name : entry))).toEqual([
            'a.png',
            'b.jpg',
        ]);
        expect(data.has('attachments')).toBe(false);
    });

    it('leaves the server out when the ticket is not about one', () => {
        const data = serialize(
            openSupportTicketInput({ subject: 'Help', categoryId: 1, server: null, body: 'Broken', attachments: [] })
        );

        expect(data.has('server')).toBe(false);
        expect(data.getAll('attachments[]')).toEqual([]);
    });

    it('builds a reply with its images', () => {
        const input = replySupportTicketInput(12, 'More', [file('c.webp', 'image/webp')]);

        expect(input.path).toEqual({ ticket: 12 });
        expect(serialize(input).getAll('attachments[]')).toHaveLength(1);
    });

    it('refuses files that are not small PNG, JPEG or WebP images', () => {
        expect(supportImageProblem(file('a.png', 'image/png'))).toBeNull();
        expect(supportImageProblem(file('a.gif', 'image/gif'))).toContain('not a PNG');
        expect(supportImageProblem(file('a.svg', 'image/svg+xml'))).toContain('not a PNG');
        expect(supportImageProblem(file('big.png', 'image/png', 5 * 1024 * 1024 + 1))).toContain('larger than 5 MB');
    });
});
