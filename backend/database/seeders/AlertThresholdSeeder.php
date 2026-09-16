<?php

namespace Database\Seeders;

use App\Models\AlertThreshold;
use App\Models\Department;
use App\Models\PriorityLevel;
use Illuminate\Database\Seeder;

/**
 * Phase 8 — reasonable defaults, the same threshold_minutes across every
 * department for now; an Administrator can adjust per-department later
 * (out of scope for this phase — no admin UI for editing thresholds yet,
 * only for viewing the alerts these produce).
 */
class AlertThresholdSeeder extends Seeder
{
    private const THRESHOLD_MINUTES_BY_PRIORITY = [
        'Critical' => 10,
        'High' => 20,
        'Medium' => 30,
        'Normal' => 45,
    ];

    public function run(): void
    {
        $departments = Department::all();
        $priorityLevels = PriorityLevel::all();

        foreach ($departments as $department) {
            foreach ($priorityLevels as $priorityLevel) {
                $minutes = self::THRESHOLD_MINUTES_BY_PRIORITY[$priorityLevel->name] ?? 45;

                AlertThreshold::updateOrCreate(
                    ['department_id' => $department->id, 'priority_level_id' => $priorityLevel->id],
                    ['threshold_minutes' => $minutes]
                );
            }
        }
    }
}
