<?php

namespace App\Services\Catalog;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /** @param  list<string>  $changedFields  field names only, never values */
    public function record(?User $actor, string $action, Model $subject, array $changedFields = []): void
    {
        ActivityLog::create([
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => match (true) {
                $subject instanceof Product => 'product',
                $subject instanceof ProductCollection => 'collection',
                $subject instanceof Category => 'category',
                default => class_basename($subject),
            },
            'subject_id' => $subject->getKey(),
            'subject_label' => mb_substr((string) $subject->getAttribute('name'), 0, 160),
            'changed_fields' => array_values(array_diff($changedFields, ['updated_at', 'search_text'])) ?: null,
        ]);
    }
}
