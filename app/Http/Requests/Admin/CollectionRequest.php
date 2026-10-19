<?php

namespace App\Http\Requests\Admin;

use App\Models\ProductCollection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CollectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware enforces can:manage-catalog.
    }

    private function collection(): ?ProductCollection
    {
        $collection = $this->route('collection');

        return $collection instanceof ProductCollection ? $collection : null;
    }

    public function rules(): array
    {
        $collection = $this->collection();

        return [
            'name' => [$collection ? 'sometimes' : 'required', 'string', 'max:120'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:140', 'regex:'.ProductRequest::SLUG, Rule::unique('collections', 'slug')->ignore($collection)],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'cover_image_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'banner_image_alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'],
            'legacy_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/', Rule::unique('collections', 'legacy_key')->ignore($collection)],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $collection = $this->collection();
            if ($collection?->published_at && $this->filled('slug') && $this->input('slug') !== $collection->slug) {
                $validator->errors()->add('slug', 'The URL of a collection that has been published cannot be changed.');
            }
        }];
    }
}
