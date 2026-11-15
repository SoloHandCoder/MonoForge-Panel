import { useStore } from '@tanstack/react-form';
import {
    useSaveAssistantProvider,
    type AssistantProvider,
    type AssistantProviderInput,
    type AssistantProviderPreset,
} from '@/api/assistant';
import {
    providerPresetOrder,
    providerPresets,
    type ProviderPresetMap,
} from '@/components/admin/assistant/assistantTabs';
import Button from '@/components/elements/Button';
import { Dialog } from '@/components/elements/dialog';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import { Form, useAppForm } from '@/components/form';

interface Props {
    open: boolean;
    onClose: () => void;
    provider?: AssistantProvider;
    /** The prefills per preset; defaults to the built-in ones. */
    presets?: ProviderPresetMap;
}

export interface ProviderFormValues {
    preset: AssistantProviderPreset;
    name: string;
    baseUrl: string;
    model: string;
    /** Always starts blank: stored keys never reach the browser. */
    apiKey: string;
    contextWindow: number | null;
    supportsTools: boolean;
    enabled: boolean;
    /** `Header: value`, one per line. */
    extraHeaders: string;
}

const presetOptionsOf = (presets: ProviderPresetMap) =>
    providerPresetOrder.map((preset) => ({ value: preset, label: presets[preset].label }));

export const formatHeaders = (headers: Readonly<Record<string, string>>): string =>
    Object.entries(headers)
        .map(([name, value]) => `${name}: ${value}`)
        .join('\n');

/** `Header: value` lines as a header map; lines without a colon are skipped. */
export const parseHeaders = (text: string): Record<string, string> | null => {
    const headers: Record<string, string> = {};

    for (const line of text.split('\n')) {
        const at = line.indexOf(':');
        const name = line.slice(0, at).trim();

        if (at > 0 && name) {
            headers[name] = line.slice(at + 1).trim();
        }
    }

    return Object.keys(headers).length ? headers : null;
};

const newProviderValues = (): ProviderFormValues => ({
    preset: 'clinepass',
    name: providerPresets.clinepass.label,
    baseUrl: providerPresets.clinepass.base_url,
    model: providerPresets.clinepass.model,
    apiKey: '',
    contextWindow: 32000,
    supportsTools: true,
    enabled: true,
    extraHeaders: '',
});

export const providerFormValues = (provider?: AssistantProvider): ProviderFormValues =>
    provider
        ? {
              preset: provider.preset,
              name: provider.name,
              baseUrl: provider.base_url,
              model: provider.model,
              apiKey: '',
              contextWindow: provider.context_window,
              supportsTools: provider.supports_tools,
              enabled: provider.enabled,
              extraHeaders: formatHeaders(provider.extra_headers),
          }
        : newProviderValues();

/** The request body; a blank key field leaves the key out so the stored one is kept. */
export const providerRequestBody = (values: ProviderFormValues): AssistantProviderInput => {
    const body: AssistantProviderInput = {
        name: values.name.trim(),
        preset: values.preset,
        base_url: values.baseUrl.trim(),
        model: values.model.trim(),
        extra_headers: parseHeaders(values.extraHeaders),
        context_window: values.contextWindow ?? 32000,
        supports_tools: values.supportsTools,
        enabled: values.enabled,
    };

    if (values.apiKey.trim() !== '') {
        body.api_key = values.apiKey.trim();
    }

    return body;
};

const apiKeyDescription = (stored: boolean, needed: boolean): string => {
    if (stored) {
        return 'A key is stored. Leave this blank to keep it, or enter a new one to replace it.';
    }

    return needed ? 'Stored encrypted and never shown again.' : 'Optional for this provider.';
};

const required = (message: string) => ({
    onChange: ({ value }: { value: string }) => (value.trim() ? undefined : message),
});

/** Adds or edits an AI provider. Picking a preset prefills its base URL and model. */
export default function ProviderFormDialog({ open, onClose, provider, presets = providerPresets }: Props) {
    const save = useSaveAssistantProvider();
    const form = useAppForm({
        defaultValues: providerFormValues(provider),
        onSubmit: async ({ value }) => {
            try {
                await save.mutateAsync({ id: provider?.id, body: providerRequestBody(value) });
                onClose();
            } catch {
                // Error toast is handled by the mutation.
            }
        },
    });
    const isSubmitting = useStore(form.store, (state) => state.isSubmitting);
    const preset = useStore(form.store, (state) => state.values.preset);
    const keyDescription = apiKeyDescription(provider?.has_key ?? false, presets[preset].needs_key);

    return (
        <Dialog
            open={open}
            title={provider ? 'Edit provider' : 'Add provider'}
            preventExternalClose={isSubmitting}
            hideCloseIcon={isSubmitting}
            onClose={onClose}
        >
            <SpinnerOverlay visible={isSubmitting} />
            <Form form={form} className='m-0'>
                <div className='grid gap-5'>
                    <form.AppField
                        name='preset'
                        listeners={{
                            onChange: ({ value }) => {
                                const defaults = presets[value];

                                form.setFieldValue('baseUrl', defaults.base_url);
                                form.setFieldValue('model', defaults.model);

                                if (!provider) {
                                    form.setFieldValue('name', defaults.label);
                                }
                            },
                        }}
                    >
                        {(field) => <field.SelectField label='Preset' options={presetOptionsOf(presets)} />}
                    </form.AppField>
                    <form.AppField name='name' validators={required('A name is required.')}>
                        {(field) => <field.TextField type='text' label='Name' autoComplete='off' />}
                    </form.AppField>
                    <div className='grid gap-5 sm:grid-cols-2'>
                        <form.AppField name='baseUrl' validators={required('A base URL is required.')}>
                            {(field) => (
                                <field.TextField
                                    type='url'
                                    label='Base URL'
                                    description='The OpenAI-compatible endpoint, without /chat/completions.'
                                    autoComplete='off'
                                />
                            )}
                        </form.AppField>
                        <form.AppField name='model' validators={required('A model is required.')}>
                            {(field) => <field.TextField type='text' label='Model' autoComplete='off' />}
                        </form.AppField>
                    </div>
                    <form.AppField name='apiKey'>
                        {(field) => (
                            <field.TextField
                                type='password'
                                label='API key'
                                description={keyDescription}
                                placeholder={provider?.has_key ? '•••••••• (stored)' : undefined}
                                autoComplete='new-password'
                                data-testid='provider-api-key'
                            />
                        )}
                    </form.AppField>
                    <form.AppField
                        name='contextWindow'
                        validators={{
                            onChange: ({ value }) => (value && value >= 1024 ? undefined : 'At least 1,024 tokens.'),
                        }}
                    >
                        {(field) => (
                            <field.NumberField
                                label='Context window'
                                description="The model's context size in tokens."
                                min={1024}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='extraHeaders'>
                        {(field) => (
                            <field.TextAreaField
                                label='Extra headers'
                                description='Optional, one per line as Header: value.'
                                rows={2}
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='supportsTools'>
                        {(field) => (
                            <field.SwitchField
                                label='Tool calls'
                                description='Turn off for models without function calling; the assistant then only chats.'
                            />
                        )}
                    </form.AppField>
                    <form.AppField name='enabled'>
                        {(field) => <field.SwitchField label='Enabled' description='Conversations may use it.' />}
                    </form.AppField>
                </div>
                <div className='mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end'>
                    <Button.Text type='button' isSecondary onClick={onClose}>
                        Cancel
                    </Button.Text>
                    <form.AppForm>
                        <form.SubmitButton>{provider ? 'Save changes' : 'Add provider'}</form.SubmitButton>
                    </form.AppForm>
                </div>
            </Form>
        </Dialog>
    );
}
