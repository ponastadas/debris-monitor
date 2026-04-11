<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Guard is handled by EnsureIsAdmin middleware
    }

    public function rules(): array
    {
        return [
            'name'   => ['sometimes', 'string', 'max:100'],
            'role'   => ['sometimes', 'in:user,admin'],
            'status' => ['sometimes', 'in:active,suspended'],
        ];
    }
}
