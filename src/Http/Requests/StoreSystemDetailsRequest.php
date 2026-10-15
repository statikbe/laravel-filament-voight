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
            'app' => ['nullable', 'array'],
            'app.environment' => ['nullable', 'string', 'max:255'],
            'app.debug_mode' => ['nullable', 'boolean'],
            'framework' => ['nullable', 'array'],
            'framework.name' => ['nullable', 'string', 'max:255'],
            'framework.version' => ['nullable', 'string', 'max:255'],
            'framework.groups' => ['nullable', 'array'],
            'server' => ['required', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (strlen($this->getContent()) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'Payload too large.');
        }
    }
}
