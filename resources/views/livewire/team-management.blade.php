<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.crm')] class extends Component {
    public array $rooms = [];
    public bool $showEditRoomModal = false;
    public bool $showAddRoomModal = false;
    public bool $showDeleteRoomModal = false;
    public ?int $selectedRoomId = null;
    public string $selectedRoomNumber = '';
    public string $selectedRoomType = '';
    public int $selectedRoomPriceNight = 0;
    public int $selectedRoomPricePassage = 0;

    // New Room form
    public string $newRoomNumber = '';
    public string $newRoomType = 'Standard';
    public int $newRoomPriceNight = 0;
    public int $newRoomPricePassage = 0;

    // Beverage Management
    public array $beverages = [];
    public bool $showEditBeverageModal = false;
    public bool $showAddBeverageModal = false;
    public bool $showDeleteBeverageModal = false;
    public ?int $selectedBeverageId = null;
    public string $selectedBeverageName = '';
    public string $selectedBeverageCategory = 'Bière';
    public int $selectedBeveragePrice = 0;
    public int $selectedBeveragePurchasePrice = 0;
    public int $selectedBeverageMinStock = 0;

    // New Beverage form
    public string $newBeverageName = '';
    public string $newBeverageCategory = 'Bière';
    public int $newBeveragePrice = 0;
    public int $newBeveragePurchasePrice = 0;
    public int $newBeverageMinStock = 10;

    // Accounting Summary
    public array $stats = [];

    public function mount(): void
    {
        // Restrict access to Admins only
        if (!auth()->user()?->isAdmin()) {
            abort(403, 'Accès non autorisé aux employés non-administrateurs.');
        }

        $this->loadRooms();
        $this->loadBeverages();
        $this->loadStats();
    }

    public function loadRooms(): void
    {
        $this->rooms = \App\Models\Room::orderBy('number')->get()->toArray();
    }

    public function loadBeverages(): void
    {
        $this->beverages = \App\Models\Beverage::orderBy('name')->get()->toArray();
    }

    public function loadStats(): void
    {
        $income = \App\Models\Transaction::where('type', 'entree')->sum('amount');
        $expense = \App\Models\Transaction::where('type', 'sortie')->sum('amount');
        
        $beverages = \App\Models\Beverage::all();
        $stockValue = $beverages->sum(fn($b) => $b->stock * $b->price);
        $stockCost = $beverages->sum(fn($b) => $b->stock * ($b->purchase_price ?? 0));

        $this->stats = [
            'total_income' => $income,
            'total_expense' => $expense,
            'net_balance' => $income - $expense,
            'low_stock_count' => \App\Models\Beverage::whereRaw('stock < min_stock')->count(),
            'occupied_rooms' => \App\Models\Room::where('status', 'occupé')->count(),
            'stock_value' => $stockValue,
            'stock_cost' => $stockCost,
            'potential_profit' => $stockValue - $stockCost,
        ];
    }

    public function openEditRoomModal(int $roomId): void
    {
        $room = \App\Models\Room::findOrFail($roomId);
        $this->selectedRoomId = $room->id;
        $this->selectedRoomNumber = $room->number;
        $this->selectedRoomType = $room->type;
        $this->selectedRoomPriceNight = $room->price_night;
        $this->selectedRoomPricePassage = $room->price_passage ?? ($room->price_night / 2);
        $this->showEditRoomModal = true;
    }

    public function updateRoom(): void
    {
        $this->validate([
            'selectedRoomType' => 'required|string',
            'selectedRoomPriceNight' => 'required|integer|min:0',
            'selectedRoomPricePassage' => 'required|integer|min:0',
        ]);

        if ($this->selectedRoomId) {
            $room = \App\Models\Room::findOrFail($this->selectedRoomId);
            $room->update([
                'type' => $this->selectedRoomType,
                'price_night' => $this->selectedRoomPriceNight,
                'price_passage' => $this->selectedRoomPricePassage,
            ]);

            ActivityLog::create([
                'type' => 'hotel',
                'message' => "Mise à jour de la configuration Chambre {$room->number} par " . auth()->user()->name . ".",
            ]);
        }

        $this->showEditRoomModal = false;
        $this->loadRooms();
        $this->loadStats();
        $this->dispatch('toast', message: 'Configuration chambre mise à jour !');
    }

    public function openAddRoomModal(): void
    {
        $this->newRoomNumber = '';
        $this->newRoomType = 'Standard';
        $this->newRoomPriceNight = 0;
        $this->newRoomPricePassage = 0;
        $this->showAddRoomModal = true;
    }

    public function createRoom(): void
    {
        $this->validate([
            'newRoomNumber' => 'required|string|unique:rooms,number',
            'newRoomType' => 'required|string',
            'newRoomPriceNight' => 'required|integer|min:0',
            'newRoomPricePassage' => 'required|integer|min:0',
        ]);

        \App\Models\Room::create([
            'number' => $this->newRoomNumber,
            'type' => $this->newRoomType,
            'price_night' => $this->newRoomPriceNight,
            'price_passage' => $this->newRoomPricePassage,
            'status' => 'disponible',
        ]);

        ActivityLog::create([
            'type' => 'hotel',
            'message' => "Nouvelle chambre créée : {$this->newRoomNumber} par " . auth()->user()->name . ".",
        ]);

        $this->showAddRoomModal = false;
        $this->loadRooms();
        $this->loadStats();
        $this->dispatch('toast', message: 'Nouvelle chambre ajoutée !');
    }

    public function confirmDeleteRoom(int $id, string $number): void
    {
        $this->selectedRoomId = $id;
        $this->selectedRoomNumber = $number;
        $this->showDeleteRoomModal = true;
    }

    public function deleteRoom(): void
    {
        if ($this->selectedRoomId) {
            $room = \App\Models\Room::find($this->selectedRoomId);
            
            if (!$room) {
                $this->showDeleteRoomModal = false;
                $this->selectedRoomId = null;
                $this->dispatch('toast', message: 'Chambre introuvable.');
                return;
            }

            if ($room->status === 'occupé') {
                $this->dispatch('toast', message: 'Impossible de supprimer une chambre occupée !');
                $this->showDeleteRoomModal = false;
                return;
            }

            $number = $room->number;
            $room->delete();

            ActivityLog::create([
                'type' => 'hotel',
                'message' => "Suppression de la chambre #{$number} par " . auth()->user()->name . ".",
            ]);
        }

        $this->showDeleteRoomModal = false;
        $this->selectedRoomId = null;
        $this->loadRooms();
        $this->loadStats();
        $this->dispatch('toast', message: 'Chambre supprimée.');
    }

    public function openEditBeverageModal(int $beverageId): void
    {
        $bev = \App\Models\Beverage::findOrFail($beverageId);
        $this->selectedBeverageId = $bev->id;
        $this->selectedBeverageName = $bev->name;
        $this->selectedBeverageCategory = $bev->category;
        $this->selectedBeveragePrice = $bev->price;
        $this->selectedBeveragePurchasePrice = $bev->purchase_price;
        $this->selectedBeverageMinStock = $bev->min_stock;
        $this->showEditBeverageModal = true;
    }

    public function updateBeverage(): void
    {
        $this->validate([
            'selectedBeverageName' => 'required|string|min:2',
            'selectedBeverageCategory' => 'required|string',
            'selectedBeveragePrice' => 'required|integer|min:0',
            'selectedBeveragePurchasePrice' => 'required|integer|min:0',
            'selectedBeverageMinStock' => 'required|integer|min:0',
        ]);

        if ($this->selectedBeverageId) {
            $bev = \App\Models\Beverage::findOrFail($this->selectedBeverageId);
            $bev->update([
                'name' => $this->selectedBeverageName,
                'category' => $this->selectedBeverageCategory,
                'price' => $this->selectedBeveragePrice,
                'purchase_price' => $this->selectedBeveragePurchasePrice,
                'min_stock' => $this->selectedBeverageMinStock,
            ]);

            ActivityLog::create([
                'type' => 'stock',
                'message' => "Mise à jour de l'article '{$bev->name}' par " . auth()->user()->name . ".",
            ]);
        }

        $this->showEditBeverageModal = false;
        $this->loadBeverages();
        $this->loadStats();
        $this->dispatch('toast', message: 'Article mis à jour !');
    }

    public function openAddBeverageModal(): void
    {
        $this->newBeverageName = '';
        $this->newBeverageCategory = 'Bière';
        $this->newBeveragePrice = 0;
        $this->newBeveragePurchasePrice = 0;
        $this->newBeverageMinStock = 10;
        $this->showAddBeverageModal = true;
    }

    public function createBeverage(): void
    {
        $this->validate([
            'newBeverageName' => 'required|string|min:2|unique:beverages,name',
            'newBeverageCategory' => 'required|string',
            'newBeveragePrice' => 'required|integer|min:0',
            'newBeveragePurchasePrice' => 'required|integer|min:0',
            'newBeverageMinStock' => 'required|integer|min:0',
        ]);

        \App\Models\Beverage::create([
            'name' => $this->newBeverageName,
            'category' => $this->newBeverageCategory,
            'price' => $this->newBeveragePrice,
            'purchase_price' => $this->newBeveragePurchasePrice,
            'min_stock' => $this->newBeverageMinStock,
            'stock' => 0,
        ]);

        ActivityLog::create([
            'type' => 'stock',
            'message' => "Nouvel article créé : '{$this->newBeverageName}' par " . auth()->user()->name . ".",
        ]);

        $this->showAddBeverageModal = false;
        $this->loadBeverages();
        $this->loadStats();
        $this->dispatch('toast', message: 'Nouvel article ajouté !');
    }

    public function confirmDeleteBeverage(int $id, string $name): void
    {
        $this->selectedBeverageId = $id;
        $this->selectedBeverageName = $name;
        $this->showDeleteBeverageModal = true;
    }

    public function deleteBeverage(): void
    {
        if ($this->selectedBeverageId) {
            $bev = \App\Models\Beverage::find($this->selectedBeverageId);
            
            if (!$bev) {
                $this->showDeleteBeverageModal = false;
                $this->selectedBeverageId = null;
                $this->dispatch('toast', message: 'Article introuvable.');
                return;
            }

            $name = $bev->name;
            $bev->delete();

            ActivityLog::create([
                'type' => 'stock',
                'message' => "Suppression de l'article '{$name}' par " . auth()->user()->name . ".",
            ]);
        }

        $this->showDeleteBeverageModal = false;
        $this->selectedBeverageId = null;
        $this->loadBeverages();
        $this->loadStats();
        $this->dispatch('toast', message: 'Article supprimé.');
    }
};
?>

<div class="w-full py-4 md:py-8 space-y-8">

    <!-- Header Section -->
    <header class="flex flex-col md:flex-row md:items-center md:justify-between border-b border-zinc-800 pb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white uppercase font-sans">Vue d'ensemble</h1>
            <p class="text-xs text-zinc-400 uppercase tracking-widest font-medium mt-1">Administration globale du CRM</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}" wire:navigate class="bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2 rounded-xl text-xs font-bold text-white transition cursor-pointer flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-green-500">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                Tableau de Bord
            </a>
            @can('access-accounting')
                <a href="{{ route('accounting') }}" wire:navigate class="bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2 rounded-xl text-xs font-bold text-white transition cursor-pointer flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-blue-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                    Comptabilité
                </a>
            @endcan
            <a href="{{ route('users') }}" wire:navigate class="bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2 rounded-xl text-xs font-bold text-white transition cursor-pointer flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-crm-yellow">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                </svg>
                Gérer l'Équipe
            </a>
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- VUE D'ENSEMBLE CDFINANCIÈRE (COMPTA) -->
        <section class="lg:col-span-12 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-4">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-tight">Vue d'Ensemble CDFinancière</h2>
                    <p class="text-xs text-zinc-500 mt-1">Résumé global de la comptabilité</p>
                </div>
                <a href="{{ route('accounting') }}" wire:navigate class="text-[10px] font-bold text-crm-yellow hover:underline uppercase tracking-widest">Voir détails →</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl">
                    <p class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Recettes Totales</p>
                    <p class="text-2xl font-black text-green-500 mt-1">+ {{ number_format($stats['total_income'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span></p>
                </div>
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl">
                    <p class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Dépenses Totales</p>
                    <p class="text-2xl font-black text-red-500 mt-1">- {{ number_format($stats['total_expense'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span></p>
                </div>
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl">
                    <p class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Solde Net Global</p>
                    <p class="text-2xl font-black {{ $stats['net_balance'] >= 0 ? 'text-crm-yellow' : 'text-orange-500' }} mt-1">
                        {{ $stats['net_balance'] >= 0 ? '+' : '' }} {{ number_format($stats['net_balance'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span>
                    </p>
                </div>
            </div>
        </section>

        <!-- VUE D'ENSEMBLE STOCK (VALORISATION) -->
        <section class="lg:col-span-12 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-4">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-tight">Valorisation des Stocks</h2>
                    <p class="text-xs text-zinc-500 mt-1">Valeur actuelle des produits en terrasse</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-[10px] font-bold text-zinc-400 bg-zinc-900 border border-zinc-800 px-2 py-1 rounded">Total {{ count($beverages) }} Articles</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl">
                    <p class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Coût d'Achat Total</p>
                    <p class="text-2xl font-black text-zinc-400 mt-1">{{ number_format($stats['stock_cost'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span></p>
                </div>
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl">
                    <p class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Valeur de Vente Totale</p>
                    <p class="text-2xl font-black text-white mt-1">{{ number_format($stats['stock_value'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span></p>
                </div>
                <div class="bg-zinc-900/50 border border-zinc-800 p-5 rounded-2xl border-crm-yellow/20">
                    <p class="text-[10px] uppercase font-bold text-crm-yellow tracking-wider">Marge Bénéficiaire Potentielle</p>
                    <p class="text-2xl font-black text-crm-yellow mt-1">
                        + {{ number_format($stats['potential_profit'], 0, ',', ' ') }} <span class="text-[10px] text-zinc-600">CDF</span>
                    </p>
                </div>
            </div>
        </section>

        <!-- CONFIGURATION HÔTEL (CHAMBRES) -->
        <section class="lg:col-span-12 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-4">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-tight">Configuration de l'Hôtel</h2>
                    <p class="text-xs text-zinc-500 mt-1">Gérer les chambres, les types et la tarification</p>
                </div>
                <div class="flex items-center gap-3">
                    <button wire:click="openAddRoomModal" class="bg-crm-yellow hover:bg-crm-yellow-hover text-black px-3 py-1.5 rounded-lg text-[10px] font-bold transition duration-150 shadow-lg shadow-yellow-500/5 cursor-pointer flex items-center gap-1.5 uppercase">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Ajouter une Chambre
                    </button>
                    <span class="text-[10px] font-bold text-zinc-400 bg-zinc-900 border border-zinc-800 px-2 py-1 rounded">{{ $stats['occupied_rooms'] }} / {{ count($rooms) }} Chambres Occupées</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($rooms as $room)
                    <div wire:key="room-{{ $room['id'] }}" class="bg-zinc-900/50 border border-zinc-800 rounded-xl p-4 flex items-center justify-between group hover:border-crm-yellow/30 transition">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-lg font-black text-white">#{{ $room['number'] }}</span>
                                <span class="text-[10px] uppercase font-bold text-zinc-500 bg-zinc-900 border border-zinc-800 px-1.5 py-0.5 rounded">{{ $room['type'] }}</span>
                            </div>
                            <div class="flex items-center gap-3 mt-1">
                                <p class="text-[10px] font-bold text-crm-yellow"><span class="text-zinc-500 uppercase">Nuit:</span> {{ number_format($room['price_night'], 0, ',', ' ') }} F</p>
                                <p class="text-[10px] font-bold text-blue-400"><span class="text-zinc-500 uppercase">Passage:</span> {{ number_format($room['price_passage'] ?? ($room['price_night'] / 2), 0, ',', ' ') }} F</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="openEditRoomModal({{ $room['id'] }})" class="p-2 bg-zinc-900 border border-zinc-800 text-zinc-400 hover:text-white rounded-lg transition cursor-pointer">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                            </button>
                            <button wire:click="confirmDeleteRoom({{ $room['id'] }}, '{{ $room['number'] }}')" class="p-2 bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-red-500 rounded-lg transition cursor-pointer shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <!-- CONFIGURATION TERRASSE (BOISSONS) -->
        <section class="lg:col-span-12 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6">
            <div class="flex items-center justify-between border-b border-zinc-900 pb-4">
                <div>
                    <h2 class="text-base font-bold text-white uppercase tracking-tight">Configuration de la Terrasse</h2>
                    <p class="text-xs text-zinc-500 mt-1">Gérer les articles, prix de vente et stocks d'alerte</p>
                </div>
                <div class="flex items-center gap-3">
                    <button wire:click="openAddBeverageModal" class="bg-crm-yellow hover:bg-crm-yellow-hover text-black px-3 py-1.5 rounded-lg text-[10px] font-bold transition duration-150 shadow-lg shadow-yellow-500/5 cursor-pointer flex items-center gap-1.5 uppercase">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Ajouter un Article
                    </button>
                    <span class="text-[10px] font-bold text-crm-yellow bg-crm-yellow/10 border border-crm-yellow/30 px-2 py-1 rounded">{{ $stats['low_stock_count'] }} Alertes Stock</span>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($beverages as $bev)
                    <div wire:key="bev-{{ $bev['id'] }}" class="bg-zinc-900/50 border border-zinc-800 rounded-xl p-4 flex items-center justify-between group hover:border-crm-yellow/30 transition">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="font-bold text-sm truncate text-white">{{ $bev['name'] }}</p>
                                <span class="text-[8px] uppercase font-bold text-zinc-600 bg-zinc-950 border border-zinc-800 px-1 py-0.5 rounded shrink-0">{{ $bev['category'] }}</span>
                            </div>
                            <div class="flex items-center gap-3 mt-1.5">
                                <p class="text-xs font-bold text-crm-yellow">{{ number_format($bev['price'], 0, ',', ' ') }} CDF</p>
                                <p class="text-[10px] text-zinc-500 font-medium">Achat : <span class="text-zinc-300">{{ number_format($bev['purchase_price'] ?? 0, 0, ',', ' ') }} F</span></p>
                                <p class="text-[10px] text-zinc-500 font-medium">Alerte à : <span class="text-zinc-300">{{ $bev['min_stock'] }} unités</span></p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="openEditBeverageModal({{ $bev['id'] }})" class="p-2 bg-zinc-900 border border-zinc-800 text-zinc-400 hover:text-white rounded-lg transition cursor-pointer shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                            </button>
                            <button wire:click="confirmDeleteBeverage({{ $bev['id'] }}, '{{ $bev['name'] }}')" class="p-2 bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-red-500 rounded-lg transition cursor-pointer shrink-0">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <!-- EDIT BEVERAGE MODAL -->
    @if($showEditBeverageModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 space-y-4">
                    <div class="text-center">
                        <h3 class="text-base font-bold text-white uppercase">Modifier l'Article</h3>
                        <p class="text-xs text-zinc-500 mt-1">Mise à jour des prix et stocks d'alerte</p>
                    </div>

                    <div class="space-y-4 pt-2">
                        <!-- Name -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Nom de l'article</label>
                            <input wire:model="selectedBeverageName" type="text" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                        </div>

                        <!-- Price & Min Stock Row -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Prix d'achat (Base)</label>
                                <input wire:model="selectedBeveragePurchasePrice" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Prix de vente</label>
                                <input wire:model="selectedBeveragePrice" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Alerte Stock (Min)</label>
                            <input wire:model="selectedBeverageMinStock" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-4">
                        <button wire:click="$set('showEditBeverageModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                            Annuler
                        </button>
                        <button wire:click="updateBeverage"
                            class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition cursor-pointer shadow-lg shadow-yellow-500/5">
                            Mettre à jour
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- EDIT ROOM MODAL -->
    @if($showEditRoomModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 space-y-4">
                    <div class="text-center">
                        <h3 class="text-base font-bold text-white uppercase">Modifier Chambre {{ $selectedRoomNumber }}</h3>
                        <p class="text-xs text-zinc-500 mt-1">Mise à jour des paramètres structurels</p>
                    </div>

                    <div class="space-y-4 pt-2">
                        <!-- Type -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Type de Chambre</label>
                            <input wire:model="selectedRoomType" type="text" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                        </div>

                        <!-- Prices Row -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Tarif Nuitée (CDF)</label>
                                <input wire:model="selectedRoomPriceNight" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Tarif Passage (CDF)</label>
                                <input wire:model="selectedRoomPricePassage" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-4">
                        <button wire:click="$set('showEditRoomModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                            Annuler
                        </button>
                        <button wire:click="updateRoom"
                            class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition cursor-pointer shadow-lg shadow-yellow-500/5">
                            Mettre à jour
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- ADD ROOM MODAL -->
    @if($showAddRoomModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 space-y-4">
                    <div class="text-center">
                        <h3 class="text-base font-bold text-white uppercase">Ajouter une Nouvelle Chambre</h3>
                        <p class="text-xs text-zinc-500 mt-1">Définition des tarifs et du type de chambre</p>
                    </div>

                    <form wire:submit.prevent="createRoom" class="space-y-4 pt-2">
                        <!-- Number -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Numéro de Chambre</label>
                            <input wire:model.defer="newRoomNumber" type="text" placeholder="Ex: 101, A1, etc." class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            @error('newRoomNumber') <span class="text-[10px] text-red-500 font-bold uppercase">{{ $message }}</span> @enderror
                        </div>

                        <!-- Type -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Type de Chambre</label>
                            <input wire:model.defer="newRoomType" type="text" placeholder="Ex: Standard, VIP, Suite" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                        </div>

                        <!-- Prices Row -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Tarif Nuitée (CDF)</label>
                                <input wire:model.defer="newRoomPriceNight" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Tarif Passage (CDF)</label>
                                <input wire:model.defer="newRoomPricePassage" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 pt-4">
                            <button type="button" wire:click="$set('showAddRoomModal', false)"
                                class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                                Annuler
                            </button>
                            <button type="submit"
                                class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition cursor-pointer shadow-lg shadow-yellow-500/5">
                                Créer la Chambre
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- ADD BEVERAGE MODAL -->
    @if($showAddBeverageModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 space-y-4">
                    <div class="text-center">
                        <h3 class="text-base font-bold text-white uppercase">Ajouter un Nouvel Article</h3>
                        <p class="text-xs text-zinc-500 mt-1">Créer une nouvelle référence pour la terrasse</p>
                    </div>

                    <form wire:submit.prevent="createBeverage" class="space-y-4 pt-2">
                        <!-- Name -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Nom de l'article</label>
                            <input wire:model.defer="newBeverageName" type="text" placeholder="Ex: Mutzig (65cl)" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            @error('newBeverageName') <span class="text-[10px] text-red-500 font-bold uppercase">{{ $message }}</span> @enderror
                        </div>

                        <!-- Category -->
                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Catégorie</label>
                            <select wire:model.defer="newBeverageCategory" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                                <option value="Bière">Bière</option>
                                <option value="Soft">Soft (Jus/Soda)</option>
                                <option value="Eau">Eau</option>
                                <option value="Vin">Vin</option>
                                <option value="Spiritueux">Spiritueux</option>
                                <option value="Autres">Autres</option>
                            </select>
                        </div>

                        <!-- Prices Row -->
                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Prix d'achat</label>
                                <input wire:model.defer="newBeveragePurchasePrice" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            </div>
                            <div class="space-y-1.5">
                                <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Prix de vente</label>
                                <input wire:model.defer="newBeveragePrice" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Alerte Stock (Min)</label>
                            <input wire:model.defer="newBeverageMinStock" type="number" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                        </div>

                        <div class="flex items-center gap-3 pt-4">
                            <button type="button" wire:click="$set('showAddBeverageModal', false)"
                                class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                                Annuler
                            </button>
                            <button type="submit"
                                class="flex-1 bg-crm-yellow hover:bg-crm-yellow-hover text-black py-2.5 rounded-xl font-bold text-xs transition cursor-pointer shadow-lg shadow-yellow-500/5">
                                Créer l'Article
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    <!-- DELETE BEVERAGE CONFIRMATION MODAL -->
    @if($showDeleteBeverageModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 text-center space-y-4">
                    <div class="w-14 h-14 bg-red-500/10 border border-red-500/20 rounded-2xl mx-auto flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7 text-red-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white uppercase">Supprimer l'Article</h3>
                        <p class="text-sm text-zinc-400 mt-2 leading-relaxed">
                            Êtes-vous sûr de vouloir supprimer <span class="font-bold text-white">{{ $selectedBeverageName }}</span> ?
                            Cette action supprimera également l'historique de stock associé à cet article.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="$set('showDeleteBeverageModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                            Annuler
                        </button>
                        <button wire:click="deleteBeverage"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2.5 rounded-xl font-bold text-xs transition cursor-pointer">
                            Supprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif


    <!-- DELETE ROOM CONFIRMATION MODAL -->
    @if($showDeleteRoomModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 text-center space-y-4">
                    <div class="w-14 h-14 bg-red-500/10 border border-red-500/20 rounded-2xl mx-auto flex items-center justify-center">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7 text-red-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-white uppercase">Supprimer la Chambre</h3>
                        <p class="text-sm text-zinc-400 mt-2 leading-relaxed">
                            Êtes-vous sûr de vouloir supprimer la chambre <span class="font-bold text-white">#{{ $selectedRoomNumber }}</span> ?
                            Cette action est irréversible.
                        </p>
                    </div>
                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="$set('showDeleteRoomModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-300 hover:text-white py-2.5 rounded-xl font-semibold text-xs transition cursor-pointer">
                            Annuler
                        </button>
                        <button wire:click="deleteRoom"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2.5 rounded-xl font-bold text-xs transition cursor-pointer">
                            Supprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>