<?php

namespace App\Http\Requests;

use App\Enums\BoardingType;
use App\Enums\PreferredTenantGender;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** The alert form posts blanks for unused filters and booleans as `true`/`"true"`. */
    protected function prepareForValidation(): void
    {
        $data = [];

        foreach ($this->all() as $key => $value) {
            $data[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        foreach (['wifiRequired', 'mealsRequired', 'attachedBathroomNeeded'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = is_bool($data[$flag]) ? $data[$flag] : ($data[$flag] === null ? null : $data[$flag] === 'true');
            }
        }

        $this->replace($data);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'city' => ['bail', 'required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'minBudget' => ['nullable', 'integer', 'min:0'],
            // `gte:` would reject a lone max budget, so compare by hand only when both are set.
            'maxBudget' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) {
                $min = $this->input('minBudget');

                if ($min !== null && $value !== null && (int) $value < (int) $min) {
                    $fail('Max budget must be greater than min budget.');
                }
            }],
            'preferredTenantGender' => ['nullable', Rule::enum(PreferredTenantGender::class)],
            'boardingType' => ['nullable', Rule::enum(BoardingType::class)],
            'minCapacity' => ['nullable', 'integer', 'min:1'],
            'wifiRequired' => ['nullable', 'boolean'],
            'mealsRequired' => ['nullable', 'boolean'],
            'attachedBathroomNeeded' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'city.required' => 'Preferred city is required.',
            'preferredTenantGender.enum' => 'Invalid preferred tenant gender.',
            'boardingType.enum' => 'Invalid boarding type.',
        ];
    }
}
