<?php

namespace Database\Seeders;

use App\Models\LabTestCatalog;
use Illuminate\Database\Seeder;

/**
 * A categorized starter set matching a standard hospital lab request
 * form — placeholder prices (TZS) for the Administrator to adjust via the
 * Lab Test Catalog admin page to whatever this hospital actually charges.
 * This is the SAME table the Doctor's categorized Request Laboratory
 * checklist and Billing's itemized charge picker both read from — one
 * catalog, two uses (see LabTestCatalog).
 *
 * Idempotent by name, same as before — running this again after the
 * older, uncategorized starter set was seeded doesn't remove or
 * re-categorize those rows, it only adds whatever's missing from this
 * list. Any pre-existing row not named here simply keeps category=null
 * (shown under "Other" wherever the checklist groups by category).
 */
class LabTestCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $tests = [
            'Hematology' => [
                'CBC, Platelet Count' => 12000,
                'Peripheral Smear' => 10000,
                'Reticulocyte Count' => 9000,
                'Blood Typing' => 7000,
                'Serum Iron' => 15000,
                'TIBC' => 15000,
                'Ferritin' => 20000,
            ],
            'Coagulation Tests' => [
                'PT with INR' => 15000,
                'aPTT' => 15000,
            ],
            'Urine Examination' => [
                'Urinalysis' => 6000,
                'Urine C&S' => 18000,
            ],
            'Stool Examination' => [
                'Fecalysis' => 6000,
                'Occult Blood' => 8000,
                'Fecal Immunochemical Test' => 10000,
                'Stool C&S' => 18000,
            ],
            'Clinical Chemistry' => [
                'BUN' => 8000,
                'Creatinine' => 8000,
                'Serum Sodium' => 8000,
                'Serum Chloride' => 8000,
                'Serum Potassium' => 8000,
                'Serum Magnesium' => 10000,
                'FBS' => 5000,
                'HbA1c' => 20000,
                'Total Cholesterol' => 10000,
                'LDL' => 10000,
                'HDL' => 10000,
                'Triglycerides' => 10000,
                'Uric Acid' => 8000,
                'TSH' => 20000,
                'FT3' => 20000,
                'FT4' => 20000,
                'AST' => 8000,
                'ALT' => 8000,
                'Total Bilirubin' => 8000,
            ],
            'Diagnostics' => [
                '12-Lead ECG' => 15000,
                '2D Echo with Doppler' => 60000,
                '24-h ABPM' => 40000,
                'Treadmill Stress Test' => 70000,
                'Chest Xray' => 25000,
            ],
            'Radiology' => [
                'Ultrasound' => 35000,
                'CT Scan' => 150000,
            ],
        ];

        foreach ($tests as $category => $items) {
            foreach ($items as $name => $price) {
                LabTestCatalog::updateOrCreate(['name' => $name], ['category' => $category, 'price' => $price, 'active' => true]);
            }
        }
    }
}
