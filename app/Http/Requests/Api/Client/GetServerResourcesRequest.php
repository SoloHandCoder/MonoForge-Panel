<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client;

use Closure;
use Illuminate\Validation\Validator;

class GetServerResourcesRequest extends ClientApiRequest
{
    /** The most servers one request may ask for. */
    public const int MAX_SERVERS = 50;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return ValidationRules
     */
    public function rules(): array
    {
        return [
            'servers' => ['required', 'string', 'max:4096'],
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('servers')) {
                return;
            }

            if (count($this->identifiers()) > self::MAX_SERVERS) {
                $validator->errors()->add('servers', sprintf('No more than %d servers may be requested at once.', self::MAX_SERVERS));
            }
        }];
    }

    /**
     * The requested server identifiers (uuids, short uuids or identifiers), each once, in order.
     *
     * @return list<string>
     */
    public function identifiers(): array
    {
        $value = $this->query('servers');
        if (! is_string($value)) {
            return [];
        }

        $identifiers = array_filter(array_map(trim(...), explode(',', $value)), fn (string $identifier): bool => $identifier !== '');

        return array_values(array_unique($identifiers));
    }
}
