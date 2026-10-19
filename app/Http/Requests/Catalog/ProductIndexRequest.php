<?php

namespace App\Http\Requests\Catalog;

use App\Services\Catalog\ProductSearch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates and bounds public catalog query parameters. */
class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept both ?size=M and ?size[]=M&size[]=L.
        foreach (['collection', 'category', 'garment', 'size', 'color'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => [$this->input($key)]]);
            }
        }
    }

    public function rules(): array
    {
        $list = ['sometimes', 'array', 'max:20'];
        $item = ['string', 'max:80'];

        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'collection' => $list, 'collection.*' => $item,
            'category' => $list, 'category.*' => $item,
            'garment' => $list, 'garment.*' => $item,
            'size' => $list, 'size.*' => $item,
            'color' => $list, 'color.*' => $item,
            'min_price' => ['sometimes', 'integer', 'min:0'],
            'max_price' => ['sometimes', 'integer', 'min:0', 'gte:min_price'],
            'featured' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(ProductSearch::SORTS)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('catalog.pagination.max')],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'facets' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $data = $this->validated();
        if (isset($data['featured'])) {
            $data['featured'] = $this->boolean('featured');
        }

        return collect($data)->only(['q', 'collection', 'category', 'garment', 'size', 'color', 'min_price', 'max_price', 'featured', 'sort'])->all();
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? config('catalog.pagination.default'));
    }
}
