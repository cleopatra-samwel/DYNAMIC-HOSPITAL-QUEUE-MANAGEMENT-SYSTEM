<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['patient_number', 'name', 'date_of_birth', 'gender', 'contact'])]
class Patient extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }
}
