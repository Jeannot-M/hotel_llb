<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Beverage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'purchase_price',
        'price',
        'stock',
        'min_stock',
    ];

    /**
     * Calcule la marge bénéficiaire en montant
     */
    public function getMarginAttribute(): int
    {
        return $this->price - $this->purchase_price;
    }

    /**
     * Calcule le pourcentage de marge
     */
    public function getMarginPercentageAttribute(): float
    {
        if ($this->purchase_price <= 0) return 0;
        return round((($this->price - $this->purchase_price) / $this->purchase_price) * 100, 1);
    }
}
