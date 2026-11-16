import { Check, Copy, Eye, ExternalLink, Link2, Palette, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useId, useState } from 'react';
import { httpValidationErrors, type ValidationErrors } from '@/api/http';
import { useCurrentServerName, useCurrentServerUuid } from '@/api/server/queries';
import {
    BANNER_MAX_BYTES,
    BANNER_TYPES,
    DESCRIPTION_MAX,
    LINK_MAX,
    SLUG_MAX,
    TAGLINE_MAX,
    deleteServerPageBannerInput,
    linkProblem,
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
import Label from '@/components/elements/Label';
import PageHeading from '@/components/elements/PageHeading';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Spinner from '@/components/elements/Spinner';
import { FileInput, TextArea, TextInput } from '@/components/form/controls';
import ServerCard from '@/components/serverPage/ServerCard';
import Switch from '@/components/ui/Switch';
import copyToClipboard from '@/lib/clipboard';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';
import {
    DEFAULT_ACCENT,
    DEFAULT_BACKGROUND,
    buildTheme,
    textForGradient,
    type BackgroundStyle,
    type ButtonStyle,
} from '@/lib/serverPageTheme';
import { notifyHttpError } from '@/plugins/notifications';

interface Draft {
    slug: string;
    published: boolean;
    noindex: boolean;
    discoverable: boolean;
    allowWake: boolean;
    description: string;
    tagline: string;
    color: string | null;
    backgroundStyle: BackgroundStyle;
    backgroundFrom: string | null;
    backgroundTo: string | null;
    buttonStyle: ButtonStyle;
    showPlayers: boolean;
    showVotes: boolean;
    showAddress: boolean;
    showDescription: boolean;
    discordUrl: string;
    websiteUrl: string;
}

const draftFrom = (settings: ServerPageSettings, serverName: string): Draft => ({
    slug: settings.slug ?? suggestSlug(serverName),
    published: settings.published,
    noindex: settings.noindex,
    discoverable: settings.discoverable,
    allowWake: settings.allow_wake,
    description: settings.description,
    tagline: settings.tagline,
    color: settings.banner_color,
    backgroundStyle: settings.background_style,
    backgroundFrom: settings.background_from,
    backgroundTo: settings.background_to,
    buttonStyle: settings.button_style,
    showPlayers: settings.show_players,
    showVotes: settings.show_votes,
    showAddress: settings.show_address,
    showDescription: settings.show_description,
    discordUrl: settings.discord_url ?? '',
    websiteUrl: settings.website_url ?? '',
});

/** The start of the description as plain text, as the discovery card shows it. */
export const plainExcerpt = (markdown: string, max = 160): string => {
    const text = markdown
        .replaceAll(/!\[[^\]]*\]\([^)]*\)/g, '')
        .replaceAll(/\[([^\]]*)\]\([^)]*\)/g, '$1')
        .replaceAll(/<[^>]*>/g, '')
        .replaceAll(/[*_`#>~|]+/g, '')
        .replaceAll(/^\s*(?:[-+]|\d+[.)])\s+/gm, '')
        .replaceAll(/\s+/g, ' ')
        .trim();

    return text.length > max ? `${text.slice(0, max - 1).trimEnd()}…` : text;
};

/** Why the gradient cannot be saved, or null: some text colour must read on both of its ends. */
export const gradientProblem = (draft: Pick<Draft, 'backgroundStyle' | 'backgroundFrom' | 'backgroundTo'>) => {
    if (draft.backgroundStyle !== 'gradient') {
        return null;
    }

    if (!draft.backgroundTo) {
        return 'Choose the second colour of the gradient.';
    }

    return textForGradient(draft.backgroundFrom ?? DEFAULT_BACKGROUND, draft.backgroundTo) === null
        ? 'These two colours are too far apart for text to stay readable on both. Pick colours that are both light or both dark.'
        : null;
};

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

function Section({ title, description, children }: { title: string; description: string; children: ReactNode }) {
    const id = useId();

    return (
        <section aria-labelledby={id} className='flex flex-col gap-5'>
            <div className='border-b border-border pb-2'>
                <h3 id={id} className='text-sm font-semibold tracking-wide text-foreground uppercase'>
                    {title}
                </h3>
                <p className='mt-0.5 text-sm text-muted-foreground'>{description}</p>
            </div>
            {children}
        </section>
    );
}

function ColorField({
    label,
    value,
    fallback,
    onChange,
    error,
    resetLabel = 'Use the default',
}: {
    label: string;
    value: string | null;
    fallback: string;
    onChange: (value: string | null) => void;
    error?: string | null;
    resetLabel?: string;
}) {
    const id = useId();

    return (
        <div className='min-w-0'>
            <Label htmlFor={id}>{label}</Label>
            <div className='flex flex-wrap items-center gap-3'>
                <input
                    id={id}
                    type='color'
                    value={value ?? fallback}
                    aria-describedby={error ? `${id}-error` : undefined}
                    onChange={(event) => onChange(event.currentTarget.value)}
                    className='h-10 w-14 cursor-pointer rounded-md border border-border bg-transparent'
                />
                <span className='font-mono text-sm text-muted-foreground'>{value ?? 'Default'}</span>
                {value !== null && (
                    <button
                        type='button'
                        onClick={() => onChange(null)}
                        className='text-xs font-medium text-accent underline underline-offset-2'
                    >
                        {resetLabel}
                    </button>
                )}
            </div>
            <FieldError id={`${id}-error`} message={error} />
        </div>
    );
}

function Choice<T extends string>({
    legend,
    value,
    options,
    onChange,
}: {
    legend: string;
    value: T;
    options: { value: T; label: string; disabled?: boolean; hint?: string }[];
    onChange: (value: T) => void;
}) {
    const name = useId();

    return (
        <fieldset className='min-w-0'>
            <legend className='mb-1.5 text-sm font-medium text-foreground'>{legend}</legend>
            <div className='flex flex-wrap gap-2'>
                {options.map((option) => (
                    <label
                        key={option.value}
                        title={option.hint}
                        className={cn(
                            'inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                            value === option.value
                                ? 'border-accent bg-accent/10 text-foreground'
                                : 'border-border text-muted-foreground hover:text-foreground',
                            option.disabled && 'cursor-not-allowed opacity-50'
                        )}
                    >
                        <input
                            type='radio'
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            disabled={option.disabled}
                            onChange={() => onChange(option.value)}
                            className='sr-only'
                        />
                        {option.label}
                    </label>
                ))}
            </div>
        </fieldset>
    );
}

/**
 * The page address: the panel's own address as a muted prefix and the slug as the part the owner
 * edits, on a row of its own, with copy and open buttons that wrap below it on a narrow screen.
 */
function AddressField({
    draft,
    settings,
    error,
    onChange,
}: {
    draft: Draft;
    settings: ServerPageSettings;
    error: string | null;
    onChange: (slug: string) => void;
}) {
    const id = useId();
    const [copied, setCopied] = useState(false);
    const prefix = `${window.location.host}/s/`;
    const live = settings.exists && settings.published && settings.url !== null && settings.slug === draft.slug;

    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 2000);

        return () => window.clearTimeout(timer);
    }, [copied]);

    return (
        <div>
            <Label htmlFor={id}>Page address</Label>
            <div className='flex flex-col gap-2 sm:flex-row sm:items-stretch'>
                <div
                    className={cn(
                        'flex min-w-0 flex-1 items-stretch overflow-hidden rounded-md border bg-card focus-within:ring-2 focus-within:ring-ring',
                        error ? 'border-destructive' : 'border-border'
                    )}
                >
                    <span
                        title={`${window.location.origin}/s/`}
                        className='flex max-w-[55%] shrink-0 items-center truncate border-r border-border bg-muted px-3 font-mono text-sm text-muted-foreground'
                    >
                        <span className='truncate'>{prefix}</span>
                    </span>
                    <input
                        id={id}
                        value={draft.slug}
                        maxLength={SLUG_MAX}
                        autoComplete='off'
                        spellCheck={false}
                        aria-invalid={!!error}
                        aria-describedby={`${id}-help`}
                        onChange={(event) => onChange(event.currentTarget.value.toLowerCase())}
                        className='h-10 min-w-0 flex-1 bg-transparent px-3 font-mono text-sm text-foreground outline-none'
                    />
                </div>
                {live && settings.url && (
                    <div className='flex gap-2'>
                        <Button.Text
                            type='button'
                            className='flex-1 sm:flex-none'
                            onClick={async () => setCopied(await copyToClipboard(settings.url ?? ''))}
                        >
                            <Copy aria-hidden className='mr-1.5 inline size-4' />
                            {copied ? 'Copied' : 'Copy'}
                        </Button.Text>
                        <a
                            href={settings.url}
                            target='_blank'
                            rel='noopener noreferrer'
                            className='inline-flex flex-1 items-center justify-center gap-1.5 rounded-md border border-border px-3 py-2 text-sm font-medium text-foreground no-underline hover:bg-muted sm:flex-none'
                        >
                            <ExternalLink aria-hidden className='size-4' />
                            Open
                        </a>
                    </div>
                )}
            </div>
            <span aria-live='polite' className='sr-only'>
                {copied ? 'Address copied' : ''}
            </span>
            {error ? (
                <FieldError id={`${id}-help`} message={error} />
            ) : (
                <p id={`${id}-help`} className='input-help'>
                    3 to 32 lowercase letters, numbers and hyphens. Changing it breaks links you shared.
                </p>
            )}
        </div>
    );
}

function BannerField({ uuid, settings }: { uuid: string; settings: ServerPageSettings }) {
    const upload = useUploadServerPageBanner(uuid);
    const remove = useDeleteServerPageBanner(uuid);
    const [problem, setProblem] = useState<string | null>(null);
    const inputId = useId();

    if (!settings.exists) {
        return <p className='text-sm text-muted-foreground'>Save the page first, then add a banner image.</p>;
    }

    return (
        <div className='flex flex-col gap-3'>
            {settings.banner_image_url && (
                <img
                    src={settings.banner_image_url}
                    alt='Current banner'
                    className='h-24 w-full rounded-lg border border-border object-cover'
                />
            )}
            <Label htmlFor={inputId}>Banner image: PNG, JPEG or WebP, up to 1 MB</Label>
            <FileInput
                id={inputId}
                accept={BANNER_TYPES.join(',')}
                disabled={upload.isPending}
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
                        disabled={remove.isPending}
                        onClick={() => remove.mutate(deleteServerPageBannerInput(uuid))}
                    >
                        <Trash2 aria-hidden className='mr-1.5 inline size-4' />
                        Remove image
                    </Button.Danger>
                </div>
            )}
        </div>
    );
}

function Preview({ draft, settings, serverName }: { draft: Draft; settings: ServerPageSettings; serverName: string }) {
    const theme = buildTheme({
        accent: draft.color,
        backgroundStyle: draft.backgroundStyle,
        backgroundFrom: draft.backgroundFrom,
        backgroundTo: draft.backgroundTo,
        buttonStyle: draft.buttonStyle,
        imageUrl: settings.banner_image_url,
    });

    return (
        <Card title='Preview' icon={Eye}>
            <div className='flex flex-col gap-3'>
                <p className='text-sm text-muted-foreground'>
                    How your card looks on the{' '}
                    <a href='/servers' className='font-medium text-accent underline underline-offset-2'>
                        discovery page
                    </a>
                    , with a sample status. The text colour is picked for you so it always stays readable.
                </p>
                <div aria-label='Preview of your server card' role='group'>
                    <ServerCard
                        headingLevel='h3'
                        server={{
                            name: serverName || 'Your server',
                            tagline: draft.tagline.trim(),
                            description: draft.showDescription ? plainExcerpt(draft.description) : '',
                            game: null,
                            status: 'online',
                            players: draft.showPlayers ? { online: 3, max: 20 } : null,
                            votes: settings.votes,
                            address: draft.showAddress && settings.has_address ? 'your-server.joinmc.link' : null,
                            theme,
                            sections: {
                                players: draft.showPlayers,
                                votes: draft.showVotes,
                                address: draft.showAddress,
                                description: draft.showDescription,
                            },
                            url: null,
                        }}
                    />
                </div>
                {settings.hidden_from_discovery ? (
                    <p className='text-sm text-warning'>An administrator took this page off the discovery page.</p>
                ) : (
                    !draft.discoverable && (
                        <p className='text-sm text-muted-foreground'>The card is not listed on the discovery page.</p>
                    )
                )}
            </div>
        </Card>
    );
}

interface Problems {
    slug: string | null;
    discord: string | null;
    website: string | null;
    background: string | null;
}

/** The problems that keep the form from being sent: the panel's answer first, then the local checks. */
const formProblems = (draft: Draft, errors: ValidationErrors): Problems => ({
    slug: errors.slug ?? (draft.slug === '' ? null : slugProblem(draft.slug)),
    discord: errors.discord_url ?? linkProblem('discord', draft.discordUrl),
    website: errors.website_url ?? linkProblem('website', draft.websiteUrl),
    background: errors.background_to ?? gradientProblem(draft),
});

/** The request the panel expects for a draft. */
const requestBody = (draft: Draft) => ({
    slug: draft.slug,
    published: draft.published,
    noindex: draft.noindex,
    discoverable: draft.discoverable,
    allow_wake: draft.allowWake,
    description: draft.description,
    tagline: draft.tagline,
    // An empty colour is stored as none, which shows the panel's default.
    banner_color: draft.color ?? '',
    background_style: draft.backgroundStyle,
    background_from: draft.backgroundFrom,
    background_to: draft.backgroundTo,
    button_style: draft.buttonStyle,
    show_players: draft.showPlayers,
    show_votes: draft.showVotes,
    show_address: draft.showAddress,
    show_description: draft.showDescription,
    discord_url: draft.discordUrl.trim(),
    website_url: draft.websiteUrl.trim(),
});

interface SectionProps {
    draft: Draft;
    change: (patch: Partial<Draft>) => void;
    errors: ValidationErrors;
    problems: Problems;
    settings: ServerPageSettings;
}

function PageCard({ draft, change, errors, problems, settings }: SectionProps) {
    const [preview, setPreview] = useState(false);
    const ids = { description: useId(), tagline: useId(), discord: useId(), website: useId() };
    const slugError = problems.slug;
    const discordError = problems.discord;
    const websiteError = problems.website;

    return (
        <Card title='Page' icon={Link2}>
            <Section title='Address and text' description='Where the page lives and what it says.'>
                <AddressField
                    draft={draft}
                    settings={settings}
                    error={slugError}
                    onChange={(slug) => change({ slug })}
                />
                <div>
                    <Label htmlFor={ids.tagline}>Tagline</Label>
                    <TextInput
                        id={ids.tagline}
                        value={draft.tagline}
                        maxLength={TAGLINE_MAX}
                        $hasError={!!errors.tagline}
                        aria-describedby={`${ids.tagline}-help`}
                        placeholder='Islands, weekly events and no pay to win'
                        onChange={(event) => change({ tagline: event.currentTarget.value })}
                    />
                    {errors.tagline ? (
                        <FieldError id={`${ids.tagline}-help`} message={errors.tagline} />
                    ) : (
                        <p id={`${ids.tagline}-help`} className='input-help'>
                            One line under the name, plain text. {draft.tagline.length} / {TAGLINE_MAX}
                        </p>
                    )}
                </div>
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
                <div className='grid gap-4 sm:grid-cols-2'>
                    <div className='min-w-0'>
                        <Label htmlFor={ids.discord}>Discord invite</Label>
                        <TextInput
                            id={ids.discord}
                            type='url'
                            inputMode='url'
                            value={draft.discordUrl}
                            maxLength={LINK_MAX}
                            placeholder='https://discord.gg/abc123'
                            $hasError={!!discordError}
                            aria-invalid={!!discordError}
                            aria-describedby={discordError ? `${ids.discord}-error` : undefined}
                            onChange={(event) => change({ discordUrl: event.currentTarget.value })}
                        />
                        <FieldError id={`${ids.discord}-error`} message={discordError} />
                    </div>
                    <div className='min-w-0'>
                        <Label htmlFor={ids.website}>Website</Label>
                        <TextInput
                            id={ids.website}
                            type='url'
                            inputMode='url'
                            value={draft.websiteUrl}
                            maxLength={LINK_MAX}
                            placeholder='https://example.com'
                            $hasError={!!websiteError}
                            aria-invalid={!!websiteError}
                            aria-describedby={websiteError ? `${ids.website}-error` : undefined}
                            onChange={(event) => change({ websiteUrl: event.currentTarget.value })}
                        />
                        <FieldError id={`${ids.website}-error`} message={websiteError} />
                    </div>
                </div>
            </Section>
        </Card>
    );
}

function LookCard({ uuid, draft, change, errors, problems, settings }: SectionProps & { uuid: string }) {
    const backgroundError = problems.background;

    return (
        <Card title='Look' icon={Palette}>
            <Section title='Colours and shape' description='Pick colours only; the text colour is chosen for you.'>
                <ColorField
                    label='Accent colour'
                    value={draft.color}
                    fallback={DEFAULT_ACCENT}
                    error={errors.banner_color}
                    onChange={(color) => change({ color })}
                />
                <Choice<BackgroundStyle>
                    legend='Background'
                    value={draft.backgroundStyle}
                    onChange={(backgroundStyle) => change({ backgroundStyle })}
                    options={[
                        { value: 'solid', label: 'Solid colour' },
                        { value: 'gradient', label: 'Gradient' },
                        {
                            value: 'image',
                            label: 'Banner image',
                            disabled: !settings.banner_image_url,
                            hint: settings.banner_image_url ? undefined : 'Upload a banner image first.',
                        },
                    ]}
                />
                {draft.backgroundStyle !== 'image' && (
                    <div className='grid gap-4 sm:grid-cols-2'>
                        <ColorField
                            label={draft.backgroundStyle === 'gradient' ? 'Gradient start' : 'Background colour'}
                            value={draft.backgroundFrom}
                            fallback={DEFAULT_BACKGROUND}
                            error={errors.background_from}
                            onChange={(backgroundFrom) => change({ backgroundFrom })}
                        />
                        {draft.backgroundStyle === 'gradient' && (
                            <ColorField
                                label='Gradient end'
                                value={draft.backgroundTo}
                                fallback={DEFAULT_ACCENT}
                                error={backgroundError}
                                resetLabel='Clear'
                                onChange={(backgroundTo) => change({ backgroundTo })}
                            />
                        )}
                    </div>
                )}
                {draft.backgroundStyle === 'gradient' && !draft.backgroundTo && (
                    <FieldError id='gradient-missing' message={backgroundError} />
                )}
                <BannerField uuid={uuid} settings={settings} />
                <Choice<ButtonStyle>
                    legend='Buttons'
                    value={draft.buttonStyle}
                    onChange={(buttonStyle) => change({ buttonStyle })}
                    options={[
                        { value: 'rounded', label: 'Rounded' },
                        { value: 'square', label: 'Square' },
                    ]}
                />
            </Section>
        </Card>
    );
}

function VisibilityCard({ draft, change }: Pick<SectionProps, 'draft' | 'change'>) {
    return (
        <Card title='Visibility' icon={Eye}>
            <Section title='Who sees what' description='Publishing, listing, and the parts of the page you show.'>
                <Switch
                    checked={draft.published}
                    onChange={(published) => change({ published })}
                    label='Published'
                    description='Anyone with the link can open the page. Turn it off to hide the page again.'
                />
                <Switch
                    checked={draft.discoverable}
                    onChange={(discoverable) => change({ discoverable })}
                    label='Show on the server discovery page'
                    description='Lists the published page on the public server list at /servers.'
                />
                <Switch
                    checked={draft.noindex}
                    onChange={(noindex) => change({ noindex })}
                    label='Hide from search engines'
                    description='Asks Google and others not to list the page. Links still work.'
                />
                <Switch
                    checked={draft.allowWake}
                    onChange={(allowWake) => change({ allowWake })}
                    label='Let visitors wake the server'
                    description='While the server sleeps, visitors can start it from the page (a few times an hour). Each wake means paying for the time it runs.'
                />
                <div className='grid gap-4 sm:grid-cols-2'>
                    <Switch
                        checked={draft.showPlayers}
                        onChange={(showPlayers) => change({ showPlayers })}
                        label='Show players online'
                    />
                    <Switch
                        checked={draft.showVotes}
                        onChange={(showVotes) => change({ showVotes })}
                        label='Show votes'
                    />
                    <Switch
                        checked={draft.showAddress}
                        onChange={(showAddress) => change({ showAddress })}
                        label='Show the join address'
                    />
                    <Switch
                        checked={draft.showDescription}
                        onChange={(showDescription) => change({ showDescription })}
                        label='Show the description'
                    />
                </div>
            </Section>
        </Card>
    );
}

function PageForm({ uuid, settings, serverName }: { uuid: string; settings: ServerPageSettings; serverName: string }) {
    const save = useSaveServerPage(uuid);
    const [draft, setDraft] = useState<Draft>(() => draftFrom(settings, serverName));
    const [errors, setErrors] = useState<ValidationErrors>({});
    const problems = formProblems(draft, errors);
    const blocked = Object.values(problems).some((problem) => problem !== null);

    const change = (patch: Partial<Draft>) => setDraft((current) => ({ ...current, ...patch }));
    const sectionProps = { draft, change, errors, problems, settings };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setErrors({});
        save.mutate(saveServerPageInput(uuid, requestBody(draft)), {
            onError: (error) => {
                const fieldErrors = httpValidationErrors(error);

                setErrors(fieldErrors);

                if (Object.keys(fieldErrors).length === 0) {
                    notifyHttpError(error, 'Unable to save the public page');
                }
            },
        });
    };

    return (
        <form onSubmit={submit} noValidate className='grid gap-6 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]'>
            <div className='flex min-w-0 flex-col gap-6'>
                <PageCard {...sectionProps} />
                <LookCard uuid={uuid} {...sectionProps} />
                <VisibilityCard draft={draft} change={change} />
                <div className='flex justify-end'>
                    <Button type='submit' isLoading={save.isPending} disabled={save.isPending || blocked}>
                        Save
                    </Button>
                </div>
            </div>
            <div className='min-w-0 lg:sticky lg:top-6 lg:self-start'>
                <Preview draft={draft} settings={settings} serverName={serverName} />
            </div>
        </form>
    );
}

function Status({ settings }: { settings: ServerPageSettings }) {
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
            <span className='inline-flex items-center gap-1.5 text-sm'>
                <Check aria-hidden className='size-4' />
                {settings.votes === 1 ? '1 vote' : `${settings.votes} votes`}
                {settings.discoverable && !settings.hidden_from_discovery && ' · listed on the discovery page'}
            </span>
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
            <Status settings={page.data} />
            {!page.data.has_address && (
                <Alert type='warning' title='No join address yet'>
                    The page only ever shows the server’s playit address. Until the server has one, visitors are asked
                    to contact you to join.
                </Alert>
            )}
            {page.data.hidden_from_discovery && (
                <Alert type='warning' title='Not on the discovery page'>
                    An administrator took this page off the server discovery page. It is still published at its address.
                    Contact support if you think this is a mistake.
                </Alert>
            )}
            <PageForm key={String(page.data.exists)} uuid={uuid} settings={page.data} serverName={serverName} />
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
                description='A page to share with players: your server’s name, description, status, player count, join address and votes. Published pages can also be listed on the server discovery page.'
            />
            <div className='mt-6 flex flex-col gap-6'>
                {uuid ? <ShareBody uuid={uuid} /> : <Spinner size='large' centered />}
            </div>
        </ServerContentBlock>
    );
}
