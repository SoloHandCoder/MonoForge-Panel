<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Subusers;

use Illuminate\Validation\ValidationException;
use Pterodactyl\Actions\Subusers\CreateSubuser;
use Pterodactyl\Enum\Permissions;
use Pterodactyl\Models\User;

class StoreSubuserRequest extends SubuserRequest
{
    public function permission(): string
    {
        return Permissions::UserCreate->value;
    }

    /**
     * The invitee is given by email, or as `friend`, the UUID of one of the requester's friends.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $rules = CreateSubuser::rules();

        return [
            ...$rules,
            'email' => ['required_without:friend', 'prohibits:friend', ...array_values(array_filter($rules['email'], fn (mixed $rule): bool => $rule !== 'required'))],
            'friend' => ['sometimes', 'string', 'uuid'],
        ];
    }

    /**
     * The email to invite: the one given, or that of the friend picked. A UUID that is not one of
     * the requester's friends is refused the same way whether or not it belongs to a user.
     *
     * @throws ValidationException
     */
    public function inviteeEmail(): string
    {
        if (! $this->filled('friend')) {
            return $this->string('email')->toString();
        }

        $friend = $this->user()->friends()->where('users.uuid', $this->string('friend')->toString())->first();
        if (! $friend instanceof User) {
            throw ValidationException::withMessages(['friend' => 'Pick one of your friends.']);
        }

        return $friend->email;
    }
}
