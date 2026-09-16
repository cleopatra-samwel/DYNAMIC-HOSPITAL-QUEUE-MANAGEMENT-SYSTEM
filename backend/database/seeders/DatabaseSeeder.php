<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            DepartmentSeeder::class,
            StaffUserSeeder::class,
            PriorityLevelSeeder::class,
            AlertThresholdSeeder::class,
            AudioClipSeeder::class,
            LabTestCatalogSeeder::class,
            MedicationCatalogSeeder::class,
        ]);
    }
}