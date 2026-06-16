<?php

use Livewire\Volt\Component;
use App\Models\Room;
use App\Models\Beverage;
use App\Models\ActivityLog;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.crm')] class extends Component {
    // Modals visibility
    public bool $showMobileMenu = false;
    public bool $showExpenseModal = false;
    public bool $showBookModal = false;
    public bool $showCheckoutModal = false;
    public bool $showStockModal = false;
    public bool $showSellModal = false;
    public bool $showInventoryModal = false;

    // Active selection for modals
    public ?int $selectedRoomId = null;
    public ?int $selectedBeverageId = null;

    // Form inputs
    public string $guestName = '';
    public string $stayType = 'jour'; // 'jour' ou 'passage'
    public int $stayDuration = 1;
    
    // Stock entry inputs
    public int $addQuantity = 0;
    public int $purchaseCost = 0;
    
    // Inventory edit arrays
    public array $inventoryStocks = [];

    // Sell quantity input
    public $sellQuantity = 1;

    // Expense inputs
    public string $expenseCategory = 'Autres';
    public int $expenseAmount = 0;
    public string $expenseDescription = '';

    public function getRoomsProperty()
    {
        return Room::orderBy('number')->get();
    }

    public function getBeveragesProperty()
    {
        return Beverage::orderBy('name')->get();
    }

    public function getHistoryProperty()
    {
        $query = ActivityLog::orderBy('created_at', 'desc');

        if (!auth()->user()?->isAdmin()) {
            $userId = auth()->id();
            $userName = auth()->user()?->name;
            $query->where(function($q) use ($userId, $userName) {
                $q->where('user_id', $userId)
                  ->orWhere(function($sub) use ($userName) {
                      $sub->whereNull('user_id')
                          ->where('message', 'like', '%' . $userName . '%');
                  });
            });
        }

        return $query->take(30)->get();
    }

    public function getUserStatsProperty()
    {
        $users = User::orderBy('name')->get();

        // Si l'utilisateur n'est pas admin, il ne voit que ses propres statistiques
        if (!auth()->user()?->isAdmin()) {
            $users = collect([auth()->user()]);
        }

        $stats = [];
        $today = now()->toDateString();

        foreach ($users as $user) {
            // Terrasse (Bar)
            $terrToday = Transaction::where('user_id', $user->id)
                ->where('category', 'Bar')
                ->where('type', 'entree')
                ->whereDate('date', today())
                ->sum('amount');
            $terrTodayCount = Transaction::where('user_id', $user->id)
                ->where('category', 'Bar')
                ->where('type', 'entree')
                ->whereDate('date', today())
                ->count();
            
            // Calculer la quantité totale réelle d'unités vendues aujourd'hui
            $terrTransactions = Transaction::where('user_id', $user->id)
                ->where('category', 'Bar')
                ->where('type', 'entree')
                ->whereDate('date', today())
                ->get();
            $terrTodayQty = 0;
            foreach ($terrTransactions as $t) {
                if (preg_match('/Vente directe bar: (\d+)x/i', $t->description, $matches)) {
                    $terrTodayQty += (int)$matches[1];
                } else if ($terrTodayCount > 0) {
                    $terrTodayQty += 1;
                }
            }

            $terrGlobal = Transaction::where('user_id', $user->id)
                ->where('category', 'Bar')
                ->where('type', 'entree')
                ->sum('amount');

            // Hébergement (Chambre)
            $hebToday = Transaction::where('user_id', $user->id)
                ->where('category', 'Chambre')
                ->where('type', 'entree')
                ->whereDate('date', today())
                ->sum('amount');
            $hebTodayCount = Transaction::where('user_id', $user->id)
                ->where('category', 'Chambre')
                ->where('type', 'entree')
                ->whereDate('date', today())
                ->count();
            $hebGlobal = Transaction::where('user_id', $user->id)
                ->where('category', 'Chambre')
                ->where('type', 'entree')
                ->sum('amount');

            if ($terrGlobal > 0 || $hebGlobal > 0 || $terrTodayCount > 0 || $hebTodayCount > 0) {
                $stats[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'terrasse_today' => $terrToday,
                    'terrasse_today_count' => $terrTodayCount,
                    'terrasse_today_qty' => $terrTodayQty,
                    'terrasse_global' => $terrGlobal,
                    'hebergement_today' => $hebToday,
                    'hebergement_today_count' => $hebTodayCount,
                    'hebergement_global' => $hebGlobal,
                ];
            }
        }

        return $stats;
    }

    public function openBookModal(int $roomId): void
    {
        if (!auth()->user()?->can_access_hebergement) {
            abort(403, 'Accès non autorisé.');
        }
        $this->selectedRoomId = $roomId;
        $this->guestName = '';
        $this->stayType = 'jour';
        $this->stayDuration = 1;
        $this->showBookModal = true;
    }

    public function bookRoom(): void
    {
        if (!auth()->user()?->can_access_hebergement) {
            abort(403, 'Accès non autorisé.');
        }
        $this->validate([
            'guestName' => 'required|string|min:2',
            'stayType' => 'required|in:jour,passage',
            'stayDuration' => 'required|integer|min:1',
        ]);

        $room = Room::findOrFail($this->selectedRoomId);
        
        // Si c'est un passage, on force la durée à 1 (pour le calcul)
        $duration = $this->stayType === 'passage' ? 1 : $this->stayDuration;

        $room->update([
            'status' => 'occupé',
            'guest_name' => $this->guestName,
            'stay_duration' => $duration,
            'stay_type' => $this->stayType,
            'checked_in_at' => now(),
        ]);

        $message = $this->stayType === 'passage' 
            ? "Chambre {$room->number} occupée pour un Passage par {$this->guestName}."
            : "Chambre {$room->number} occupée par {$this->guestName} pour {$duration} jour(s).";

        ActivityLog::create([
            'type' => 'chambre',
            'message' => $message . " (Enregistrée par " . auth()->user()->name . ")."
        ]);

        // Enregistrement de la transaction comptable dès l'enregistrement/check-in
        $basePrice = $this->stayType === 'passage' 
            ? ($room->price_passage ?? ($room->price_night / 2)) 
            : $room->price_night;
        
        $totalAmount = $basePrice * $duration;

        Transaction::create([
            'type' => 'entree',
            'category' => 'Chambre',
            'amount' => $totalAmount,
            'description' => "Enregistrement Chambre {$room->number} - {$this->guestName} (" . ($this->stayType === 'passage' ? 'Passage' : $duration . ' nuits') . ")",
            'date' => now()->toDateString(),
            'user_id' => auth()->id(),
        ]);

        $this->showBookModal = false;
        $this->selectedRoomId = null;
        
        $this->dispatch('toast', message: 'Client enregistré et transaction comptabilisée !');
    }

    public function openCheckoutModal(int $roomId): void
    {
        if (!auth()->user()?->can_access_hebergement) {
            abort(403, 'Accès non autorisé.');
        }
        $this->selectedRoomId = $roomId;
        $this->showCheckoutModal = true;
    }

    public function checkoutRoom(): void
    {
        if (!auth()->user()?->can_access_hebergement) {
            abort(403, 'Accès non autorisé.');
        }
        $room = Room::findOrFail($this->selectedRoomId);
        $guest = $room->guest_name;
        $roomNum = $room->number;

        $room->update([
            'status' => 'nettoyage',
            'guest_name' => null,
            'guest_phone' => null,
            'guest_address' => null,
            'companion_name' => null,
            'stay_duration' => null,
            'stay_type' => null,
            'checked_in_at' => null,
        ]);

        // Log operation
        ActivityLog::create([
            'type' => 'chambre',
            'message' => "Départ client de la Chambre {$roomNum} (précédemment {$guest})."
        ]);

        $this->showCheckoutModal = false;
        $this->selectedRoomId = null;
        
        $this->dispatch('toast', message: 'Chambre libérée !');
    }

    public function setReady(int $roomId): void
    {
        if (!auth()->user()?->can_access_hebergement) {
            abort(403, 'Accès non autorisé.');
        }
        $room = Room::findOrFail($roomId);
        $room->update(['status' => 'disponible']);

        ActivityLog::create([
            'type' => 'chambre',
            'message' => "Chambre {$room->number} nettoyée et prête."
        ]);
        
        $this->dispatch('toast', message: 'Chambre disponible !');
    }

    public function openStockModal(int $beverageId): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $this->selectedBeverageId = $beverageId;
        $bev = Beverage::findOrFail($beverageId);
        $this->addQuantity = 10; 
        $this->purchaseCost = $bev->purchase_price * $this->addQuantity;
        $this->showStockModal = true;
    }

    public function addStock(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $this->validate([
            'addQuantity' => 'required|integer|min:1',
            'purchaseCost' => 'nullable|integer|min:0',
        ]);

        $bev = Beverage::findOrFail($this->selectedBeverageId);
        $bev->increment('stock', $this->addQuantity);
        
        // Update purchase price based on this supply
        if ($this->purchaseCost > 0) {
            $unitPurchasePrice = round($this->purchaseCost / $this->addQuantity);
            $bev->update(['purchase_price' => $unitPurchasePrice]);
        }

        ActivityLog::create([
            'type' => 'stock',
            'message' => "Approvisionnement : +{$this->addQuantity} x {$bev->name} par " . auth()->user()->name . "."
        ]);

        // Register transaction in accounting (sortie)
        if ($this->purchaseCost > 0) {
            Transaction::create([
                'type' => 'sortie',
                'category' => 'Approvisionnement',
                'amount' => $this->purchaseCost,
                'description' => "Achat stock: +{$this->addQuantity}x {$bev->name} (@" . number_format($bev->purchase_price, 0, ',', ' ') . " F/u)",
                'date' => now()->toDateString(),
                'user_id' => auth()->id(),
            ]);
        }

        $this->showStockModal = false;
        $this->selectedBeverageId = null;
        
        $this->dispatch('toast', message: 'Stock mis à jour !');
    }

    public function confirmSellBeverage(int $beverageId): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $this->selectedBeverageId = $beverageId;
        $this->sellQuantity = 1;
        $this->showSellModal = true;
    }

    public function sellBeverage(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $this->validate([
            'sellQuantity' => 'required|integer|min:1',
        ]);

        $bev = Beverage::findOrFail($this->selectedBeverageId);
        
        if ($bev->stock < $this->sellQuantity) {
            $this->addError('sellQuantity', "Stock insuffisant ({$bev->stock} restants).");
            return;
        }

        $bev->decrement('stock', $this->sellQuantity);

        ActivityLog::create([
            'type' => 'stock',
            'message' => "Vente directe : {$this->sellQuantity}x {$bev->name} par " . auth()->user()->name . "."
        ]);

        // Register transaction in accounting (entrée)
        Transaction::create([
            'type' => 'entree',
            'category' => 'Bar',
            'amount' => $bev->price * $this->sellQuantity,
            'description' => "Vente directe bar: {$this->sellQuantity}x {$bev->name}",
            'date' => now()->toDateString(),
            'user_id' => auth()->id(),
        ]);

        $this->showSellModal = false;
        $this->selectedBeverageId = null;
        $this->sellQuantity = 1;
        $this->dispatch('toast', message: 'Vente enregistrée et comptabilisée !');
    }

    public function incrementSellQuantity(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $bev = Beverage::find($this->selectedBeverageId);
        $max = $bev ? $bev->stock : 1;
        $current = (int)$this->sellQuantity;
        if ($current < $max) {
            $this->sellQuantity = $current + 1;
        }
    }

    public function decrementSellQuantity(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        $current = (int)$this->sellQuantity;
        if ($current > 1) {
            $this->sellQuantity = $current - 1;
        }
    }

    public function updatedSellQuantity($value): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            return;
        }
        $bev = Beverage::find($this->selectedBeverageId);
        $max = $bev ? $bev->stock : 1;
        
        if ($value === '' || $value === null) {
            return;
        }

        $val = (int)$value;
        if ($val < 1) {
            $this->sellQuantity = 1;
        } elseif ($val > $max) {
            $this->sellQuantity = $max;
        } else {
            $this->sellQuantity = $val;
        }
    }

    public function openInventoryModal(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        foreach ($this->beverages as $bev) {
            $this->inventoryStocks[$bev->id] = $bev->stock;
        }
        $this->showInventoryModal = true;
    }

    public function saveInventory(): void
    {
        if (!auth()->user()?->can_access_terrasse) {
            abort(403, 'Accès non autorisé.');
        }
        foreach ($this->inventoryStocks as $id => $stock) {
            $bev = Beverage::findOrFail($id);
            $old = $bev->stock;
            $new = (int)$stock;
            if ($old !== $new) {
                $bev->update(['stock' => $new]);
                $diff = $new - $old;
                $diffText = $diff > 0 ? "+{$diff}" : "{$diff}";
                $valueDiff = abs($diff) * $bev->price;
                
                ActivityLog::create([
                    'type' => 'stock',
                    'message' => "ÉCART D'INVENTAIRE pour {$bev->name} : Système={$old}, Physique={$new} (Diff: {$diffText}, Valeur approx: " . number_format($valueDiff, 0, ',', ' ') . " F) par " . auth()->user()->name . "."
                ]);
            }
        }

        $this->showInventoryModal = false;
        $this->dispatch('toast', message: 'Inventaire enregistré !');
    }

    public function resetDemo(): void
    {
        // Clear activity log and transactions, then seed
        Schema::disableForeignKeyConstraints();
        Room::truncate();
        Beverage::truncate();
        ActivityLog::truncate();
        Transaction::truncate();
        Schema::enableForeignKeyConstraints();

        $seeder = new \Database\Seeders\DatabaseSeeder();
        $seeder->run();

        $this->dispatch('toast', message: 'Base de données réinitialisée aux valeurs par défaut !');
    }

    public function openExpenseModal(): void
    {
        $this->showMobileMenu = false;
        $this->expenseCategory = 'Autres';
        $this->expenseAmount = 0;
        $this->expenseDescription = '';
        $this->showExpenseModal = true;
    }

    public function saveExpense(): void
    {
        $this->validate([
            'expenseCategory' => 'required|string',
            'expenseAmount' => 'required|integer|min:1',
            'expenseDescription' => 'required|string|min:3',
        ]);

        Transaction::create([
            'type' => 'sortie',
            'category' => $this->expenseCategory,
            'amount' => $this->expenseAmount,
            'description' => $this->expenseDescription,
            'date' => now()->toDateString(),
            'user_id' => auth()->id(),
        ]);

        ActivityLog::create([
            'type' => 'finance',
            'message' => "Dépense enregistrée : " . number_format($this->expenseAmount, 0, ',', ' ') . " CDF ({$this->expenseCategory} - {$this->expenseDescription}) par " . auth()->user()->name . ".",
        ]);

        $this->showExpenseModal = false;
        $this->dispatch('toast', message: 'Dépense enregistrée !');
    }
};
?>

<div class="w-full py-4 md:py-8 space-y-8">
    
    <!-- Header Section -->
    <header class="flex flex-col gap-6 border-b border-zinc-800 pb-6">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-4">
                <img src="{{ asset('images/logo.png') }}" alt="Logo" class="w-16 h-16 md:w-20 md:h-20 object-contain">
                <div>
                    <h1 class="text-xl font-black text-white leading-none">CRM HÔTEL</h1>
                    <p class="text-[10px] text-zinc-500 uppercase tracking-widest mt-1">LULUABOURG</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('report.daily') }}" target="_blank" class="hidden md:flex items-center gap-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2.5 rounded-xl text-xs font-black text-zinc-400 hover:text-white transition cursor-pointer shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-green-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231a1.125 1.125 0 0 1-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-14.326 0C3.768 7.44 3 8.375 3 9.456V15.75a2.25 2.25 0 0 0 2.25 2.25h1.091M9 10.125h6M9 13h4" />
                    </svg>
                    PDF Rapport
                </a>
                <button wire:click="$set('showMobileMenu', true)" class="w-10 h-10 flex items-center justify-center bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-white rounded-xl transition duration-150 cursor-pointer shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Stats Horizontal Scroll Row -->
        <div class="flex flex-nowrap overflow-x-auto pb-2 -mx-4 px-4 sm:mx-0 sm:px-0 gap-3 custom-scrollbar scrollbar-hide">
            <!-- Occupation -->
            <div class="flex-none w-[140px] md:w-auto md:flex-1 bg-zinc-900/50 border border-zinc-800 p-3 rounded-2xl flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-green-500/10 flex items-center justify-center shrink-0">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                </div>
                <div class="min-w-0">
                    <p class="text-[8px] text-zinc-500 uppercase font-bold tracking-widest truncate">Occupation</p>
                    <p class="text-sm font-black text-white">
                        {{ round((collect($this->rooms)->where('status', 'occupé')->count() / count($this->rooms)) * 100) }}%
                    </p>
                </div>
            </div>

            <!-- Alerte Stock -->
            <div class="flex-none w-[140px] md:w-auto md:flex-1 bg-zinc-900/50 border border-zinc-800 p-3 rounded-2xl flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-crm-yellow/10 flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[8px] text-zinc-500 uppercase font-bold tracking-widest truncate">Alertes</p>
                    <p class="text-sm font-black text-white">
                        {{ collect($this->beverages)->filter(fn($b) => $b->stock < $b->min_stock)->count() }}
                    </p>
                </div>
            </div>

            <!-- Chambres Libres -->
            <div class="flex-none w-[140px] md:w-auto md:flex-1 bg-zinc-900/50 border border-zinc-800 p-3 rounded-2xl flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-blue-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[8px] text-zinc-500 uppercase font-bold tracking-widest truncate">Libres</p>
                    <p class="text-sm font-black text-white">
                        {{ collect($this->rooms)->where('status', 'disponible')->count() }}
                    </p>
                </div>
            </div>

            <!-- Dernière Action -->
            <div class="flex-none w-[140px] md:w-auto md:flex-1 bg-zinc-900/50 border border-zinc-800 p-3 rounded-2xl flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-orange-500/10 flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-orange-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[8px] text-zinc-500 uppercase font-bold tracking-widest truncate">Activité</p>
                    <p class="text-[10px] font-bold text-white truncate">
                        {{ $this->history->first()?->created_at->diffForHumans() ?? 'N/A' }}
                    </p>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Dashboard Body Grid -->
    <main class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        @php
            $canAccessHeba = auth()->user()?->can_access_hebergement ?? true;
            $canAccessTerra = auth()->user()?->can_access_terrasse ?? true;
        @endphp
        
        @if($canAccessHeba)
            <!-- Left Side: Hotel Room Status (Chambres) -->
            <section class="{{ $canAccessTerra ? 'lg:col-span-7' : 'lg:col-span-12' }} space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
                    </svg>
                    <h2 class="text-sm md:text-lg font-bold tracking-tight uppercase">HÉBERGEMENT ({{ count($this->rooms) }})</h2>
                </div>
                <span class="hidden sm:inline-block text-[10px] text-zinc-400 font-bold bg-zinc-900 border border-zinc-800 px-2 py-1 rounded uppercase tracking-widest">CRM Hôtel</span>
            </div>

            <!-- Rooms Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-3 gap-3 md:gap-4">
                @foreach($this->rooms as $room)
                    <div wire:key="room-{{ $room->id }}" class="bg-zinc-950 border border-zinc-800 hover:border-zinc-700 rounded-2xl p-3 md:p-5 flex flex-col justify-between min-h-[180px] md:min-h-[220px] transition duration-200 group">
                        
                        <!-- Card Header -->
                        <div class="space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-xs tracking-widest text-zinc-400 font-semibold uppercase">Chambre</span>
                                
                                <!-- Status Indicator Dot/Badge -->
                                <div class="flex items-center gap-1.5">
                                    @if($room->status === 'disponible')
                                        <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                        <span class="text-[10px] font-semibold text-green-500 uppercase tracking-wider">Disponible</span>
                                    @elseif($room->status === 'occupé')
                                        @if($room->isOverstayed())
                                            <span class="w-2.5 h-2.5 rounded-full bg-red-600 animate-ping"></span>
                                            <span class="text-[10px] font-black text-red-600 uppercase tracking-wider">RETARD</span>
                                        @else
                                            <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                                            <span class="text-[10px] font-semibold text-red-500 uppercase tracking-wider">Occupée</span>
                                        @endif
                                    @else
                                        <span class="w-2 h-2 rounded-full bg-crm-yellow"></span>
                                        <span class="text-[10px] font-semibold text-crm-yellow uppercase tracking-wider">Nettoyage</span>
                                    @endif
                                </div>
                            </div>
                            <h3 class="text-2xl font-bold tracking-tight text-white group-hover:text-crm-yellow transition duration-200">
                                {{ $room->number }}
                            </h3>
                            <p class="text-xs text-zinc-400 font-medium">{{ $room->type }}</p>
                        </div>

                        <!-- Card Middle: Occupant or Price info -->
                        <div class="py-2 md:py-4 my-1 md:my-2 border-t border-b border-zinc-900 flex flex-col justify-center min-h-[50px] md:min-h-[64px]">
                            @if($room->status === 'occupé')
                                <div class="space-y-0.5">
                                    <p class="text-[8px] md:text-[10px] text-zinc-500 uppercase font-bold">Occupant</p>
                                    <p class="text-xs md:text-sm font-bold truncate text-white uppercase">{{ $room->guest_name }}</p>
                                    <p class="text-[8px] md:text-[10px] {{ $room->isOverstayed() ? 'text-red-500 animate-pulse' : 'text-crm-yellow' }} font-black uppercase tracking-wider">{{ $room->stay_duration }} jour(s)</p>
                                </div>
                            @elseif($room->status === 'nettoyage')
                                <div class="flex items-center gap-2 text-zinc-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 md:w-5 md:h-5 text-crm-yellow animate-spin" style="animation-duration: 3s;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                                    </svg>
                                    <span class="text-[10px] md:text-xs font-bold uppercase tracking-tight">Nettoyage...</span>
                                </div>
                            @else
                                <div class="space-y-0.5">
                                    <p class="text-[8px] md:text-[10px] text-zinc-500 uppercase font-bold">Tarifs (Nuit / Pass)</p>
                                    <p class="text-xs md:text-sm font-black text-white">{{ number_format($room->price_night, 0, ',', ' ') }} / {{ number_format($room->price_passage ?? ($room->price_night / 2), 0, ',', ' ') }} F</p>
                                </div>
                            @endif
                        </div>

                        <!-- Card Action Button -->
                        <div>
                            @if($room->status === 'disponible')
                                <button wire:click="openBookModal({{ $room->id }})" class="w-full bg-white hover:bg-crm-yellow text-black text-xs font-bold py-2 rounded-xl transition duration-150 cursor-pointer">
                                    Enregistrer Client
                                </button>
                            @elseif($room->status === 'occupé')
                                <button wire:click="openCheckoutModal({{ $room->id }})" class="w-full bg-zinc-900 hover:bg-zinc-800 text-white hover:text-red-400 border border-zinc-800 py-2 rounded-xl text-xs font-bold transition duration-150 cursor-pointer">
                                    Libérer la chambre
                                </button>
                            @else
                                <button wire:click="setReady({{ $room->id }})" class="w-full bg-crm-yellow hover:bg-crm-yellow-hover text-black text-xs font-bold py-2 rounded-xl transition duration-150 cursor-pointer">
                                    Marquer Prête
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @endif

        @if($canAccessTerra)
            <!-- Right Side: Terrace Bar stock management (Terrasse) -->
            <section class="{{ $canAccessHeba ? 'lg:col-span-5' : 'lg:col-span-12' }} space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-800 pb-3">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75 3 16.5m6.75-6.75L16.5 3m-6.75 6.75 4.5 4.5m0 0L21 21M12 12H3m9 0v9" />
                    </svg>
                    <h2 class="text-sm md:text-lg font-bold tracking-tight uppercase">TERRASSE ({{ count($this->beverages) }})</h2>
                </div>
                <span class="hidden sm:inline-block text-[10px] text-zinc-400 font-bold bg-zinc-900 border border-zinc-800 px-2 py-1 rounded uppercase tracking-widest">CRM Terrasse</span>
            </div>

            <!-- Quick Actions Panel -->
            <div class="grid grid-cols-2 gap-3">
                <button wire:click="openInventoryModal" class="bg-zinc-950 hover:bg-zinc-900 border border-zinc-800 text-white text-[10px] sm:text-xs font-semibold py-3 px-2 rounded-xl flex items-center justify-center gap-2 transition duration-150 cursor-pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.25 2.25 0 0 1 10.5 2.25h4.5a2.25 2.25 0 0 1 2.25 2.25M9 3.75H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V6a2.25 2.25 0 0 0-2.25-2.25H15M9 3.75h.008v.008H9V3.75Z" />
                    </svg>
                    Inventaire
                </button>
                <button wire:click="openStockModal({{ count($this->beverages) > 0 ? $this->beverages->first()->id : 1 }})" class="bg-crm-yellow hover:bg-crm-yellow-hover text-black text-[10px] sm:text-xs font-bold py-3 px-2 rounded-xl flex items-center justify-center gap-2 transition duration-150 cursor-pointer">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Approvisionner
                </button>
            </div>

            <!-- Beverages Stock List Card -->
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl overflow-hidden">
                <div class="p-3 md:p-4 bg-zinc-900 border-b border-zinc-800 flex justify-between items-center">
                    <span class="text-[10px] md:text-xs font-bold uppercase tracking-wider text-zinc-400">Articles en stock</span>
                    <span class="text-[10px] text-zinc-500 uppercase font-bold">{{ count($this->beverages) }} types</span>
                </div>

                @error('bar')
                    <div class="bg-red-500/10 border border-zinc-800/40 text-red-400 p-3 m-4 rounded-xl text-xs font-semibold">
                        {{ $message }}
                    </div>
                @enderror

                <div class="divide-y divide-zinc-900">
                    @foreach($this->beverages as $bev)
                        @php 
                            $isLow = $bev->stock < $bev->min_stock; 
                            $percent = min(100, round(($bev->stock / 120) * 100));
                        @endphp
                        <div class="p-3 md:p-4 flex items-center justify-between gap-3 md:gap-4 hover:bg-zinc-900/50 transition duration-150">
                            
                            <!-- Left side details -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="font-bold text-xs md:text-sm truncate text-white uppercase">{{ $bev->name }}</p>
                                    <span class="hidden sm:inline-block text-[8px] px-1.5 py-0.5 rounded bg-zinc-900 border border-zinc-800 text-zinc-500 font-bold uppercase">{{ $bev->category }}</span>
                                </div>
                                
                                <!-- Stock Progress Bar -->
                                <div class="mt-1.5 md:mt-2 flex items-center gap-2 md:gap-3">
                                    <div class="flex-1 bg-zinc-900 h-1 md:h-1.5 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-300 {{ $isLow ? 'bg-crm-yellow' : 'bg-white' }}" style="width: {{ $percent }}%"></div>
                                    </div>
                                    <span class="text-[9px] md:text-[10px] text-zinc-400 font-bold whitespace-nowrap">{{ $bev->stock }} <span class="hidden md:inline">unités</span></span>
                                </div>
                            </div>

                            <!-- Right side actions & warnings -->
                            <div class="flex items-center gap-1.5 md:gap-2 shrink-0">
                                <div class="text-right mr-1">
                                    <p class="text-[10px] md:text-xs font-black text-white whitespace-nowrap">{{ number_format($bev->price, 0, ',', ' ') }} F</p>
                                    <p class="text-[10px] md:text-xs font-black text-zinc-400 uppercase tracking-tight mt-0.5">Total: {{ number_format($bev->stock * $bev->price, 0, ',', ' ') }} F</p>
                                    @if($isLow)
                                        <span class="text-[7px] md:text-[8px] font-black text-crm-yellow border border-crm-yellow/30 px-1 py-0.2 rounded uppercase tracking-tighter">BAS</span>
                                    @endif
                                </div>

                                <!-- Quick Vente Button (-1) -->
                                <button wire:click="confirmSellBeverage({{ $bev->id }})" class="p-1.5 md:p-2 bg-red-500/10 hover:bg-red-500/20 border border-red-500/20 text-red-500 rounded-lg transition duration-150 cursor-pointer disabled:opacity-20 disabled:cursor-not-allowed shrink-0" {{ $bev->stock <= 0 ? 'disabled' : '' }} title="Vendre 1">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3.5 h-3.5 md:w-4 md:h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                                    </svg>
                                </button>

                                <!-- Quick +10 Add -->
                                <button wire:click="openStockModal({{ $bev->id }})" class="p-1.5 md:p-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-400 hover:text-white rounded-lg transition duration-150 cursor-pointer shrink-0" title="Approvisionner">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor" class="w-3.5 h-3.5 md:w-4 md:h-4">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
        @endif

    </main>

    <!-- Bottom Section: Grid Layout for Activity Logs & Agent Performance -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        
        <!-- Left Column: Activity Logs (7 Cols) -->
        <section class="lg:col-span-7 bg-zinc-950 border border-zinc-800 rounded-2xl overflow-hidden p-4 md:p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-3">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 md:w-5 md:h-5 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    <h2 class="text-xs md:text-sm font-black tracking-tight uppercase">JOURNAL DES ACTIVITÉS</h2>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                    </span>
                    <span class="text-[9px] md:text-[10px] uppercase font-black text-zinc-500 tracking-widest">Temps Réel</span>
                </div>
            </div>
            
            <div class="space-y-3 max-h-[300px] overflow-y-auto pr-2 custom-scrollbar">
                @forelse($this->history as $item)
                    <div wire:key="log-{{ $item->id }}" class="flex items-start justify-between gap-3 md:gap-4 text-[10px] md:text-xs border-b border-zinc-900/50 pb-3 last:border-b-0 group">
                        <div class="flex items-start gap-2 md:gap-3 min-w-0">
                            @php
                                $logIcon = match($item->type) {
                                    'chambre' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />',
                                    'equipe' => '<path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />',
                                    'finance' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />',
                                    default => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75 3 16.5m6.75-6.75L16.5 3m-6.75 6.75 4.5 4.5m0 0L21 21M12 12H3m9 0v9" />'
                                };
                                $logColor = match($item->type) {
                                    'chambre' => 'text-blue-400',
                                    'equipe' => 'text-zinc-400',
                                    'finance' => 'text-red-400',
                                    default => 'text-crm-yellow'
                                };
                            @endphp
                            <span class="p-1.5 md:p-2 bg-zinc-900 border border-zinc-800 rounded-xl {{ $logColor }} mt-0.5 shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3 h-3 md:w-4 md:h-4">{!! $logIcon !!}</svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-zinc-300 font-bold leading-relaxed break-words">{{ $item->message }}</p>
                                <p class="text-[9px] md:text-[10px] text-zinc-600 font-bold mt-1 uppercase tracking-tighter">{{ $item->created_at->translatedFormat('d M H:i') }}</p>
                            </div>
                        </div>
                        <span class="hidden sm:inline-block text-[8px] md:text-[9px] uppercase font-black text-zinc-500 bg-zinc-900 border border-zinc-800 px-2 py-0.5 rounded-lg shrink-0 tracking-widest group-hover:text-white transition">{{ $item->type }}</span>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center py-10 opacity-20">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor" class="w-12 h-12">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <p class="text-[10px] uppercase font-black tracking-widest mt-2 text-center">Aucune activité récente</p>
                    </div>
                @endforelse
            </div>
        </section>

        <!-- Right Column: Agent Performance stats (5 Cols) -->
        <section class="lg:col-span-5 bg-zinc-950 border border-zinc-800 rounded-2xl overflow-hidden p-4 md:p-6 space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-3">
                <div class="flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4.5 h-4.5 md:w-5 md:h-5 text-crm-yellow">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.109A11.386 11.386 0 0 1 10.089 21c-2.331 0-4.512-.647-6.366-1.773a3.25 3.25 0 0 1 5.437-3.29 4.5 4.5 0 0 1 5.842 3.19M15 19.128v-.003c.5-1.113.786-2.16.786-3.07M15 19.128v.109A11.386 11.386 0 0 0 20 16.5m-5-3.872a3.375 3.375 0 1 0 0-6.75 3.375 3.375 0 0 0 0 6.75ZM7.5 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm0 0V3m12 9V3" />
                    </svg>
                    <h2 class="text-xs md:text-sm font-black tracking-tight uppercase">PERFORMANCES DES AGENTS</h2>
                </div>
                <span class="text-[9px] md:text-[10px] uppercase font-black text-zinc-500 tracking-widest">Bilan</span>
            </div>

            <div class="overflow-x-auto max-h-[300px] overflow-y-auto custom-scrollbar pr-1">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-zinc-900 text-[8px] md:text-[9px] uppercase font-black text-zinc-500 tracking-wider">
                            <th class="py-2 pb-3">Agent</th>
                            <th class="py-2 pb-3 text-right">Terrasse (Bar)</th>
                            <th class="py-2 pb-3 text-right">Hébergement</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-900/50">
                        @forelse($this->userStats as $stat)
                            <tr class="text-[11px] md:text-xs group hover:bg-zinc-900/30 transition-colors">
                                <td class="py-3 pr-2">
                                    <p class="font-bold text-white uppercase">{{ $stat['name'] }}</p>
                                    <span class="text-[7px] md:text-[8px] font-black uppercase tracking-wider text-zinc-500 bg-zinc-900 border border-zinc-800/80 px-1.5 py-0.5 rounded-md mt-1 inline-block">{{ $stat['role'] }}</span>
                                </td>
                                <td class="py-3 text-right pr-2">
                                    <p class="font-black text-crm-yellow text-xs">{{ number_format($stat['terrasse_today'], 0, ',', ' ') }} F</p>
                                    <p class="text-[9px] text-zinc-400 font-extrabold mt-0.5">{{ $stat['terrasse_today_qty'] }} unité(s) vendue(s)</p>
                                    <p class="text-[8px] text-zinc-500 font-bold">({{ $stat['terrasse_today_count'] }} opération(s))</p>
                                    <p class="text-[7px] text-zinc-600 font-bold uppercase mt-1">Cumul: {{ number_format($stat['terrasse_global'], 0, ',', ' ') }} F</p>
                                </td>
                                <td class="py-3 text-right">
                                    <p class="font-black text-blue-400 text-xs">{{ number_format($stat['hebergement_today'], 0, ',', ' ') }} F</p>
                                    <p class="text-[9px] text-zinc-500 font-bold mt-0.5">{{ $stat['hebergement_today_count'] }} rsv.</p>
                                    <p class="text-[7px] text-zinc-600 font-bold uppercase mt-0.5">Cumul: {{ number_format($stat['hebergement_global'], 0, ',', ' ') }} F</p>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="py-10 text-center opacity-20">
                                    <p class="text-[10px] uppercase font-black tracking-widest">Aucune vente ou réservation enregistrée</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </div>

    <!-- MODALS SECTION -->
    
    <!-- 1. BOOK ROOM MODAL -->
    @if($showBookModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white">ENREGISTRER UN CLIENT</h3>
                        @php $rBook = collect($this->rooms)->firstWhere('id', $selectedRoomId); @endphp
                        <p class="text-xs text-zinc-400">Chambre {{ $rBook?->number ?? '' }} - {{ $rBook?->type ?? '' }}</p>
                    </div>
                    <button wire:click="$set('showBookModal', false)" class="text-zinc-500 hover:text-white transition duration-150 cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="bookRoom" class="p-5 space-y-4">
                    <!-- Code Client / Nom -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Identité du Client</label>
                        <input wire:model.defer="guestName" type="text" placeholder="Code ou Nom..." class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2 text-sm text-white outline-none transition placeholder:text-zinc-600" required>
                        @error('guestName') <span class="text-[9px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span> @enderror
                    </div>

                    <!-- Type de Séjour & Durée (Compact) -->
                    <div class="grid grid-cols-12 gap-3 items-end">
                        <div class="col-span-7 space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Format</label>
                            <div class="grid grid-cols-2 bg-zinc-900 border border-zinc-800 rounded-xl p-1">
                                <button type="button" wire:click="$set('stayType', 'jour')" 
                                    class="py-1.5 rounded-lg text-[10px] font-black uppercase transition {{ $stayType === 'jour' ? 'bg-crm-yellow text-black' : 'text-zinc-500 hover:text-zinc-300' }}">
                                    Nuits
                                </button>
                                <button type="button" wire:click="$set('stayType', 'passage')" 
                                    class="py-1.5 rounded-lg text-[10px] font-black uppercase transition {{ $stayType === 'passage' ? 'bg-crm-yellow text-black' : 'text-zinc-500 hover:text-zinc-300' }}">
                                    Passage
                                </button>
                            </div>
                        </div>

                        <div class="col-span-5 space-y-1.5 {{ $stayType === 'jour' ? '' : 'opacity-20 pointer-events-none' }}">
                            <label class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Durée</label>
                            <div class="flex items-center bg-zinc-900 border border-zinc-800 rounded-xl p-1">
                                <button type="button" wire:click="$set('stayDuration', Math.max(1, stayDuration - 1))" class="w-7 h-7 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-white flex items-center justify-center transition cursor-pointer">
                                    <span class="font-black text-xs">-</span>
                                </button>
                                <span class="flex-1 text-center text-xs font-black text-white">{{ $stayDuration }}</span>
                                <button type="button" wire:click="$set('stayDuration', stayDuration + 1)" class="w-7 h-7 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-white flex items-center justify-center transition cursor-pointer">
                                    <span class="font-black text-xs">+</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Résumé Financier (Compact) -->
                    <div class="bg-zinc-900/50 p-4 rounded-xl border border-zinc-800 flex justify-between items-center">
                        <div class="space-y-0.5">
                            <span class="text-zinc-500 font-bold uppercase tracking-widest text-[9px]">Total à encaisser</span>
                            <div class="flex items-baseline gap-1">
                                <span class="text-xl font-black text-crm-yellow">
                                    @php
                                        $price = $stayType === 'passage' ? ($rBook?->price_passage ?? ($rBook?->price_night / 2)) : ($rBook?->price_night ?? 0);
                                        $duration = $stayType === 'passage' ? 1 : ($stayDuration ?: 1);
                                    @endphp
                                    {{ number_format($price * $duration, 0, ',', ' ') }}
                                </span>
                                <span class="text-[9px] font-bold text-zinc-600 uppercase">CDF</span>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-[9px] text-zinc-500 font-bold uppercase tracking-tighter">
                                Tarif : {{ number_format($stayType === 'passage' ? ($rBook?->price_passage ?? ($rBook?->price_night / 2)) : ($rBook?->price_night ?? 0), 0, ',', ' ') }}
                            </p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 pt-1">
                        <button type="button" wire:click="$set('showBookModal', false)" class="bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-400 hover:text-white py-2.5 rounded-xl font-bold text-[10px] uppercase tracking-widest transition duration-150 cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit" class="bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-black text-[10px] uppercase tracking-widest transition duration-150 cursor-pointer">
                            Valider
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- 2. CHECKOUT ROOM MODAL -->
    @if($showCheckoutModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white">LIBÉRER LA CHAMBRE (CHECK-OUT)</h3>
                        @php $rOut = collect($this->rooms)->firstWhere('id', $selectedRoomId); @endphp
                        <p class="text-xs text-zinc-400">Chambre {{ $rOut?->number ?? '' }}</p>
                    </div>
                    <button wire:click="$set('showCheckoutModal', false)" class="text-zinc-500 hover:text-white transition duration-150 cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 space-y-6">
                    <div class="space-y-4">
                        <div class="bg-zinc-900 p-4 rounded-xl border border-zinc-800 space-y-2">
                            <p class="text-[10px] text-zinc-500 uppercase font-bold tracking-wider">Informations d'occupation</p>
                            <div class="flex justify-between text-xs">
                                <span class="text-zinc-400">Client :</span>
                                <span class="font-bold text-white">{{ $rOut?->guest_name ?? '' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-zinc-400">Téléphone :</span>
                                <span class="text-white">{{ $rOut?->guest_phone ?? '' }}</span>
                            </div>
                            <div class="flex justify-between text-xs">
                                <span class="text-zinc-400">Arrivée le :</span>
                                <span class="text-white">{{ $rOut?->checked_in_at ? $rOut->checked_in_at->format('d/m/Y H:i') : '' }}</span>
                            </div>
                        </div>

                        <div class="bg-zinc-900/50 p-4 rounded-xl border border-zinc-800 space-y-2 text-xs">
                            <p class="text-[10px] text-zinc-500 uppercase font-bold tracking-wider">Statut Comptable</p>
                            <p class="text-white">Cette chambre a été comptabilisée lors de l'enregistrement du client.</p>
                            <p class="text-[9px] text-zinc-500 italic mt-1">La libération remettra simplement la chambre en état de nettoyage.</p>
                        </div>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="button" wire:click="$set('showCheckoutModal', false)" class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition duration-150 cursor-pointer">
                            Annuler
                        </button>
                        <button type="button" wire:click="checkoutRoom" class="flex-1 bg-zinc-950 hover:bg-red-500 hover:text-white text-black py-2.5 rounded-xl font-bold text-xs transition duration-150 cursor-pointer">
                            Confirmer le départ
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- 3. APPROVISIONNER STOCK MODAL -->
    @if($showStockModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white">SAISIE D'ENTRÉE STOCK</h3>
                        @php $bevSt = collect($this->beverages)->firstWhere('id', $selectedBeverageId); @endphp
                        <p class="text-xs text-zinc-400">Approvisionner: {{ $bevSt?->name ?? '' }}</p>
                    </div>
                    <button wire:click="$set('showStockModal', false)" class="text-zinc-500 hover:text-white transition duration-150 cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="addStock" class="p-6 space-y-4">
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Quantité à ajouter</label>
                        <input wire:model.defer="addQuantity" type="number" min="1" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition font-mono" required>
                        @error('addQuantity') <span class="text-xs text-red-500 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <!-- Coût d'achat -->
                    <div class="space-y-1.5">
                        <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Coût d'achat total (Optionnel - générera une dépense)</label>
                        <input wire:model.defer="purchaseCost" type="number" min="0" placeholder="Ex. 15000 (laisser 0 si non payé maintenant)" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition font-mono">
                        @error('purchaseCost') <span class="text-xs text-red-500 font-semibold">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="button" wire:click="$set('showStockModal', false)" class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition duration-150 cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit" class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition duration-150 cursor-pointer">
                            Valider l'entrée
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- 4. CONFIRMER VENTE MODAL -->
    @if($showSellModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white uppercase tracking-tight">Enregistrer une Vente</h3>
                        @php $bevSell = collect($this->beverages)->firstWhere('id', $selectedBeverageId); @endphp
                        <p class="text-xs text-zinc-400">{{ $bevSell?->name }} - Stock: {{ $bevSell?->stock }}</p>
                    </div>
                    <button wire:click="$set('showSellModal', false)" class="text-zinc-500 hover:text-white transition duration-150 cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="sellBeverage" class="p-5 space-y-4">
                    <!-- Quantité -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">Quantité vendue</label>
                        <div class="flex items-center bg-zinc-900 border border-zinc-800 rounded-xl p-1">
                            <button type="button" wire:click="decrementSellQuantity" class="w-10 h-10 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-white flex items-center justify-center transition cursor-pointer">
                                <span class="font-black text-lg">-</span>
                            </button>
                            <input wire:model.live="sellQuantity" type="number" min="1" max="{{ $bevSell?->stock ?? 1 }}" class="flex-1 bg-transparent text-center text-xl font-black text-white outline-none [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none">
                            <button type="button" wire:click="incrementSellQuantity" class="w-10 h-10 rounded-lg bg-zinc-800 hover:bg-zinc-700 text-white flex items-center justify-center transition cursor-pointer">
                                <span class="font-black text-lg">+</span>
                            </button>
                        </div>
                        @error('sellQuantity') <span class="text-[9px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span> @enderror
                    </div>

                    <!-- Résumé Financier -->
                    <div class="bg-zinc-900/50 p-4 rounded-xl border border-zinc-800 space-y-2">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-zinc-500 font-bold uppercase">Prix unitaire</span>
                            <span class="text-white font-black">{{ number_format($bevSell?->price ?? 0, 0, ',', ' ') }} F</span>
                        </div>
                        <div class="flex justify-between items-center text-sm border-t border-zinc-800 pt-2">
                            <span class="text-crm-yellow font-black uppercase text-[10px] tracking-widest">Total à encaisser</span>
                            <span class="text-crm-yellow font-black text-lg font-mono">{{ number_format(($bevSell?->price ?? 0) * (int)$sellQuantity, 0, ',', ' ') }} F</span>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-1">
                        <button type="button" wire:click="$set('showSellModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit"
                            class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition cursor-pointer">
                            Confirmer la vente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- 5. INVENTAIRE MODAL -->
    @if($showInventoryModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-lg w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white">FAIRE L'INVENTAIRE DES BOISSONS</h3>
                        <p class="text-xs text-zinc-400">Saisir les quantités physiques réelles comptées au bar</p>
                    </div>
                    <button wire:click="$set('showInventoryModal', false)" class="text-zinc-500 hover:text-white transition duration-150 cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="saveInventory" class="p-6 space-y-4">
                    <div class="max-h-[300px] overflow-y-auto pr-2 space-y-3">
                        @foreach($this->beverages as $bev)
                            <div class="flex items-center justify-between gap-4 p-3 bg-zinc-900 rounded-xl border border-zinc-800">
                                <div>
                                    <p class="text-xs font-bold text-white">{{ $bev->name }}</p>
                                    <p class="text-[10px] text-zinc-500">Stock système : {{ $bev->stock }}</p>
                                </div>
                                <div class="w-24">
                                    <input wire:model.defer="inventoryStocks.{{ $bev->id }}" type="number" min="0" class="w-full bg-zinc-950 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-lg px-2.5 py-1.5 text-xs text-center text-white outline-none transition font-mono" required>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="button" wire:click="$set('showInventoryModal', false)" class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition duration-150 cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit" class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition duration-150 cursor-pointer">
                            Enregistrer l'inventaire
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif


    <!-- 6. NOUVELLE DEPENSE MODAL -->
    @if($showExpenseModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-base font-bold text-white uppercase tracking-tight">Enregistrer une Dépense</h3>
                        <p class="text-xs text-zinc-400">Sortie d'argent immédiate (Loyer, Salaire, etc.)</p>
                    </div>
                    <button wire:click="$set('showExpenseModal', false)" class="text-zinc-500 hover:text-white transition cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="saveExpense" class="p-6 space-y-4">
                    <!-- Category Selection -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Catégorie de dépense</label>
                        <select wire:model="expenseCategory" class="w-full bg-zinc-900 border border-zinc-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 rounded-xl px-4 py-2.5 text-sm text-white outline-none transition cursor-pointer">
                            <option value="Salaires">Salaires</option>
                            <option value="Approvisionnement">Approvisionnement</option>
                            <option value="Entretien">Entretien / Maintenance</option>
                            <option value="Factures">Factures (Eau/Élec)</option>
                            <option value="Loyer">Loyer</option>
                            <option value="Autres">Autres dépenses</option>
                        </select>
                    </div>

                    <!-- Amount -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Montant total (CDF)</label>
                        <input wire:model.defer="expenseAmount" type="number" placeholder="0" class="w-full bg-zinc-900 border border-zinc-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 rounded-xl px-4 py-2.5 text-sm text-white outline-none transition font-mono" required>
                    </div>

                    <!-- Description -->
                    <div class="space-y-1.5">
                        <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Motif / Description</label>
                        <input wire:model.defer="expenseDescription" type="text" placeholder="Ex: Paiement facture SNEL Mai" class="w-full bg-zinc-900 border border-zinc-800 focus:border-red-500 focus:ring-1 focus:ring-red-500 rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button type="button" wire:click="$set('showExpenseModal', false)" class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition duration-150 cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2.5 rounded-xl font-bold text-xs transition duration-150 cursor-pointer shadow-lg shadow-red-600/20">
                            Enregistrer la Dépense
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    
    <!-- MOBILE NAVIGATION MENU -->
    @if($showMobileMenu)
        <div class="fixed inset-0 z-[100] flex justify-end overflow-hidden">
            <!-- Glass Overlay -->
            <div wire:click="$set('showMobileMenu', false)" class="fixed inset-0 bg-black/40 backdrop-blur-md transition-opacity duration-500"></div>
            
            <!-- Sidebar Surface -->
            <div class="relative w-[280px] h-full bg-zinc-950 border-l border-white/5 shadow-2xl flex flex-col animate-slide-in-right">
                <!-- Elegant Header -->
                <div class="pt-8 pb-4 px-6 flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-zinc-800 to-zinc-950 border border-white/10 flex items-center justify-center shadow-inner">
                                <img src="{{ asset('images/logo.png') }}" alt="Logo" class="w-5 h-5 object-contain grayscale opacity-80">
                            </div>
                            <div>
                                <h3 class="text-[10px] font-black text-white uppercase tracking-widest leading-none">Menu</h3>
                            </div>
                        </div>
                        <button wire:click="$set('showMobileMenu', false)" class="w-7 h-7 flex items-center justify-center rounded-full bg-white/5 hover:bg-white/10 text-zinc-400 hover:text-white transition duration-300 cursor-pointer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <!-- User Profile Quick View -->
                    <div class="flex items-center gap-3 p-2.5 bg-white/[0.02] border border-white/[0.05] rounded-xl">
                        <div class="w-8 h-8 rounded-lg bg-crm-yellow flex items-center justify-center text-black font-black text-[10px] shadow-lg shadow-yellow-500/20">
                            {{ substr(auth()->user()->name, 0, 1) }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-black text-white truncate uppercase leading-tight">{{ auth()->user()->name }}</p>
                            <p class="text-[8px] text-zinc-500 font-bold uppercase tracking-widest mt-0.5">Connecté</p>
                        </div>
                    </div>
                </div>

                <!-- Nav Links: Minimalist Style -->
                <nav class="flex-1 px-3 space-y-1 overflow-y-auto scrollbar-hide">
                    <div class="px-3 py-1.5">
                        <span class="text-[7px] font-black text-zinc-600 uppercase tracking-[0.3em]">Principaux</span>
                    </div>

                    <a href="{{ route('dashboard') }}" wire:navigate class="group flex items-center gap-3 px-3 py-2.5 rounded-xl bg-white/[0.03] border border-white/[0.05] text-white font-bold text-[11px] transition-all duration-300 hover:bg-white/[0.06]">
                        <div class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center group-hover:bg-crm-yellow transition-colors duration-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 group-hover:text-black"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        Tableau de bord
                    </a>

                    @can('access-accounting')
                        <a href="{{ route('accounting') }}" wire:navigate class="group flex items-center gap-3 px-3 py-2.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px] transition-all duration-300 hover:bg-white/[0.03]">
                            <div class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center group-hover:bg-blue-500 transition-colors duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 group-hover:text-white"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            </div>
                            Comptabilité
                        </a>
                    @endcan

                    @can('access-admin')
                        <a href="{{ route('audit') }}" wire:navigate class="group flex items-center gap-3 px-3 py-2.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px] transition-all duration-300 hover:bg-white/[0.03]">
                            <div class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center group-hover:bg-crm-yellow transition-colors duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 group-hover:text-black">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0V3m0 13.5V21m7.5-18v13.5m0 0V21m-12-4.5v4.5m16.5-4.5v4.5" />
                                </svg>
                            </div>
                            Vue Audit
                        </a>
                    @endcan

                    @can('access-admin')
                        <a href="{{ route('team') }}" wire:navigate class="group flex items-center gap-3 px-3 py-2.5 rounded-xl text-zinc-400 hover:text-white font-bold text-[11px] transition-all duration-300 hover:bg-white/[0.03]">
                            <div class="w-7 h-7 rounded-lg bg-white/5 flex items-center justify-center group-hover:bg-orange-500 transition-colors duration-300">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 group-hover:text-white"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774a1.125 1.125 0 0 1 .12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.894.15c.542.09.94.56.94 1.109v1.094c0 .55-.398 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738a1.125 1.125 0 0 1-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527a1.125 1.125 0 0 1-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15a1.125 1.125 0 0 1-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.774-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            </div>
                            Configuration
                        </a>
                    @endcan

                    <div class="px-3 py-4">
                        <span class="text-[7px] font-black text-zinc-600 uppercase tracking-[0.3em]">Finances</span>
                    </div>

                    <button wire:click="openExpenseModal" class="w-full group flex items-center gap-3 px-3 py-2.5 rounded-xl bg-red-600/10 border border-red-600/20 text-red-500 font-bold text-[11px] transition-all duration-300 hover:bg-red-600 hover:text-white cursor-pointer shadow-lg shadow-red-600/5">
                        <div class="w-7 h-7 rounded-lg bg-red-600/20 flex items-center justify-center group-hover:bg-white/20 transition-colors duration-300">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0" /></svg>
                        </div>
                        Nouvelle Dépense
                    </button>
                </nav>

                <!-- Footer Surface -->
                <div class="p-6 border-t border-white/[0.03] bg-white/[0.01]">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full flex items-center justify-center gap-3 p-3.5 rounded-xl bg-zinc-900 border border-white/5 text-zinc-400 hover:text-white font-black text-[10px] transition-all duration-300 hover:border-white/10 cursor-pointer uppercase tracking-widest">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" /></svg>
                            Déconnexion
                        </button>
                    </form>
                    <div class="mt-6 flex flex-col items-center gap-1 opacity-20">
                        <p class="text-[6px] text-white uppercase font-black tracking-[0.4em]">LULUABOURG CRM</p>
                        <p class="text-[5px] text-zinc-500 font-bold uppercase tracking-widest">Version 2.0.4 Premium</p>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>