<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\AlertThreshold;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\VerifiesPayments;
use Tests\TestCase;

/** Shared fixtures for Phase 8's notification-generation tests — same setup pattern as MultiDepartmentFlowTest. */
abstract class NotificationTestCase extends TestCase
{
    use RefreshDatabase;
    use VerifiesPayments;

    protected Department $cons;

    protected Department $lab;

    protected User $registrationStaff;

    protected User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::insert([
            ['name' => 'Critical', 'weight' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        // Long-Waiting Alert tests need real threshold rows — same values
        // AlertThresholdSeeder uses, kept in sync manually here since
        // RefreshDatabase migrates but never runs seeders.
        foreach ([$this->cons, $this->lab] as $department) {
            AlertThreshold::create(['department_id' => $department->id, 'priority_level_id' => PriorityLevel::where('name', 'Critical')->value('id'), 'threshold_minutes' => 10]);
            AlertThreshold::create(['department_id' => $department->id, 'priority_level_id' => PriorityLevel::where('name', 'Normal')->value('id'), 'threshold_minutes' => 45]);
        }

        $this->registrationStaff = $this->makeUser('Registration Staff');
        $this->doctor = $this->makeUser('Doctor');
    }

    protected function makeUser(string $role): User
    {
        $user = User::create([
            'first_name' => $role,
            'last_name' => 'Tester',
            'name' => "{$role} Tester",
            'email' => strtolower(str_replace([' ', '/'], '.', $role)).'-'.uniqid().'@test.local',
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array{0: Visit, 1: QueueTicket} */
    protected function makeWaitingVisitAndTicket(Department $department, bool $requiresPayment = false): array
    {
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'Notif Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '07'.rand(10000000, 99999999)]);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => "WAITING_{$department->dept_code}"]);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $department->id, 'service_type' => $department->dept_name, 'status' => 'Pending', 'requires_payment' => $requiresPayment]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => $department->dept_code.'-'.uniqid(), 'priority_score' => 10, 'status' => 'WAITING']);

        return [$visit, $ticket];
    }
}
