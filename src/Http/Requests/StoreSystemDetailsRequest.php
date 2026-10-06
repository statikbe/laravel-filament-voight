<?php

namespace Statikbe\FilamentVoight\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StoreSystemDetailsRequest extends FormRequest
{
    private const int MAX_BODY_BYTES = 256 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', 'max:255'],
            'collected_at' => ['required', 'date'],
            'versions' => ['nullable', 'array'],
            'versions.*' => ['nullable', 'string', 'max:255'],
            'server' => ['required', 'array'],
            'laravel' => ['nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (strlen($this->getContent()) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Payload too large.');
        }
    }
}
