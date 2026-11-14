<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ClassifyTextRequest extends FormRequest
{
    public const MAX_LENGTH = 5000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }
}
