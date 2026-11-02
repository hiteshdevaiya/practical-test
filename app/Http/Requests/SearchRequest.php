<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates autocomplete search input.
 */
final class SearchRequest extends FormRequest
{
    /**
     * Any authenticated API client may search.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for the search endpoint.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:'.config('search.max_query_length', 100)],
            'limit' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.config('search.max_limit', 20),
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'The q parameter is required.',
            'q.max' => 'The query may not be longer than the configured limit.',
            'limit.max' => 'The limit may not exceed the configured maximum.',
        ];
    }
}
