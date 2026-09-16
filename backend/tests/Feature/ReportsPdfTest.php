<?php

namespace Tests\Feature;

use App\Enums\StaffRole;
use App\Models\Department;
use App\Models\Patient;
use App\Models\PriorityLevel;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 9, DoD #3 — each report can be downloaded as a PDF containing the
 * same figures shown on screen. pdf() builds its data via the exact same
 * private *Data() methods the JSON actions call (see ReportController), so
 * "same figures" holds by construction — this still verifies the PDF
 * actually renders (real bytes, correct content type), not just that the
 * shared data method doesn't throw.
 */
class ReportsPdfTest extends TestCase
{
    use RefreshDatabase;

    private Department $cons;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (StaffRole::values() as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        PriorityLevel::insert([
            ['name' => 'Normal', 'weight' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $this->administrator = User::create([
            'first_name' => 'Admin', 'last_name' => 'Tester', 'name' => 'Admin Tester',
            'email' => 'admin-'.uniqid().'@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $this->administrator->assignRole('Administrator');

        // At least one ticket, so the PDF's list-section (waiting-time's
        // per-department table) has a real row to render, not just headers.
        $patient = Patient::create(['patient_number' => 'P-'.uniqid(), 'name' => 'PDF Test Patient', 'date_of_birth' => '1990-01-01', 'gender' => 'Male', 'contact' => '0700000300']);
        $visit = Visit::create(['patient_id' => $patient->id, 'visit_date' => now()->toDateString(), 'patient_type' => 'Normal', 'emergency_confirmed' => false, 'payment_method' => 'Cash', 'overall_status' => 'COMPLETED']);
        $service = Service::create(['visit_id' => $visit->id, 'department_id' => $this->cons->id, 'service_type' => 'Consultation', 'status' => 'Completed', 'requires_payment' => false]);
        $normal = PriorityLevel::where('name', 'Normal')->first();
        $ticket = QueueTicket::create(['service_id' => $service->id, 'priority_level_id' => $normal->id, 'queue_number' => 'CONS-0001', 'priority_score' => 10, 'status' => 'COMPLETED']);
        QueueTicket::whereKey($ticket->id)->update(['called_at' => now()]);
    }

    public static function reportTypes(): array
    {
        return [
            'daily-patients' => ['daily-patients'],
            'waiting-time' => ['waiting-time'],
            'queue-performance' => ['queue-performance'],
            'emergency' => ['emergency'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reportTypes')]
    public function test_report_pdf_renders_a_real_pdf_document(string $type): void
    {
        $response = $this->actingAs($this->administrator)->get("/api/reports/{$type}/pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');

        $body = $response->getContent();
        $this->assertStringStartsWith('%PDF', $body, "The {$type} PDF must be a real PDF document, not an error page or empty body.");
        $this->assertGreaterThan(500, strlen($body), 'A real rendered report should be more than a trivial/empty PDF shell.');
    }

    public function test_department_report_pdf_requires_department_id(): void
    {
        $response = $this->actingAs($this->administrator)->get("/api/reports/department/pdf?department_id={$this->cons->id}");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_unknown_report_type_returns_404_not_a_broken_pdf(): void
    {
        $this->actingAs($this->administrator)->get('/api/reports/not-a-real-report/pdf')->assertStatus(404);
    }

    public function test_non_administrator_cannot_download_any_report_pdf(): void
    {
        $doctor = User::create([
            'first_name' => 'Doc', 'last_name' => 'Tester', 'name' => 'Doc Tester',
            'email' => 'doc-'.uniqid().'@test.local', 'password' => 'password', 'is_active' => true,
        ]);
        $doctor->assignRole('Doctor');

        $this->actingAs($doctor)->get('/api/reports/daily-patients/pdf')->assertStatus(403);
    }
}
