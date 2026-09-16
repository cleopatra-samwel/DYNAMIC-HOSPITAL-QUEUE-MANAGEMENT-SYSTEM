<?php

namespace Database\Seeders;

use App\Models\MedicationCatalog;
use Illuminate\Database\Seeder;

/**
 * A small starting set of common medications dispensed at a Tanzanian
 * hospital pharmacy — placeholder prices (TZS) for the Administrator to
 * adjust via the Medication Catalog admin page to actual pricing.
 */
class MedicationCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $medications = [
            ['name' => 'Paracetamol 500mg', 'price' => 1000],
            ['name' => 'Amoxicillin 500mg', 'price' => 3000],
            ['name' => 'Artemether-Lumefantrine (ALU/Coartem)', 'price' => 5000],
            ['name' => 'Metronidazole 400mg', 'price' => 2000],
            ['name' => 'Oral Rehydration Salts (ORS)', 'price' => 1500],
            ['name' => 'Ibuprofen 400mg', 'price' => 1500],
            ['name' => 'Diclofenac 50mg', 'price' => 2000],
            ['name' => 'Ciprofloxacin 500mg', 'price' => 4000],
            ['name' => 'Omeprazole 20mg', 'price' => 3500],
            ['name' => 'Cough Syrup', 'price' => 4500],
            ['name' => 'Multivitamins', 'price' => 3000],
            ['name' => 'Chlorpheniramine (Antihistamine)', 'price' => 1000],
        ];

        foreach ($medications as $medication) {
            MedicationCatalog::updateOrCreate(['name' => $medication['name']], $medication + ['active' => true]);
        }
    }
}
