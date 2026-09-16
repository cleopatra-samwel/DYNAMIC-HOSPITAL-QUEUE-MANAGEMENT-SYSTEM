<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Administrator-managed price list, reused for two purposes: Billing
 * selects from it for a Laboratory service's payment items (see
 * PaymentItem, PaymentController::verify), and it's also the source of
 * the categorized checklist a Doctor ticks on the Request Laboratory
 * form (see ServiceRequestedTest, RequestedTestController) — one catalog,
 * two uses, so the two can never drift apart.
 */
#[Fillable(['name', 'category', 'price', 'active'])]
class LabTestCatalog extends Model
{
    use HasFactory;

    protected $table = 'lab_test_catalog';

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }
}
