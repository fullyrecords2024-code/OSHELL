<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicalEncounter extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'customer_id',
        'appointment_id',
        'template_id',
        'created_by',
        'status',
        'vitals',
        'assessment',
        'clinical_note',
        'treatment_plan',
        'follow_up_at',
        'follow_up_notes',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'vitals' => 'array',
            'assessment' => 'array',
            'follow_up_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ClinicalAssessmentTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ClinicalEncounterAmendment::class)
            ->orderByDesc('created_at');
    }
}
