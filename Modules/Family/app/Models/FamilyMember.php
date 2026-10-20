<?php

namespace Modules\Family\Models;

use App\Casts\DateOnly;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BCC\Models\BCCLeader;
use Modules\Family\Database\Factories\FamilyMemberFactory;
use Modules\Tenants\Models\Concerns\BelongsToTenant;

class FamilyMember extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    /**
     * Create a new factory instance for the model.
     *
     * @return Factory
     */
    protected static function newFactory()
    {
        return FamilyMemberFactory::new();
    }

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'family_members';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'tenant_id',
        'family_id',
        'person_id',
        'first_name',
        'middle_name',
        'last_name',
        'date_of_birth',
        'gender',
        'relationship_to_head',
        'marital_status',
        'phone',
        'email',
        'is_primary_contact',
        'baptism_date',
        'baptism_place',
        'baptism_godparent_primary',
        'baptism_godparent_secondary',
        'baptism_location_type',
        'baptism_church_name',
        'baptism_church_address',
        'baptism_priest_name',
        'baptism_priest_is_home',
        'first_communion_date',
        'first_communion_place',
        'confirmation_date',
        'confirmation_place',
        'marriage_date',
        'marriage_place',
        'marriage_spouse_name',
        'marriage_bride_full_name',
        'marriage_bride_father_name',
        'marriage_bride_mother_name',
        'marriage_bride_address',
        'marriage_bride_church_type',
        'marriage_bride_church_name',
        'marriage_bride_church_address',
        'marriage_groom_full_name',
        'marriage_groom_father_name',
        'marriage_groom_mother_name',
        'marriage_groom_address',
        'marriage_groom_church_type',
        'marriage_groom_church_name',
        'marriage_groom_church_address',
        'occupation',
        'education',
        'skills_talents',
        'notes',
        'status',
        'deceased_date',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'date_of_birth' => DateOnly::class,
        'baptism_date' => DateOnly::class,
        'first_communion_date' => DateOnly::class,
        'confirmation_date' => DateOnly::class,
        'marriage_date' => DateOnly::class,
        'deceased_date' => DateOnly::class,
        'is_primary_contact' => 'boolean',
        'baptism_priest_is_home' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = ['age', 'full_name_display'];

    /**
     * Get the family that this member belongs to.
     */
    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    /**
     * Canonical parish Person for this membership (ADR-24).
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Get BCC leadership roles for this member.
     */
    public function bccLeaderships(): HasMany
    {
        return $this->hasMany(BCCLeader::class, 'family_member_id');
    }

    /**
     * Get active BCC leadership roles for this member.
     */
    public function activeBccLeaderships(): HasMany
    {
        return $this->hasMany(BCCLeader::class, 'family_member_id')
            ->where('is_active', true);
    }

    /**
     * Get the user who created the member.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated the member.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope a query to only include active members.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Memberships that consume the parish PEOPLE_LIMIT subscription quota (active member, live family, not migrated).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeConsumingPeopleLimit($query)
    {
        return $query
            ->where('family_members.status', 'active')
            ->whereNull('family_members.deleted_at')
            ->whereHas('family', function (Builder $family): void {
                $family->whereNull('deleted_at')
                    ->where('status', '!=', 'migrated');
            });
    }

    /**
     * Scope a query to filter by gender.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeGender($query, string $gender)
    {
        return $query->where('gender', $gender);
    }

    /**
     * Scope a query to filter by relationship.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeRelationship($query, string $relationship)
    {
        return $query->where('relationship_to_head', $relationship);
    }

    /**
     * Scope a query to include baptized members.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeBaptized($query)
    {
        return $query->whereNotNull('baptism_date');
    }

    /**
     * Scope a query to include confirmed members.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeConfirmed($query)
    {
        return $query->whereNotNull('confirmation_date');
    }

    /**
     * Get the member's age.
     */
    public function getAgeAttribute(): ?int
    {
        if (! $this->date_of_birth) {
            return null;
        }

        return $this->date_of_birth->age;
    }

    /**
     * Get the member's full name.
     */
    public function getFullNameDisplayAttribute(): string
    {
        $parts = array_filter([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
        ]);

        return implode(' ', $parts);
    }

    /**
     * Check if the member is baptized.
     */
    public function isBaptized(): bool
    {
        return ! is_null($this->baptism_date);
    }

    /**
     * Check if the member is confirmed.
     */
    public function isConfirmed(): bool
    {
        return ! is_null($this->confirmation_date);
    }

    /**
     * Check if the member is married.
     */
    public function isMarried(): bool
    {
        return $this->marital_status === 'married';
    }

    /**
     * Check if the member is a BCC leader.
     */
    public function isBCCLeader(): bool
    {
        return $this->activeBccLeaderships()->exists();
    }
}
