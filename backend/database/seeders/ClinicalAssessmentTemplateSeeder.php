<?php

namespace Database\Seeders;

use App\Models\ClinicalAssessmentTemplate;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class ClinicalAssessmentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            ['key' => 'presenting_concern', 'label' => 'Presenting concern', 'type' => 'text', 'required' => true],
            ['key' => 'medical_history', 'label' => 'Relevant medical history', 'type' => 'text', 'required' => false],
            ['key' => 'skin_assessment', 'label' => 'Skin / treatment assessment', 'type' => 'text', 'required' => true],
            ['key' => 'risk_level', 'label' => 'Risk level', 'type' => 'select', 'required' => true, 'options' => ['low', 'moderate', 'high']],
            ['key' => 'contraindications_checked', 'label' => 'Contraindications reviewed', 'type' => 'boolean', 'required' => true],
        ];

        Organization::query()->where('is_active', true)->each(
            static fn (Organization $organization) => ClinicalAssessmentTemplate::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'name' => 'Skin care consultation',
                ],
                [
                    'description' => 'Standard consultation assessment. Record only information relevant to care.',
                    'fields' => $fields,
                    'is_active' => true,
                ],
            ),
        );
    }
}
