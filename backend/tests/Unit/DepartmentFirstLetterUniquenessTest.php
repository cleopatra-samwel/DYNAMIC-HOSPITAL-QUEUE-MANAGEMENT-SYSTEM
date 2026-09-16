<?php

namespace Tests\Unit;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * AnnouncementController speaks only the first letter of a queue_number's
 * dept_code prefix (see its class docblock) — two departments sharing a
 * first letter (e.g. "Consultation" and a future "Cardiology", both "C")
 * would be indistinguishable by voice. Enforced at the model layer so it
 * holds regardless of how a department is created (seeder, tinker, or a
 * future admin UI/API) since no create/edit endpoint exists yet.
 */
class DepartmentFirstLetterUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_department_whose_first_letter_is_already_taken_is_rejected(): void
    {
        Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        Department::create(['dept_code' => 'CARDIO', 'dept_name' => 'Cardiology', 'is_active' => true]);
    }

    public function test_creating_a_department_with_a_distinct_first_letter_succeeds(): void
    {
        Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $xray = Department::create(['dept_code' => 'XRAY', 'dept_name' => 'X-Ray', 'is_active' => true]);

        $this->assertNotNull($xray->id);
        $this->assertSame(2, Department::count());
    }

    public function test_updating_a_department_to_a_colliding_first_letter_is_rejected(): void
    {
        Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);
        $lab = Department::create(['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        $lab->update(['dept_code' => 'CARDIO']);
    }

    public function test_re_saving_a_department_unchanged_does_not_falsely_conflict_with_itself(): void
    {
        $cons = Department::create(['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'is_active' => true]);

        $cons->update(['counter_number' => 7]);

        $this->assertSame(7, $cons->fresh()->counter_number);
    }

    public function test_first_letter_comparison_is_case_insensitive(): void
    {
        Department::create(['dept_code' => 'cons', 'dept_name' => 'Consultation', 'is_active' => true]);

        $this->expectException(ValidationException::class);

        Department::create(['dept_code' => 'CARDIO', 'dept_name' => 'Cardiology', 'is_active' => true]);
    }
}
