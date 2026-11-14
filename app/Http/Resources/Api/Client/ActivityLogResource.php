<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Resources\Api\Client;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Resources\Api\ApiResource;
use Pterodactyl\Http\Resources\Api\Concerns\FormatsActivityLogs;
use Pterodactyl\Http\Resources\Api\Includes\IncludedResource;
use Pterodactyl\Http\Resources\Api\NullResource;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\ActivityLogSubject;
use Pterodactyl\Models\User;

/**
 * @extends ClientResource<ActivityLog>
 */
#[ResponseField('ip', example: '192.0.2.10', nullable: true)]
#[ResponseField('description', example: 'Changed account email address.', nullable: true)]
#[ResponseField('properties', schema: ['type' => 'object', 'additionalProperties' => ['oneOf' => [['type' => 'string'], ['type' => 'integer'], ['type' => 'number'], ['type' => 'boolean'], ['type' => 'array', 'items' => ['type' => 'string']], ['type' => 'object', 'additionalProperties' => ['type' => 'string']]]], 'example' => ['old' => 'old-user@example.com', 'new' => 'new-user@example.com']])]
class ActivityLogResource extends ClientResource
{
    use FormatsActivityLogs;

    protected array $includeRelations = [
        'actor' => ['relation' => 'actor', 'resource' => UserResource::class],
    ];

    protected array $availableIncludes = ['actor'];

    public function resourceName(): string
    {
        return ActivityLog::RESOURCE_NAME;
    }

    public function toArray(Request $request): array
    {
        return $this->activityAttributes($this->resource, $this->canViewIP());
    }

    protected function resolveInclude(string $include): ?IncludedResource
    {
        return match ($include) {
            'actor' => $this->includeActor(),
            default => parent::resolveInclude($include),
        };
    }

    /**
     * @return ApiResource<User>|NullResource
     */
    private function includeActor(): ApiResource|NullResource
    {
        if (! $this->resource->actor instanceof User) {
            return $this->null();
        }

        return $this->item($this->resource->actor, UserResource::class, User::RESOURCE_NAME);
    }

    /**
     * Determines if the viewer may see the address of this entry: administrators can, and
     * everyone else only for entries they performed themselves. Entries performed by a
     * root administrator or without a user actor are additionally withheld by the model.
     */
    private function canViewIP(): bool
    {
        $user = $this->getUser();
        if ($user->root_admin) {
            return true;
        }

        if ($this->resource->isAnonymousAuthAttempt()) {
            return $this->resource->subjects->contains(
                fn (ActivityLogSubject $subject): bool => $subject->subject_type === $user->getMorphClass()
                    && $subject->subject_id === $user->getKey()
            );
        }

        $actor = $this->resource->actor;

        return $actor instanceof Model && $actor->is($user);
    }
}
