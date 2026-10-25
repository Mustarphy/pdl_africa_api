<?php

namespace App\Http\Requests\Admin;

use App\Enums\VariantAvailability;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create/update a product with its collections and variants. */
class ProductRequest extends FormRequest
{
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const SKU = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public function authorize(): bool
    {
        return true; // Route middleware enforces can:manage-catalog.
    }

    private function product(): ?Product
    {
        $product = $this->route('product');

        return $product instanceof Product ? $product : null;
    }

    public function rules(): array
    {
        $product = $this->product();
        $required = $product ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:180', 'regex:'.self::SLUG, Rule::unique('products', 'slug')->ignore($product)],
            'sku' => [$required, 'string', 'max:64', 'regex:'.self::SKU, Rule::unique('products', 'sku')->ignore($product)],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')],
            'garment_type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'material' => ['sometimes', 'nullable', 'string', 'max:160'],
            'care_instructions' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // Minor units (kobo). Upper bound guards against typos/overflow.
            'price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'],
            'is_featured' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'seo_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'seo_description' => ['sometimes', 'nullable', 'string', 'max:170'],
            'legacy_key' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/', Rule::unique('products', 'legacy_key')->ignore($product)],

            'collection_ids' => ['sometimes', 'array', 'max:20'],
            'collection_ids.*' => ['integer', 'distinct', Rule::exists('collections', 'id')],
            'occasion_ids' => ['sometimes', 'array', 'max:20'],
            'occasion_ids.*' => ['integer', 'distinct', Rule::exists('occasions', 'id')],

            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*.id' => ['sometimes', 'nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'regex:'.self::SKU, 'distinct:ignore_case'],
            'variants.*.size' => ['sometimes', 'nullable', 'string', 'max:40'],
            'variants.*.color' => ['sometimes', 'nullable', 'string', 'max:60'],
            'variants.*.color_hex' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'variants.*.price' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000000000'],
            'variants.*.stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'variants.*.availability' => ['sometimes', Rule::enum(VariantAvailability::class)],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $product = $this->product();

            // Published URLs must stay stable.
            if ($product?->published_at && $this->filled('slug') && $this->input('slug') !== $product->slug) {
                $validator->errors()->add('slug', 'The URL of a product that has been published cannot be changed.');
            }

            $variants = collect($this->input('variants', []));

            $ownIds = $product ? $product->variants()->pluck('id')->all() : [];
            foreach ($variants as $i => $variant) {
                if (! empty($variant['id']) && ! in_array((int) $variant['id'], $ownIds, true)) {
                    $validator->errors()->add("variants.{$i}.id", 'This variant does not belong to the product.');
                }
            }

            $combos = $variants->map(fn ($v) => mb_strtolower(trim((string) ($v['size'] ?? ''))).'|'.mb_strtolower(trim((string) ($v['color'] ?? ''))));
            foreach ($combos->duplicates() as $i => $combo) {
                $validator->errors()->add("variants.{$i}.size", 'Each size and colour combination may only appear once.');
            }

            $skus = $variants->pluck('sku')->filter()->all();
            if ($skus) {
                $taken = ProductVariant::whereIn('sku', $skus)
                    ->when($product, fn ($q) => $q->where('product_id', '!=', $product->id))
                    ->pluck('sku')->map(fn ($s) => mb_strtolower($s))->all();
                foreach ($variants as $i => $variant) {
                    if (in_array(mb_strtolower((string) ($variant['sku'] ?? '')), $taken, true)) {
                        $validator->errors()->add("variants.{$i}.sku", 'This variant SKU is already used by another product.');
                    }
                }
            }
        }];
    }
}
