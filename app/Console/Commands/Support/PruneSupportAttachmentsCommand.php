<?php

declare(strict_types=1);

namespace Pterodactyl\Console\Commands\Support;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Pterodactyl\Services\Support\SupportAttachmentPruner;

#[Description('Removes the images of support tickets closed more than 180 days ago, keeping the messages.')]
#[Signature('p:support:prune-attachments')]
class PruneSupportAttachmentsCommand extends Command
{
    public function handle(SupportAttachmentPruner $pruner): int
    {
        $this->components->twoColumnDetail('Support attachments pruned', (string) $pruner->prune());

        return self::SUCCESS;
    }
}
