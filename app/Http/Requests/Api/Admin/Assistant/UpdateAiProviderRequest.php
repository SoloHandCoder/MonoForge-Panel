<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Requests\Api\Admin\Assistant;

class UpdateAiProviderRequest extends StoreAiProviderRequest
{
    /**
     * Every field is optional; what is left out stays as it is.
     *
     * @return ValidationRules
     */
    public function rules(): array
    {
        $rules = [];
        foreach (parent::rules() as $field => $fieldRules) {
            $rules[$field] = is_array($fieldRules)
                ? array_map(fn (mixed $rule): mixed => $rule === 'required' ? 'sometimes' : $rule, $fieldRules)
                : $fieldRules;
        }

        return $rules;
    }
}
