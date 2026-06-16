<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Initial Admin
        $admin = User::updateOrCreate(
            ['phone_number' => '+243000000000'],
            [
                'name' => 'Administrateur',
                'is_admin' => true,
                'password' => \Illuminate\Support\Facades\Hash::make('00000'),
            ]
        );

        // 2. Default Rooms
        $rooms = [
            ['number' => '101', 'type' => 'Suite Deluxe', 'price_night' => 150000, 'price_passage' => 75000, 'status' => 'disponible', 'guest_name' => null, 'guest_phone' => null, 'checked_in_at' => null],
            ['number' => '102', 'type' => 'Double Executive', 'price_night' => 100000, 'price_passage' => 50000, 'status' => 'disponible', 'guest_name' => null, 'guest_phone' => null, 'checked_in_at' => null],
            ['number' => '103', 'type' => 'Double Standard', 'price_night' => 75000, 'price_passage' => 40000, 'status' => 'disponible', 'guest_name' => null, 'guest_phone' => null, 'checked_in_at' => null],
            ['number' => '104', 'type' => 'Single Premium', 'price_night' => 60000, 'price_passage' => 30000, 'status' => 'nettoyage', 'guest_name' => null, 'guest_phone' => null, 'checked_in_at' => null],
            ['number' => '105', 'type' => 'Single Standard', 'price_night' => 50000, 'price_passage' => 25000, 'status' => 'occupé', 'guest_name' => 'Jean Dupont', 'guest_phone' => '+243 812 345 678', 'checked_in_at' => now()->subDay()->format('Y-m-d H:i:s')],
            ['number' => '106', 'type' => 'Suite Impériale', 'price_night' => 250000, 'price_passage' => 125000, 'status' => 'disponible', 'guest_name' => null, 'guest_phone' => null, 'checked_in_at' => null],
        ];

        foreach ($rooms as $room) {
            \App\Models\Room::updateOrCreate(['number' => $room['number']], $room);
        }

        // 3. Default Beverages
        $beverages = [
            ['name' => 'Heineken (33cl)', 'category' => 'Bière', 'purchase_price' => 1800, 'price' => 2500, 'stock' => 45, 'min_stock' => 15],
            ['name' => 'Coca-Cola (33cl)', 'category' => 'Soft', 'purchase_price' => 1000, 'price' => 1500, 'stock' => 80, 'min_stock' => 25],
            ['name' => "Jack Daniel's (70cl)", 'category' => 'Spiritueux', 'purchase_price' => 35000, 'price' => 45000, 'stock' => 8, 'min_stock' => 5],
            ['name' => "Jus d'Orange local", 'category' => 'Soft', 'purchase_price' => 1200, 'price' => 2000, 'stock' => 12, 'min_stock' => 15],
            ['name' => 'Château Margaux (75cl)', 'category' => 'Vin', 'purchase_price' => 60000, 'price' => 75000, 'stock' => 4, 'min_stock' => 5],
            ['name' => 'Eau Minérale (1.5L)', 'category' => 'Soft', 'purchase_price' => 600, 'price' => 1000, 'stock' => 120, 'min_stock' => 30],
        ];

        foreach ($beverages as $bev) {
            \App\Models\Beverage::updateOrCreate(['name' => $bev['name']], $bev);
        }

        // 4. Default Activity Logs
        $logs = [
            ['type' => 'stock', 'message' => 'Réception de 24x Coca-Cola en stock.', 'created_at' => now()->subHours(4)],
            ['type' => 'chambre', 'message' => 'Chambre 105 occupée par Jean Dupont.', 'created_at' => now()->subHours(2)],
        ];

        foreach ($logs as $log) {
            \App\Models\ActivityLog::create($log);
        }

        // 5. Default Transactions
        $transactions = [
            ['type' => 'entree', 'category' => 'Bar', 'amount' => 18000, 'description' => 'Vente: 12x Coca-Cola (33cl)', 'date' => now()->toDateString(), 'user_id' => $admin->id],
            ['type' => 'entree', 'category' => 'Chambre', 'amount' => 100000, 'description' => 'Check-out Chambre 102 - Marie Mwamba', 'date' => now()->toDateString(), 'user_id' => $admin->id],
            ['type' => 'sortie', 'category' => 'Approvisionnement', 'amount' => 24000, 'description' => 'Achat stock: +24x Coca-Cola (33cl)', 'date' => now()->subDay()->toDateString(), 'user_id' => $admin->id],
            ['type' => 'sortie', 'category' => 'Salaires', 'amount' => 80000, 'description' => 'Salaires personnel terrasse', 'date' => now()->subDays(3)->toDateString(), 'user_id' => $admin->id],
        ];

        foreach ($transactions as $tx) {
            \App\Models\Transaction::create($tx);
        }
    }
}
