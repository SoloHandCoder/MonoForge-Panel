import { useId, useRef, useState } from 'react';
import { ImagePlus, X } from 'lucide-react';
import { SUPPORT_MAX_IMAGES, supportImageProblem } from '@/api/support/queries';
import Button from '@/components/elements/Button';
import Icon from '@/components/elements/Icon';

const fileKey = (file: File): string => `${file.name}:${file.size}:${file.lastModified}`;

interface Props {
    files: File[];
    onChange: (files: File[]) => void;
    disabled?: boolean;
}

/**
 * Picks up to three PNG, JPEG or WebP images of up to 5 MB. Wrong files are refused here with a
 * reason; the server checks the bytes again either way.
 */
export default function SupportImagePicker({ files, onChange, disabled }: Props) {
    const input = useRef<HTMLInputElement>(null);
    const errorId = useId();
    const [problem, setProblem] = useState<string | null>(null);

    const add = (picked: FileList | null) => {
        const chosen = [...(picked ?? [])].filter(
            (file) => !files.some((existing) => fileKey(existing) === fileKey(file))
        );
        const rejected = chosen.map(supportImageProblem).find((reason) => reason !== null) ?? null;
        const accepted = chosen.filter((file) => supportImageProblem(file) === null);
        const next = [...files, ...accepted].slice(0, SUPPORT_MAX_IMAGES);

        setProblem(
            rejected ??
                (files.length + accepted.length > SUPPORT_MAX_IMAGES
                    ? `You can attach up to ${SUPPORT_MAX_IMAGES} images.`
                    : null)
        );
        onChange(next);

        if (input.current) {
            input.current.value = '';
        }
    };

    return (
        <div>
            <input
                ref={input}
                type='file'
                accept='image/png,image/jpeg,image/webp'
                multiple
                className='sr-only'
                tabIndex={-1}
                aria-hidden
                data-testid='support-image-input'
                onChange={(event) => add(event.target.files)}
            />
            <div className='flex flex-wrap items-center gap-2'>
                <Button.Text
                    type='button'
                    size='small'
                    isSecondary
                    disabled={disabled || files.length >= SUPPORT_MAX_IMAGES}
                    onClick={() => input.current?.click()}
                    aria-describedby={problem ? errorId : undefined}
                >
                    <Icon icon={ImagePlus} className='mr-1.5 size-4' aria-hidden />
                    Attach images
                </Button.Text>
                {files.map((file, index) => (
                    <span
                        key={fileKey(file)}
                        className='inline-flex max-w-56 items-center gap-1 rounded-md border border-border bg-muted/40 py-0.5 pr-0.5 pl-2 text-xs'
                    >
                        <span className='truncate'>{file.name}</span>
                        <button
                            type='button'
                            className='rounded p-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-primary'
                            aria-label={`Remove ${file.name}`}
                            disabled={disabled}
                            onClick={() => onChange(files.filter((_, position) => position !== index))}
                        >
                            <Icon icon={X} className='size-3.5' aria-hidden />
                        </button>
                    </span>
                ))}
            </div>
            <p
                id={errorId}
                className={problem ? 'input-help error' : 'input-help'}
                role={problem ? 'alert' : undefined}
            >
                {problem ?? `Up to ${SUPPORT_MAX_IMAGES} PNG, JPEG or WebP images, 5 MB each.`}
            </p>
        </div>
    );
}
