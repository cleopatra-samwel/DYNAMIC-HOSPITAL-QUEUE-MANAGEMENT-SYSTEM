<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per checkbox a Doctor ticks on the categorized Laboratory request checklist — see RequestedTestController. */
#[Fillable(['service_id', 'lab_test_catalog_id', 'result_value', 'reference_range', 'status'])]
class ServiceRequestedTest extends Model
{
    protected $table = 'service_requested_tests';

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTestCatalog::class, 'lab_test_catalog_id');
    }
}
