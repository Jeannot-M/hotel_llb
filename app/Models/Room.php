<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'type',
        'price_night',
        'price_passage',
        'status',
        'guest_name',
        'guest_phone',
        'guest_address',
        'companion_name',
        'stay_duration',
        'stay_type',
        'checked_in_at',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
    ];

    /**
     * Vérifie si l'occupation dépasse la durée prévue
     */
    public function isOverstayed(): bool
    {
        if ($this->status !== 'occupé' || !$this->checked_in_at) {
            return false;
        }

        $checkoutTime = null;
        if ($this->stay_type === 'passage') {
            // Un passage est estimé à 4 heures
            $checkoutTime = $this->checked_in_at->addHours(4);
        } else {
            // Une nuitée se termine à midi le jour suivant la fin du séjour
            $checkoutTime = $this->checked_in_at->addDays($this->stay_duration)->startOfDay()->addHours(12);
        }

        return now()->gt($checkoutTime);
    }
}
