<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'product_scope',
        'assigned_stage',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function getProductScope(): ?string
    {
        return $this->product_scope;
    }

    /**
     * Get operational display title (nickname) for UI header / user card.
     */
    public function getOperationalTitle(): string
    {
        if ($this->hasRole('spv') && ! empty($this->assigned_stage)) {
            $stageMap = [
                'netto' => 'SPV NETTO',
                'bubut_od' => 'SPV BUBUT OD',
                'bubut_cnc' => 'SPV BUBUT CNC',
                'bor' => 'SPV BOR',
                'qc' => 'SPV QC',
                'gudang_jadi' => 'SPV GUDANG JADI',
            ];

            return $stageMap[$this->assigned_stage] ?? 'SPV '.strtoupper(str_replace('_', ' ', $this->assigned_stage));
        }

        return explode(' ', $this->name ?? 'User')[0];
    }

    /**
     * Get operational domain subtitle for UI header / user card.
     */
    public function getOperationalSubtitle(): string
    {
        if ($this->hasRole('spv')) {
            return 'FLANGE';
        }

        $roleName = $this->roles->pluck('name')->first() ?? 'User';

        return $roleName.($this->product_scope ? ' - '.$this->product_scope : '');
    }
}
