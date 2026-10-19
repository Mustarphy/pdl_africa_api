<?php

namespace App\Http\Requests\Admin;

use App\Enums\CatalogStatus;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware enforces can:manage-catalog.
    }

    public function rules(): array
    {
        $category = $this->route('category');
        $category = $category instanceof Category ? $category : null;

        return [
            'name' => [$category ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:'.ProductRequest::SLUG, Rule::unique('categories', 'slug')->ignore($category)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::enum(CatalogStatus::class)],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
