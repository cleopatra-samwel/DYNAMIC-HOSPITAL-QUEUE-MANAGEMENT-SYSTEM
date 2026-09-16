<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Administrator-managed price list Billing selects from for a Pharmacy service's payment items (see PaymentItem, PaymentController::verify). */
#[Fillable(['name', 'price', 'active'])]
class MedicationCatalog extends Model
{
    use HasFactory;

    protected $table = 'medication_catalog';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }
}
