<?php

namespace App\Http\Requests\Admin;

use App\Models\Occasion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OccasionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware enforces can:manage-catalog.
    }

    private function occasion(): ?Occasion
    {
        $occasion = $this->route('occasion');

        return $occasion instanceof Occasion ? $occasion : null;
    }

    public function rules(): array
    {
        $occasion = $this->occasion();

        return [
            'name' => [$occasion ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:'.ProductRequest::SLUG, Rule::unique('occasions', 'slug')->ignore($occasion)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'image_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'product_ids' => ['sometimes', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $occasion = $this->occasion();
            if ($occasion?->published_at && $this->filled('slug') && $this->input('slug') !== $occasion->slug) {
                $validator->errors()->add('slug', 'The URL of an occasion that has been published cannot be changed.');
            }
        }];
    }
}
