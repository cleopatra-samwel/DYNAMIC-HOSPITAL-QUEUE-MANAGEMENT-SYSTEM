<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an itemized Payment — catalog_item_id is not a real FK
 * (see the migration) since it points into lab_test_catalog OR
 * medication_catalog depending on catalog_type, never both.
 */
#[Fillable(['payment_id', 'catalog_type', 'catalog_item_id', 'custom_label', 'amount'])]
class PaymentItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
