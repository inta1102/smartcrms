<?php

namespace App\Http\Requests\Rkh;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRkhRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return (new StoreRkhRequest())->rules();
    }

    public function withValidator($validator): void
    {
        (new StoreRkhRequest())->withValidator($validator);
    }
}
