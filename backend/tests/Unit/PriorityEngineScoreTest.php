<?php

namespace Tests\Unit;

use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\Visit;
use App\Services\PriorityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriorityEngineScoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeWaitingTicket(PriorityLevel $level, int $minutesAgo): QueueTicket
    {
        $department = Department::firstOrCreate(
            ['dept_code' => 'LAB'],
            ['dept_name' => 'Laboratory', 'is_active' => true]
        );

        $patient = Patient::create([
            'patient_number' => 'P-'.uniqid(),
            'name' => 'Test Patient',
            'date_of_birth' => '1990-01-01',
            'gender' => 'Male',
            'contact' => '0000000000',
        ]);

        $visit = Visit::create([
            'patient_id' => $patient->id,
            'visit_date' => now()->toDateString(),
            'patient_type' => 'Normal',
            'emergency_confirmed' => false,
            'payment_method' => 'Cash',
            'overall_status' => 'WAITING_LAB',
        ]);

        $service = Service::create([
            'visit_id' => $visit->id,
            'department_id' => $department->id,
            'service_type' => 'Laboratory',
            'status' => 'Pending',
            'requires_payment' => true,
        ]);

        $ticket = QueueTicket::create([
            'service_id' => $service->id,
            'priority_level_id' => $level->id,
            'queue_number' => 'LAB-TEST',
            'priority_score' => $level->weight,
            'status' => 'WAITING',
        ]);

        $ticket->created_at = now()->subMinutes($minutesAgo);
        $ticket->save();

        return $ticket->fresh();
    }

    /**
     * The worked example from the Phase 4 spec: a Critical patient waiting
     * 5 minutes and a Normal patient waiting 50 minutes should both score
     * exactly 110, given weight (Critical=100, Normal=10) + waiting_minutes
     * * AGING_POINTS_PER_MINUTE (2).
     */
    public function test_critical_at_5_minutes_and_normal_at_50_minutes_both_score_110(): void
    {
        config(['queue_priority.aging_points_per_minute' => 2]);

        $critical = PriorityLevel::create(['name' => 'Critical', 'weight' => 100]);
        $normal = PriorityLevel::create(['name' => 'Normal', 'weight' => 10]);

        $engine = new PriorityEngine();

        $criticalTicket = $this->makeWaitingTicket($critical, 5);
        $normalTicket = $this->makeWaitingTicket($normal, 50);

        $this->assertSame(110, $engine->calculateScore($criticalTicket));
        $this->assertSame(110, $engine->calculateScore($normalTicket));
    }

    public function test_score_increases_with_waiting_time(): void
    {
        config(['queue_priority.aging_points_per_minute' => 2]);

        $high = PriorityLevel::create(['name' => 'High', 'weight' => 60]);
        $engine = new PriorityEngine();

        $freshTicket = $this->makeWaitingTicket($high, 0);
        $agedTicket = $this->makeWaitingTicket($high, 10);

        $this->assertSame(60, $engine->calculateScore($freshTicket));
        $this->assertSame(80, $engine->calculateScore($agedTicket));
    }
}
