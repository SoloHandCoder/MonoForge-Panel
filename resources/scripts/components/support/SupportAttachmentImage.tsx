import { ImageOff } from 'lucide-react';
import { useAttachmentObjectUrl, type SupportAttachment } from '@/api/support/queries';
import Icon from '@/components/elements/Icon';

interface Props {
    attachment: SupportAttachment;
    /** Fetches the image through the API, with the session. */
    load: (attachment: SupportAttachment) => Promise<Blob>;
}

const formatSize = (bytes: number): string =>
    bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/** A thumbnail of an attached image that opens the full image in a new tab. */
export default function SupportAttachmentImage({ attachment, load }: Props) {
    const { src, failed } = useAttachmentObjectUrl(String(attachment.id), () => load(attachment));
    const label = `${attachment.name}, ${attachment.width}×${attachment.height}, ${formatSize(attachment.size)}`;

    if (failed) {
        return (
            <span className='flex h-24 w-32 flex-col items-center justify-center gap-1 rounded-md border border-border bg-muted/40 text-xs text-muted-foreground'>
                <Icon icon={ImageOff} className='size-4' aria-hidden />
                Unavailable
            </span>
        );
    }

    return (
        <a
            href={src ?? undefined}
            target='_blank'
            rel='noopener noreferrer'
            title={label}
            aria-label={`Open ${label}`}
            className='block h-24 w-32 overflow-hidden rounded-md border border-border bg-muted/40 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary'
        >
            {src ? (
                <img src={src} alt={attachment.name} className='size-full object-cover' />
            ) : (
                <span className='block size-full animate-pulse motion-reduce:animate-none' />
            )}
        </a>
    );
}
