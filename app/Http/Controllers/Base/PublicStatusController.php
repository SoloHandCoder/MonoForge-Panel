<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Base;

use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Unauthenticated;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseField;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Services\Status\PublicStatusService;
use Pterodactyl\Services\Status\StatusPageSettings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public status page's data: no session, cached for 30 seconds, and nodes by display name only.
 */
#[Group('Public API', 'Endpoints anyone can read without signing in.', authenticated: false)]
class PublicStatusController extends Controller
{
    private const array STATE_SCHEMA = ['type' => 'string', 'enum' => ['operational', 'partial_outage', 'major_outage'], 'example' => 'partial_outage'];

    private const array COMPONENT_STATUS_SCHEMA = [
        'type' => 'string',
        'enum' => ['operational', 'outage', 'unknown'],
        'description' => 'The current state: unknown when it has not been checked in the last 5 minutes.',
        'example' => 'operational',
    ];

    private const array UPTIME_SCHEMA = ['type' => 'number', 'nullable' => true, 'description' => 'The 90-day uptime percentage, or null before the first check.', 'example' => 99.98];

    private const array DAYS_SCHEMA = [
        'type' => 'array',
        'description' => 'One entry per day for the last 90 days, oldest first.',
        'items' => [
            'type' => 'object',
            'required' => ['date', 'uptime', 'response_ms'],
            'properties' => [
                'date' => ['type' => 'string', 'example' => '2026-11-15'],
                'uptime' => ['type' => 'number', 'nullable' => true, 'description' => 'The uptime percentage that day, or null for a day without checks.', 'example' => 99.93],
                'response_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'The average panel response time that day; always null for nodes.', 'example' => 84],
            ],
        ],
    ];

    private const array PANEL_SCHEMA = [
        'type' => 'object',
        'required' => ['name', 'status', 'uptime', 'days', 'response_ms'],
        'properties' => [
            'name' => ['type' => 'string', 'example' => 'Panel'],
            'status' => self::COMPONENT_STATUS_SCHEMA,
            'uptime' => self::UPTIME_SCHEMA,
            'days' => self::DAYS_SCHEMA,
            'response_ms' => ['type' => 'integer', 'nullable' => true, 'description' => 'How long the panel last took to answer, in milliseconds.', 'example' => 84],
        ],
    ];

    private const array NODES_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['name', 'status', 'uptime', 'days'],
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'The public display name of the node.', 'example' => 'EU Node 1'],
                'status' => self::COMPONENT_STATUS_SCHEMA,
                'uptime' => self::UPTIME_SCHEMA,
                'days' => self::DAYS_SCHEMA,
            ],
        ],
    ];

    private const array INCIDENTS_SCHEMA = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'title', 'body', 'level', 'started_at'],
            'properties' => [
                'id' => ['type' => 'integer', 'example' => 3],
                'title' => ['type' => 'string', 'example' => 'EU Node 2 is down'],
                'body' => ['type' => 'string', 'example' => 'We are looking into it.'],
                'level' => ['type' => 'string', 'enum' => ['warning', 'danger'], 'example' => 'danger'],
                'started_at' => ['type' => 'string', 'nullable' => true, 'example' => '2026-11-15T11:40:00+00:00'],
            ],
        ],
    ];

    private const array DAY_EXAMPLE = ['date' => '2026-11-15', 'uptime' => 99.93, 'response_ms' => 84];

    private const array NODE_DAY_EXAMPLE = ['date' => '2026-11-15', 'uptime' => 99.95, 'response_ms' => null];

    private const array STATUS_EXAMPLE = [
        'state' => 'partial_outage',
        'checked_at' => '2026-11-15T12:00:00+00:00',
        'panel' => [
            'name' => 'Panel',
            'status' => 'operational',
            'uptime' => 99.98,
            'days' => [self::DAY_EXAMPLE],
            'response_ms' => 84,
        ],
        'nodes' => [
            ['name' => 'EU Node 1', 'status' => 'operational', 'uptime' => 99.99, 'days' => [self::NODE_DAY_EXAMPLE]],
            ['name' => 'EU Node 2', 'status' => 'outage', 'uptime' => 97.5, 'days' => [self::NODE_DAY_EXAMPLE]],
        ],
        'incidents' => [
            ['id' => 3, 'title' => 'EU Node 2 is down', 'body' => 'We are looking into it.', 'level' => 'danger', 'started_at' => '2026-11-15T11:40:00+00:00'],
        ],
    ];

    private const array NOT_FOUND_ERROR = [
        'errors' => [
            [
                'code' => 'NotFoundHttpException',
                'status' => '404',
                'detail' => 'The requested resource could not be found on the server.',
            ],
        ],
    ];

    #[Unauthenticated]
    #[Endpoint('Get public status', 'Returns the overall state, the panel and every node shown on the status page with 90 daily uptime bars, the panel response time, and the warning or danger announcements as incidents. Cached for 30 seconds. Nodes are named by their public display name only.')]
    #[ResponseField('state', 'string', 'The overall state: the panel down or every node down is a major outage, some nodes down a partial one.', schema: self::STATE_SCHEMA)]
    #[ResponseField('checked_at', 'string', 'When the panel was last checked, or null when the checks have stopped.', nullable: true)]
    #[ResponseField('panel', 'object', 'The panel itself, with how long it last took to answer.', schema: self::PANEL_SCHEMA)]
    #[ResponseField('nodes', 'object[]', 'The nodes shown on the status page, by display name.', schema: self::NODES_SCHEMA)]
    #[ResponseField('incidents', 'object[]', 'Announcements of level warning or danger that are showing now.', schema: self::INCIDENTS_SCHEMA)]
    #[ScribeResponse(self::STATUS_EXAMPLE, description: 'Status returned.')]
    #[ScribeResponse(self::NOT_FOUND_ERROR, status: 404, description: 'The status page is turned off.')]
    public function __invoke(PublicStatusService $status): JsonResponse
    {
        throw_unless(StatusPageSettings::enabled(), NotFoundHttpException::class);

        return new JsonResponse($status->payload());
    }
}
