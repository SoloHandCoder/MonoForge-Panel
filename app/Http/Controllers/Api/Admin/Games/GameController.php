<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Api\Admin\Games;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Knuckles\Scribe\Attributes\Endpoint;
use Knuckles\Scribe\Attributes\Group;
use Knuckles\Scribe\Attributes\Response as ScribeResponse;
use Knuckles\Scribe\Attributes\Subgroup;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Extensions\Scribe\Attributes\ResponseFromResource;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Http\Controllers\Api\Admin\AdminApiController;
use Pterodactyl\Http\Requests\Api\Admin\Games\DeleteGameRequest;
use Pterodactyl\Http\Requests\Api\Admin\Games\GetGamesRequest;
use Pterodactyl\Http\Requests\Api\Admin\Games\StoreGameRequest;
use Pterodactyl\Http\Requests\Api\Admin\Games\UpdateGameRequest;
use Pterodactyl\Http\Resources\Api\Admin\GameResource;
use Pterodactyl\Http\Resources\Api\ApiResourceCollection;
use Pterodactyl\Models\Game;
use Pterodactyl\Services\Games\GameService;

/**
 * Admin > Games: which eggs run which game. A server whose egg a game claims gets that game's
 * pages and helpers (Minecraft's plugins and versions, Terraria's world settings), and, when
 * the game says so, playit.gg tunnels. Eggs no game claims fall back to the built-in detection.
 */
#[Group('Admin API', 'Root administrator endpoints for managing panel configuration and resources.')]
#[Subgroup('Games', 'The games eggs belong to, which decide the game-specific features and tunnels of their servers.')]
class GameController extends AdminApiController
{
    private const array BUILT_IN_ERROR = [
        'errors' => [
            [
                'code' => 'DisplayException',
                'status' => '400',
                'detail' => 'Built-in games cannot be deleted.',
            ],
        ],
    ];

    public function __construct(private readonly GameService $games) {}

    /**
     * List games.
     */
    #[Endpoint('List games', 'Returns every game, built-in ones first, with the eggs each claims and the eggs the built-in detection gives it.')]
    #[ResponseFromResource(GameResource::class, Game::class, description: 'Games returned.', collection: true, resourceKey: 'game')]
    public function index(GetGamesRequest $request): ApiResourceCollection
    {
        return GameResource::collection(Game::query()->orderByDesc('built_in')->orderBy('name')->get());
    }

    /**
     * Show game.
     */
    #[Endpoint('Get game', 'Returns a single game by ID.')]
    #[ResponseFromResource(GameResource::class, Game::class, description: 'Game returned.', resourceKey: 'game')]
    public function show(GetGamesRequest $request, Game $game): GameResource
    {
        return GameResource::make($game);
    }

    /**
     * Create game.
     */
    #[Endpoint('Create game', 'Creates a custom game. Listed eggs move to it from any other game.')]
    #[ResponseFromResource(GameResource::class, Game::class, status: 201, description: 'Game created.', resourceKey: 'game')]
    public function store(StoreGameRequest $request): JsonResponse
    {
        $game = $this->games->create($request->validated());

        Activity::event('admin:game.create')
            ->property('name', $game->name)
            ->log();

        return GameResource::make($game)->respond(Response::HTTP_CREATED);
    }

    /**
     * Update game.
     */
    #[Endpoint('Update game', "Updates a game. `egg_ids`, when sent, becomes the game's full egg list: listed eggs move to it from any other game and eggs left out are released to the built-in detection.")]
    #[ResponseFromResource(GameResource::class, Game::class, description: 'Game updated.', resourceKey: 'game')]
    public function update(UpdateGameRequest $request, Game $game): GameResource
    {
        $game = $this->games->update($game, $request->validated());

        Activity::event('admin:game.update')
            ->property('name', $game->name)
            ->log();

        return GameResource::make($game);
    }

    /**
     * Delete game.
     *
     * @throws DisplayException
     */
    #[Endpoint('Delete game', 'Deletes a custom game. Its eggs fall back to the built-in detection. Built-in games cannot be deleted.')]
    #[ScribeResponse(status: 204, description: 'Game deleted.')]
    #[ScribeResponse(self::BUILT_IN_ERROR, status: 400, description: 'The game is built in.')]
    public function destroy(DeleteGameRequest $request, Game $game): Response
    {
        $this->games->delete($game);

        Activity::event('admin:game.delete')
            ->property('name', $game->name)
            ->log();

        return $this->returnNoContent();
    }
}
