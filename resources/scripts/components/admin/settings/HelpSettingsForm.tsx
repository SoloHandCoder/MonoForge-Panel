import { useStore } from '@tanstack/react-form';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    type AdminHelpArticle,
    HELP_SLUG_PATTERN,
    type HelpArticleValues,
    slugify,
    updateAdminHelpArticleInput,
    useAdminHelpArticles,
    useResetAdminHelpArticle,
    useUpdateAdminHelpArticle,
} from '@/api/admin/settings/help';
import { httpErrorToHuman } from '@/api/http';
import { Alert } from '@/components/elements/alert';
import Badge from '@/components/elements/Badge';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import Icon from '@/components/elements/Icon';
import { ServerError } from '@/components/elements/ScreenBlock';
import Spinner from '@/components/elements/Spinner';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import { Form, useAppForm } from '@/components/form';
import { cn } from '@/lib/cn';
import Markdown from '@/lib/markdown';

const NEW = '';

const MAX_CONTENT_BYTES = 60_000;

const byteLength = (value: string): number => new TextEncoder().encode(value).length;

function MarkdownEditor({ id, value, onChange }: { id: string; value: string; onChange: (value: string) => void }) {
    const [preview, setPreview] = useState(false);

    return (
        <div>
            <div className='mb-3 flex gap-2' role='tablist' aria-label='Editor mode'>
                {(['Write', 'Preview'] as const).map((mode) => (
                    <button
                        key={mode}
                        type='button'
                        role='tab'
                        aria-selected={preview === (mode === 'Preview')}
                        onClick={() => setPreview(mode === 'Preview')}
                        className={cn(
                            'rounded-md px-3 py-1 text-sm transition-colors',
                            preview === (mode === 'Preview')
                                ? 'bg-muted text-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        )}
                    >
                        {mode}
                    </button>
                ))}
            </div>
            {preview ? (
                <div className='max-h-[60vh] overflow-y-auto rounded-md border border-border p-4' role='tabpanel'>
                    <Markdown source={value} />
                </div>
            ) : (
                <div role='tabpanel'>
                    <label htmlFor={id} className='mb-1 block text-sm font-medium text-foreground'>
                        Markdown
                    </label>
                    <textarea
                        id={id}
                        value={value}
                        rows={22}
                        spellCheck
                        onChange={(event) => onChange(event.target.value)}
                        className='w-full rounded-md border border-border bg-input p-3 font-mono text-sm text-foreground focus:border-accent focus:outline-hidden'
                    />
                    <p className='mt-1 text-xs text-muted-foreground'>
                        Headings (#), paragraphs, lists (- or 1.), quotes (&gt;), **bold**, *italic*, `code` and
                        [links](/help/…). Raw HTML is shown as text and only https:// or same-site /… links work.{' '}
                        {byteLength(value).toLocaleString()} of {MAX_CONTENT_BYTES.toLocaleString()} bytes.
                    </p>
                </div>
            )}
        </div>
    );
}

function ResetButton({ article, onDone }: { article: AdminHelpArticle; onDone: () => void }) {
    const reset = useResetAdminHelpArticle();

    if (!article.overridden) {
        return null;
    }

    const label = article.shipped ? 'Reset to default' : 'Delete article';

    return (
        <Dialog.ConfirmTrigger
            title={article.shipped ? `Reset ${article.title}?` : `Delete ${article.title}?`}
            confirm={label}
            onConfirmed={async (_event, close) => {
                try {
                    await reset.mutateAsync({ path: { article: article.slug } });
                    close();
                    onDone();
                } catch {
                    // Error toast is handled by the mutation.
                }
            }}
            trigger={({ onClick }) => (
                <Button type='button' color='red' isSecondary onClick={onClick}>
                    {label}
                </Button>
            )}
        >
            <p className='text-sm leading-relaxed text-muted-foreground'>
                {article.shipped
                    ? 'Your changes to this article are dropped and the starter text that ships with the panel shows again, turned on.'
                    : 'This permanently deletes the article. Links to it stop working.'}
            </p>
        </Dialog.ConfirmTrigger>
    );
}

const formValues = (article: AdminHelpArticle | null): HelpArticleValues =>
    article
        ? {
              slug: article.slug,
              title: article.title,
              category: article.category,
              summary: article.summary,
              content: article.content,
              order: String(article.order),
              enabled: article.enabled,
          }
        : { slug: '', title: '', category: '', summary: '', content: '', order: '', enabled: true };

const titleError = (isNew: boolean, value: string): string | undefined =>
    isNew && value.trim() === '' ? 'Give the article a title.' : undefined;

const slugError = (value: string): string | undefined =>
    value.trim() === '' || HELP_SLUG_PATTERN.test(value.trim())
        ? undefined
        : 'Lowercase letters, numbers and single hyphens only.';

const orderError = (value: string): string | undefined =>
    value.trim() === '' || /^-?\d{1,6}$/.test(value.trim()) ? undefined : 'Use a whole number.';

function contentError(isNew: boolean, value: string): string | undefined {
    if (isNew && value.trim() === '') {
        return 'Write the article.';
    }

    return byteLength(value) > MAX_CONTENT_BYTES ? 'The article is too long.' : undefined;
}

/** Where the article is read, and whether it is still the starter text. */
function ArticleStatus({ article }: { article: AdminHelpArticle }) {
    return (
        <>
            <p className='mb-4 flex flex-wrap items-center gap-2 text-sm text-muted-foreground'>
                {article.audience === 'admin' ? (
                    <span>Shown to root administrators under Admin → Help.</span>
                ) : (
                    <a
                        href={article.publicUrl}
                        target='_blank'
                        rel='noopener noreferrer'
                        className='text-accent underline underline-offset-2'
                    >
                        View the public page
                    </a>
                )}
                {article.shipped && (
                    <Badge size='sm' tone={article.overridden ? 'info' : 'neutral'}>
                        {article.overridden ? 'Changed' : 'Starter text'}
                    </Badge>
                )}
            </p>
            {article.shipped && (
                <Alert type='info' className='mb-6 text-sm'>
                    <span>
                        This article ships with the panel. A field left empty, or set back to the starter text, keeps
                        following the starter text when the panel is updated.
                    </span>
                </Alert>
            )}
        </>
    );
}

/** The editor of one article, or of a new one (`article` null). Exported for tests. */
export function HelpArticleForm({
    article,
    onSaved,
    onReset,
}: {
    article: AdminHelpArticle | null;
    onSaved: (slug: string) => void;
    onReset: () => void;
}) {
    const update = useUpdateAdminHelpArticle();
    const isNew = article === null;
    const form = useAppForm({
        defaultValues: formValues(article),
        onSubmit: async ({ value }) => {
            const slug = isNew ? value.slug.trim() || slugify(value.title) : value.slug;

            try {
                await update.mutateAsync(updateAdminHelpArticleInput({ ...value, slug }));
                onSaved(slug);
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const content = useStore(form.store, (state) => state.values.content);
    const title = useStore(form.store, (state) => state.values.title);
    const prefix = article?.slug ?? 'new';

    return (
        <Form form={form}>
            {article && <ArticleStatus article={article} />}
            <TitledGreyBox title='Article'>
                <div className='grid gap-4 md:grid-cols-2'>
                    <form.AppField name='title' validators={{ onChange: ({ value }) => titleError(isNew, value) }}>
                        {(field) => <field.TextField id={`help_${prefix}_title`} label='Title' maxLength={120} />}
                    </form.AppField>
                    {isNew && (
                        <form.AppField name='slug' validators={{ onChange: ({ value }) => slugError(value) }}>
                            {(field) => (
                                <field.TextField
                                    id='help_new_slug'
                                    label='Address'
                                    maxLength={64}
                                    placeholder={slugify(title) || 'discord-rules'}
                                    description={`The article lives at /help/${field.state.value.trim() || slugify(title) || '…'}.`}
                                />
                            )}
                        </form.AppField>
                    )}
                    <form.AppField name='category'>
                        {(field) => (
                            <field.TextField
                                id={`help_${prefix}_category`}
                                label='Category'
                                maxLength={60}
                                description='An existing category lists the article there; a new name adds a category.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='order' validators={{ onChange: ({ value }) => orderError(value) }}>
                        {(field) => (
                            <field.TextField
                                id={`help_${prefix}_order`}
                                label='Order'
                                inputMode='numeric'
                                description='Lower comes first. Categories follow their first article.'
                            />
                        )}
                    </form.AppField>
                    <div className='md:col-span-2'>
                        <form.AppField name='summary'>
                            {(field) => (
                                <field.TextField
                                    id={`help_${prefix}_summary`}
                                    label='Summary'
                                    maxLength={300}
                                    description='One sentence, shown in the list and used as the search engine description.'
                                />
                            )}
                        </form.AppField>
                    </div>
                    <div className='md:col-span-2'>
                        <form.AppField name='enabled'>
                            {(field) => (
                                <field.SwitchField
                                    label='Shown'
                                    description='Turned off, the article leaves the help center and its page is not found.'
                                />
                            )}
                        </form.AppField>
                    </div>
                </div>
            </TitledGreyBox>
            <TitledGreyBox title='Content' className='mt-6'>
                <form.Field name='content' validators={{ onChange: ({ value }) => contentError(isNew, value) }}>
                    {(field) => (
                        <>
                            <MarkdownEditor
                                id={`help_${prefix}_content`}
                                value={content}
                                onChange={(value) => field.handleChange(value)}
                            />
                            {field.state.meta.errors.length > 0 && (
                                <p className='mt-1 text-xs text-destructive'>{String(field.state.meta.errors[0])}</p>
                            )}
                        </>
                    )}
                </form.Field>
            </TitledGreyBox>
            <div className='mt-6 flex flex-wrap items-center justify-end gap-3'>
                {article && <ResetButton article={article} onDone={onReset} />}
                <form.AppForm>
                    <form.SubmitButton>{isNew ? 'Add article' : 'Save article'}</form.SubmitButton>
                </form.AppForm>
            </div>
        </Form>
    );
}

function ArticleList({
    articles,
    selected,
    onSelect,
}: {
    articles: AdminHelpArticle[];
    selected: string;
    onSelect: (slug: string) => void;
}) {
    return (
        <div className='flex flex-col gap-1' role='listbox' aria-label='Help articles'>
            <button
                type='button'
                role='option'
                aria-selected={selected === NEW}
                onClick={() => onSelect(NEW)}
                className={cn(
                    'mb-2 flex items-center gap-2 rounded-md border border-dashed px-3 py-2 text-left text-sm transition-colors',
                    selected === NEW
                        ? 'border-accent text-foreground'
                        : 'border-border text-muted-foreground hover:text-foreground'
                )}
            >
                <Icon icon={Plus} className='size-4' aria-hidden />
                New article
            </button>
            {articles.map((article) => (
                <button
                    key={article.slug}
                    type='button'
                    role='option'
                    aria-selected={selected === article.slug}
                    onClick={() => onSelect(article.slug)}
                    className={cn(
                        'flex flex-col gap-0.5 rounded-md px-3 py-2 text-left text-sm transition-colors',
                        selected === article.slug
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    )}
                >
                    <span className='truncate font-medium'>{article.title}</span>
                    <span className='flex flex-wrap gap-1 text-xs opacity-80'>
                        {article.category}
                        {article.audience === 'admin' && <span>· Admins</span>}
                        {!article.enabled && <span>· Off</span>}
                        {article.shipped && article.overridden && <span>· Changed</span>}
                        {!article.shipped && <span>· Added</span>}
                    </span>
                </button>
            ))}
        </div>
    );
}

/** Admin > Settings > Help: the help center's articles, their changes, and new ones. */
export default function HelpSettings() {
    const { data, error, refetch } = useAdminHelpArticles();
    const [selected, setSelected] = useState<string | null>(null);
    // Bumped after a save or reset, so the form starts again from what the server now has.
    const [revision, setRevision] = useState(0);

    if (error && !data) {
        return <ServerError message={httpErrorToHuman(error)} onRetry={() => refetch()} />;
    }

    if (!data) {
        return <Spinner size='large' centered />;
    }

    const current = selected ?? data[0]?.slug ?? NEW;
    const article = data.find((entry) => entry.slug === current) ?? null;

    return (
        <div>
            <p className='mb-6 text-sm text-muted-foreground'>
                The articles of the public{' '}
                <a href='/help' target='_blank' rel='noopener noreferrer' className='text-accent underline'>
                    help center
                </a>
                . Change the starter articles, turn them off, or add your own. Guides marked Admins are only shown to
                root administrators under Admin → Help.
            </p>
            <div className='grid gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]'>
                <ArticleList articles={data} selected={article ? current : NEW} onSelect={setSelected} />
                {/* Keyed so switching articles, saving and resetting start a fresh form. */}
                <HelpArticleForm
                    key={`${article?.slug ?? NEW}:${revision}`}
                    article={article}
                    onSaved={(slug) => {
                        setSelected(slug);
                        setRevision((value) => value + 1);
                    }}
                    onReset={() => {
                        setSelected(article?.shipped ? article.slug : null);
                        setRevision((value) => value + 1);
                    }}
                />
            </div>
        </div>
    );
}
