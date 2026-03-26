<?php

namespace Statikbe\FilamentVoight\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncLockFileRequest extends FormRequest
{
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
            'project_code' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'max:255'],
            'lockfiles' => ['required', 'array', 'min:1'],
            'lockfiles.*' => ['file'],
        ];
    }
}
