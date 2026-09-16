<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // counter_number: exact numbers don't matter, just distinct and
        // consistent with whatever physical signage a real hospital has.
        // location_phrase_type/append_doctor_phrase (Voice Announcements
        // v2): which location word is spoken for that counter_number, and
        // whether "Daktari" follows it — Consultation is the only
        // room+doctor department, everything else is called by number
        // alone (room or window, per the confirmed mapping).
        $departments = [
            ['dept_code' => 'REG', 'dept_name' => 'Registration', 'counter_number' => 1, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false],
            ['dept_code' => 'CONS', 'dept_name' => 'Consultation', 'counter_number' => 2, 'location_phrase_type' => 'room', 'append_doctor_phrase' => true],
            ['dept_code' => 'LAB', 'dept_name' => 'Laboratory', 'counter_number' => 3, 'location_phrase_type' => 'room', 'append_doctor_phrase' => false],
            ['dept_code' => 'PHARM', 'dept_name' => 'Pharmacy', 'counter_number' => 4, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false],
            ['dept_code' => 'BILL', 'dept_name' => 'Billing', 'counter_number' => 5, 'location_phrase_type' => 'window', 'append_doctor_phrase' => false],
        ];

        foreach ($departments as $department) {
            // updateOrCreate (not firstOrCreate) so re-running this seeder
            // against a database that already has these 5 rows from an
            // earlier phase still backfills counter_number instead of
            // silently leaving it null.
            Department::updateOrCreate(
                ['dept_code' => $department['dept_code']],
                $department
            );
        }
    }
}