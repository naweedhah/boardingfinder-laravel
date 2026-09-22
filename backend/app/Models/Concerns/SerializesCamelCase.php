<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * The React client (and the original Node/Prisma API) speaks camelCase JSON, while the
 * database follows Laravel's snake_case convention. This trait converts attribute keys on
 * the way out so controllers can return models directly without hand-written mappers.
 *
 * Relation keys are already camelCase because they use the relation method name
 * (see `Model::$snakeAttributes = false` in AppServiceProvider).
 */
trait SerializesCamelCase
{
    /**
     * Columns whose camelCase form is not what `Str::camel` would produce.
     *
     * @var array<string, string>
     */
    protected static array $serializedKeyOverrides = [
        'applicant_nic' => 'applicantNIC',
    ];

    public function attributesToArray(): array
    {
        $attributes = [];

        foreach (parent::attributesToArray() as $key => $value) {
            $attributes[static::$serializedKeyOverrides[$key] ?? Str::camel($key)] = $value;
        }

        return $attributes;
    }
}
