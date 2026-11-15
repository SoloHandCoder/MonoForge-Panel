import { Card, Switch } from '@/sdk';
import type { ViewProps } from '@/sdk/theme';

/** Pay-as-you-play: the owner's auto-sleep switch, on plans that include it. */
export default function ServerAutoSleep({ model, className }: ViewProps<'server.autoSleep'>) {
    if (!model.allowed) {
        return null;
    }

    return (
        <Card title='Auto-sleep' className={className}>
            {model.supported ? (
                <>
                    <p className='text-sm'>
                        When nobody has played for {model.idleMinutes} minutes, the server is stopped gracefully and
                        sleeps until anyone starts it again. Sleeping time costs {model.discountPercent}% less at the
                        renewal.
                    </p>
                    {model.lastSavedTokens > 0 && (
                        <p className='mt-3 text-sm font-medium'>
                            You saved {model.lastSavedTokens} tokens by sleeping at the last renewal.
                        </p>
                    )}
                    {model.sleeping && (
                        <p className='mt-3 text-sm text-muted-foreground'>
                            The server is sleeping now. Start it, or use Wake server on the console.
                        </p>
                    )}
                    <div className='mt-6'>
                        <Switch
                            checked={model.enabled}
                            disabled={model.saving}
                            label='Auto-sleep'
                            description='Sleep while nobody plays.'
                            onChange={(checked) => void model.setEnabled(checked)}
                        />
                    </div>
                </>
            ) : (
                <p className='text-sm text-muted-foreground'>
                    Auto-sleep is not supported for this game yet. It needs a game that tells the panel who is online
                    (Minecraft Java for now).
                </p>
            )}
        </Card>
    );
}
