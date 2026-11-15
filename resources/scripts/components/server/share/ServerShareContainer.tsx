import { ExternalLink, ImagePlus, Link2, Palette, Trash2 } from 'lucide-react';
import { type FormEvent, useId, useState } from 'react';
import { httpValidationErrors, type ValidationErrors } from '@/api/http';
import { useCurrentServerName, useCurrentServerUuid } from '@/api/server/queries';
import {
    BANNER_MAX_BYTES,
    BANNER_TYPES,
    DESCRIPTION_MAX,
    SLUG_MAX,
    deleteServerPageBannerInput,
    saveServerPageInput,
    slugProblem,
    suggestSlug,
    uploadServerPageBannerInput,
    useDeleteServerPageBanner,
    useSaveServerPage,
    useServerPage,
    useUploadServerPageBanner,
    type ServerPageSettings,
} from '@/api/server/page/queries';
import { Alert } from '@/components/elements/alert';
import Button from '@/components/elements/Button';
import Card from '@/components/elements/Card';
import CopyOnClick from '@/components/elements/CopyOnClick';
import Label from '@/components/elements/Label';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { FileInput, TextArea, TextInput } from '@/components/form/controls';
import Switch from '@/components/ui/Switch';
import { notifyHttpError } from '@/plugins/notifications';
import Markdown from '@/lib/markdown';

/** What the colour picker shows before the owner picks one: the brand violet the page falls back to. */
// oxlint-disable-next-line rules/design-tokens -- a colour input needs a literal hex value
const DEFAULT_COLOR = '#8b5cf6';

interface Draft {
    slug: string;
    published: boolean;
    noindex: boolean;
    description: string;
    color: string | null;
}

const draftFrom = (settings: ServerPageSettings, serverName: string): Draft => ({
    slug: settings.slug ?? suggestSlug(serverName),
    published: settings.published,
    noindex: settings.noindex,
    description: settings.description,
    color: settings.banner_color,
});

/** A client-side check of a chosen banner, before the panel checks its content. */
export const bannerProblem = (file: File): string | null => {
    if (!(BANNER_TYPES as readonly string[]).includes(file.type)) {
        return 'Choose a PNG, JPEG or WebP image.';
    }

    return file.size > BANNER_MAX_BYTES ? 'The image must be 1 MB or smaller.' : null;
};

function FieldError({ id, message }: { id: string; message?: string | null }) {
    return message ? (
        <p id={id} className='input-help error'>
            {message}
        </p>
    ) : null;
}

function BannerCard({ uuid, settings }: { uuid: string; settings: ServerPageSettings }) {
    const upload = useUploadServerPageBanner(uuid);
    const remove = useDeleteServerPageBanner(uuid);
    const [problem, setProblem] = useState<string | null>(null);
    const inputId = useId();

    return (
        <Card title='Banner image' icon={ImagePlus} loading={upload.isPending || remove.isPending}>
            {settings.exists ? (
                <div className='flex flex-col gap-3'>
                    {settings.banner_image_url && (
                        <img
                            src={settings.banner_image_url}
                            alt='Current banner'
                            className='h-28 w-full rounded-lg border border-border object-cover'
                        />
                    )}
                    <Label htmlFor={inputId}>PNG, JPEG or WebP, up to 1 MB</Label>
                    <FileInput
                        id={inputId}
                        accept={BANNER_TYPES.join(',')}
                        aria-describedby={problem ? `${inputId}-error` : undefined}
                        onChange={(event) => {
                            const file = event.currentTarget.files?.[0];

                            event.currentTarget.value = '';

                            if (!file) {
                                return;
                            }

                            const reason = bannerProblem(file);

                            setProblem(reason);

                            if (reason === null) {
                                upload.mutate(uploadServerPageBannerInput(uuid, file));
                            }
                        }}
                    />
                    <FieldError id={`${inputId}-error`} message={problem} />
                    {settings.banner_image_url && (
                        <div>
                            <Button.Danger
                                size='small'
                                type='button'
                                onClick={() => remove.mutate(deleteServerPageBannerInput(uuid))}
                            >
                                <Trash2 aria-hidden className='mr-1.5 inline size-4' />
                                Remove image
                            </Button.Danger>
                        </div>
                    )}
                </div>
            ) : (
                <p className='text-sm text-muted-foreground'>Save the page first, then add a banner image.</p>
            )}
        </Card>
    );
}

function PageForm({ uuid, settings, serverName }: { uuid: string; settings: ServerPageSettings; serverName: string }) {
    const save = useSaveServerPage(uuid);
    const [draft, setDraft] = useState<Draft>(() => draftFrom(settings, serverName));
    const [errors, setErrors] = useState<ValidationErrors>({});
    const [preview, setPreview] = useState(false);
    const ids = { slug: useId(), description: useId(), color: useId() };
    const slugError = errors.slug ?? (draft.slug === '' ? null : slugProblem(draft.slug));
    const origin = window.location.origin;

    const change = (patch: Partial<Draft>) => setDraft((current) => ({ ...current, ...patch }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setErrors({});
        save.mutate(
            saveServerPageInput(uuid, {
                slug: draft.slug,
                published: draft.published,
                noindex: draft.noindex,
                description: draft.description,
                // An empty colour is stored as none, which shows the panel's default.
                banner_color: draft.color ?? '',
            }),
            {
                onError: (error) => {
                    const fieldErrors = httpValidationErrors(error);

                    setErrors(fieldErrors);

                    if (Object.keys(fieldErrors).length === 0) {
                        notifyHttpError(error, 'Unable to save the public page');
                    }
                },
            }
        );
    };

    return (
        <form onSubmit={submit} className='flex flex-col gap-6' noValidate>
            <Card title='Address' icon={Link2}>
                <div className='flex flex-col gap-5'>
                    <div>
                        <Label htmlFor={ids.slug}>Page address</Label>
                        <div className='flex items-stretch'>
                            <span className='hidden items-center rounded-l-md border border-r-0 border-border bg-muted px-3 text-sm text-muted-foreground sm:flex'>
                                {origin}/s/
                            </span>
                            <TextInput
                                id={ids.slug}
                                value={draft.slug}
                                maxLength={SLUG_MAX}
                                autoComplete='off'
                                spellCheck={false}
                                $hasError={!!slugError}
                                aria-invalid={!!slugError}
                                aria-describedby={`${ids.slug}-help`}
                                className='sm:rounded-l-none'
                                onChange={(event) => change({ slug: event.currentTarget.value.toLowerCase() })}
                            />
                        </div>
                        {slugError ? (
                            <FieldError id={`${ids.slug}-help`} message={slugError} />
                        ) : (
                            <p id={`${ids.slug}-help`} className='input-help'>
                                3 to 32 lowercase letters, numbers and hyphens. Changing it breaks links you shared.
                            </p>
                        )}
                    </div>
                    <Switch
                        checked={draft.published}
                        onChange={(published) => change({ published })}
                        label='Published'
                        description='Anyone with the link can open the page. Turn it off to hide the page again.'
                    />
                    <Switch
                        checked={draft.noindex}
                        onChange={(noindex) => change({ noindex })}
                        label='Hide from search engines'
                        description='Asks Google and others not to list the page. Links still work.'
                    />
                </div>
            </Card>

            <Card title='Description and colour' icon={Palette}>
                <div className='flex flex-col gap-5'>
                    <div>
                        <div className='mb-1 flex items-center justify-between gap-3'>
                            <Label htmlFor={ids.description} className='mb-0'>
                                Description
                            </Label>
                            <button
                                type='button'
                                aria-pressed={preview}
                                onClick={() => setPreview((value) => !value)}
                                className='text-xs font-medium text-accent underline underline-offset-2'
                            >
                                {preview ? 'Edit' : 'Preview'}
                            </button>
                        </div>
                        {preview ? (
                            <div className='min-h-32 rounded-md border border-border p-3'>
                                {draft.description.trim() === '' ? (
                                    <p className='text-sm text-muted-foreground'>Nothing to preview yet.</p>
                                ) : (
                                    <Markdown source={draft.description} />
                                )}
                            </div>
                        ) : (
                            <TextArea
                                id={ids.description}
                                rows={7}
                                value={draft.description}
                                maxLength={DESCRIPTION_MAX}
                                $hasError={!!errors.description}
                                aria-describedby={`${ids.description}-help`}
                                onChange={(event) => change({ description: event.currentTarget.value })}
                            />
                        )}
                        {errors.description ? (
                            <FieldError id={`${ids.description}-help`} message={errors.description} />
                        ) : (
                            <p id={`${ids.description}-help`} className='input-help'>
                                Markdown: **bold**, *italic*, [links](https://example.com), lists and headings.{' '}
                                {draft.description.length} / {DESCRIPTION_MAX}
                            </p>
                        )}
                    </div>
                    <div>
                        <Label htmlFor={ids.color}>Banner colour</Label>
                        <div className='flex flex-wrap items-center gap-3'>
                            <input
                                id={ids.color}
                                type='color'
                                value={draft.color ?? DEFAULT_COLOR}
                                onChange={(event) => change({ color: event.currentTarget.value })}
                                className='h-10 w-14 cursor-pointer rounded-md border border-border bg-transparent'
                            />
                            <span className='font-mono text-sm text-muted-foreground'>{draft.color ?? 'Default'}</span>
                            {draft.color !== null && (
                                <button
                                    type='button'
                                    onClick={() => change({ color: null })}
                                    className='text-xs font-medium text-accent underline underline-offset-2'
                                >
                                    Use the default
                                </button>
                            )}
                        </div>
                        <FieldError id={`${ids.color}-error`} message={errors.banner_color} />
                    </div>
                </div>
            </Card>

            <div className='flex justify-end'>
                <Button type='submit' isLoading={save.isPending} disabled={save.isPending || slugError !== null}>
                    Save
                </Button>
            </div>
        </form>
    );
}

function PublishedLink({ settings }: { settings: ServerPageSettings }) {
    if (!settings.exists || !settings.url) {
        return null;
    }

    if (!settings.published) {
        return (
            <Alert type='info' title='The page is hidden'>
                Turn on Published and save to share it.
            </Alert>
        );
    }

    return (
        <Alert type='success' title='Your page is live'>
            <div className='flex flex-wrap items-center gap-x-4 gap-y-2'>
                <CopyOnClick text={settings.url}>
                    <code className='cursor-pointer font-mono text-sm break-all'>{settings.url}</code>
                </CopyOnClick>
                <a
                    href={settings.url}
                    target='_blank'
                    rel='noopener noreferrer'
                    className='inline-flex items-center gap-1 text-sm font-medium underline underline-offset-2'
                >
                    Open
                    <ExternalLink aria-hidden className='size-3.5' />
                </a>
                <span className='text-sm'>{settings.votes} votes</span>
            </div>
        </Alert>
    );
}

function ShareBody({ uuid }: { uuid: string }) {
    const serverName = useCurrentServerName() ?? '';
    const page = useServerPage(uuid);

    if (page.isPending) {
        return <Spinner size='large' centered />;
    }

    if (page.isError) {
        return (
            <Alert type='danger' title='The public page settings could not be loaded'>
                Reload the page to try again.
            </Alert>
        );
    }

    return (
        <>
            <PublishedLink settings={page.data} />
            {!page.data.has_address && (
                <Alert type='warning' title='No join address yet'>
                    The page only ever shows the server’s playit address. Until the server has one, visitors are asked
                    to contact you to join.
                </Alert>
            )}
            <div className='grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]'>
                <PageForm key={String(page.data.exists)} uuid={uuid} settings={page.data} serverName={serverName} />
                <BannerCard uuid={uuid} settings={page.data} />
            </div>
        </>
    );
}

/** The server's shareable public page settings, at /server/$id/share. Needs `share.manage`. */
export default function ServerShareContainer() {
    const uuid = useCurrentServerUuid();

    return (
        <ServerContentBlock title='Public page'>
            <PageHeading
                title='Public page'
                description='A page to share with players: your server’s name, description, status, player count, join address and votes.'
            />
            <div className='mt-6 flex flex-col gap-6'>
                {uuid ? <ShareBody uuid={uuid} /> : <Spinner size='large' centered />}
            </div>
        </ServerContentBlock>
    );
}
