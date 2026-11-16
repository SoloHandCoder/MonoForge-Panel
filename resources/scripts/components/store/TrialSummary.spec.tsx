/** @vitest-environment jsdom */
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { CAPACITY_MESSAGE } from '@/components/store/StoreNotices';
import TrialSummary from '@/components/store/TrialSummary';
import { createExtensionTestHost, type ExtensionTestHost } from '@/sdk/testing';

let host: ExtensionTestHost | undefined;

afterEach(() => {
    cleanup();
    host?.dispose();
    host = undefined;
});

const resources = { memory: 2048, disk: 10_000, cpu: 100, backups: 1, databases: 1, allocations: 0 };

async function show(props: Partial<Parameters<typeof TrialSummary>[0]> = {}) {
    const onStart = vi.fn();

    host = createExtensionTestHost();
    await act(async () => {
        render(
            <TrialSummary
                hours={48}
                resources={resources}
                soldOut={false}
                error={null}
                canStart
                working={false}
                Captcha={null}
                onStart={onStart}
                {...props}
            />,
            { wrapper: host!.Wrapper }
        );
    });

    return onStart;
}

it('starts the free trial and says nothing is charged', async () => {
    const onStart = await show({ Captcha: () => <div>captcha widget</div> });

    expect(screen.getByText('Free')).toBeVisible();
    expect(screen.getByText('For 48 hours')).toBeVisible();
    expect(screen.getByText('captcha widget')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Try free for 48 h' }));

    expect(onStart).toHaveBeenCalledOnce();
});

it('cannot start while sold out, and shows why a start failed', async () => {
    await show({
        soldOut: true,
        canStart: false,
        error: { message: 'You have already used your free trial.', short: false },
    });

    expect(screen.getByText(CAPACITY_MESSAGE)).toBeVisible();
    expect(screen.getByText('You have already used your free trial.')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Try free for 48 h' })).toBeDisabled();
});
