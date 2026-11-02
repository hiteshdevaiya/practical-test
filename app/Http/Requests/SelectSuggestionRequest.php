<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the suggestion selection payload.
 */
final class SelectSuggestionRequest extends FormRequest
{
    /**
     * Any authenticated API client may select.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules for the select endpoint.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'selection_token' => ['required', 'string', 'max:4096'],
        ];
    }
}
