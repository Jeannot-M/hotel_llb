<?php

use Livewire\Volt\Component;
use App\Models\Transaction;
use App\Models\ActivityLog;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.crm')] class extends Component {
    public string $period = 'mois'; // 'aujourdhui', 'semaine', 'mois', 'tous', 'personnalise'
    public string $filterType = 'tous'; // 'tous', 'entree', 'sortie'
    public string $filterCategory = 'tous'; // 'tous', 'Chambre', 'Bar', 'Approvisionnement', 'Salaires', 'Autres'
    public string $search = '';
    public string $deleteReason = '';

    // Custom date range
    public string $startDate = '';
    public string $endDate = '';

    // Manual Transaction Form
    public bool $showAddModal = false;
    public bool $showDeleteModal = false;
    public ?int $selectedTransactionId = null;

    public string $txType = 'sortie';
    public string $txCategory = 'Autres';
    public int $txAmount = 0;
    public string $txDescription = '';
    public string $txDate = '';

    public function mount(): void
    {
        $this->txDate = now()->toDateString();
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->toDateString();
    }

    public function getTransactionsProperty()
    {
        $query = Transaction::with('user')->orderBy('date', 'desc')->orderBy('created_at', 'desc');

        // Search filter
        if ($this->search) {
            $query->where('description', 'like', '%' . $this->search . '%');
        }

        // Period filter
        if ($this->period === 'aujourdhui') {
            $query->whereDate('date', now()->toDateString());
        } elseif ($this->period === 'semaine') {
            $query->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($this->period === 'mois') {
            $query->whereBetween('date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        } elseif ($this->period === 'personnalise') {
            if ($this->startDate && $this->endDate) {
                $query->whereBetween('date', [$this->startDate, $this->endDate]);
            }
        }

        // Type filter
        if ($this->filterType !== 'tous') {
            $query->where('type', $this->filterType);
        }

        // Category filter
        if ($this->filterCategory !== 'tous') {
            $query->where('category', $this->filterCategory);
        }

        return $query->get();
    }

    public function resetFilters(): void
    {
        $this->period = 'mois';
        $this->filterType = 'tous';
        $this->filterCategory = 'tous';
        $this->search = '';
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->toDateString();
    }

    public function confirmDelete(int $id): void
    {
        $this->selectedTransactionId = $id;
        $this->deleteReason = '';
        $this->showDeleteModal = true;
    }

    public function deleteTransaction(): void
    {
        if (!auth()->user()->isAdmin()) {
            $this->dispatch('toast', message: 'Action non autorisée.');
            return;
        }

        if (!$this->selectedTransactionId) {
            $this->showDeleteModal = false;
            return;
        }

        $this->validate([
            'deleteReason' => 'required|string|min:5',
        ], [
            'deleteReason.required' => 'Le motif de suppression est obligatoire.',
            'deleteReason.min' => 'Le motif doit faire au moins 5 caractères.',
        ]);

        $tx = Transaction::find($this->selectedTransactionId);
        
        if (!$tx) {
            $this->showDeleteModal = false;
            $this->selectedTransactionId = null;
            $this->dispatch('toast', message: 'Transaction introuvable.');
            return;
        }
        
        ActivityLog::create([
            'type' => 'finance',
            'message' => "SUPPRESSION TRANSACTION (Motif: {$this->deleteReason}) : " . ($tx->type === 'entree' ? 'Recette' : 'Dépense') . " de " . number_format($tx->amount, 0, ',', ' ') . " CDF ({$tx->category} - {$tx->description}) par " . auth()->user()->name . ".",
        ]);

        $tx->delete();

        $this->showDeleteModal = false;
        $this->selectedTransactionId = null;
        $this->deleteReason = '';
        $this->dispatch('toast', message: 'Transaction supprimée.');
    }

    public function getStatsProperty()
    {
        $txs = $this->transactions;

        $income = $txs->where('type', 'entree')->sum('amount');
        $expense = $txs->where('type', 'sortie')->sum('amount');
        $net = $income - $expense;

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $net
        ];
    }

    public function openAddModal(): void
    {
        $this->txType = 'sortie';
        $this->txCategory = 'Autres';
        $this->txAmount = 0;
        $this->txDescription = '';
        $this->txDate = now()->toDateString();
        $this->showAddModal = true;
    }

    public function saveTransaction(): void
    {
        $this->validate([
            'txType' => 'required|in:entree,sortie',
            'txCategory' => 'required|string',
            'txAmount' => 'required|integer|min:1',
            'txDescription' => 'required|string|min:3',
            'txDate' => 'required|date',
        ]);

        Transaction::create([
            'type' => $this->txType,
            'category' => $this->txCategory,
            'amount' => $this->txAmount,
            'description' => $this->txDescription,
            'date' => $this->txDate,
            'user_id' => auth()->id(),
        ]);

        ActivityLog::create([
            'type' => 'finance',
            'message' => "Transaction enregistrée : " . ($this->txType === 'entree' ? 'Recette' : 'Dépense') . " de " . number_format($this->txAmount, 0, ',', ' ') . " CDF ({$this->txCategory} - {$this->txDescription}) par " . auth()->user()->name . ".",
        ]);

        $this->showAddModal = false;

        $this->dispatch('toast', message: 'Transaction enregistrée !');
    }
};
?>

<div class="w-full py-4 md:py-8 space-y-8">

    <!-- Header Section -->
    <header class="flex flex-col gap-6 border-b border-zinc-800 pb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-xl md:text-2xl font-black tracking-tight text-white uppercase font-sans">COMPTABILITÉ</h1>
                <p class="text-[10px] text-zinc-500 uppercase tracking-[0.2em] font-bold mt-1.5">Suivi de Trésorerie & Flux Financiers</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('dashboard') }}" wire:navigate class="flex-1 md:flex-none justify-center bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2.5 rounded-xl text-xs font-bold text-zinc-400 hover:text-white transition cursor-pointer flex items-center gap-2 shadow-lg">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4 text-green-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    <span class="hidden sm:inline">Accueil</span>
                </a>
                <button wire:click="openAddModal"
                    class="flex-[2] md:flex-none justify-center flex items-center gap-2 bg-crm-yellow hover:bg-crm-yellow-hover text-black px-5 py-2.5 rounded-xl text-xs font-black transition duration-150 shadow-xl shadow-yellow-500/10 cursor-pointer uppercase tracking-tight">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="3"
                        stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nouvelle Opération
                </button>
            </div>
        </div>

        <!-- Stats Horizontal Scroll Row -->
        <div class="flex flex-nowrap overflow-x-auto pb-2 -mx-4 px-4 sm:mx-0 sm:px-0 gap-3 scrollbar-hide">
            <!-- Recettes -->
            <div class="flex-none w-[200px] md:w-auto md:flex-1 bg-zinc-950 border border-zinc-900 p-4 rounded-2xl flex flex-col justify-between min-h-[100px] transition hover:border-zinc-800">
                <div class="flex items-center justify-between text-[10px] uppercase font-black text-zinc-500 tracking-widest">
                    <span>Recettes</span>
                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                </div>
                <div class="mt-3">
                    <p class="text-xl md:text-2xl font-black text-white font-mono leading-none">
                        + {{ number_format($this->stats['income'], 0, ',', ' ') }} F
                    </p>
                </div>
            </div>

            <!-- Dépenses -->
            <div class="flex-none w-[200px] md:w-auto md:flex-1 bg-zinc-950 border border-zinc-900 p-4 rounded-2xl flex flex-col justify-between min-h-[100px] transition hover:border-zinc-800">
                <div class="flex items-center justify-between text-[10px] uppercase font-black text-zinc-500 tracking-widest">
                    <span>Dépenses</span>
                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                </div>
                <div class="mt-3">
                    <p class="text-xl md:text-2xl font-black text-white font-mono leading-none">
                        - {{ number_format($this->stats['expense'], 0, ',', ' ') }} F
                    </p>
                </div>
            </div>

            <!-- Solde Net -->
            <div class="flex-none w-[200px] md:w-auto md:flex-1 bg-zinc-950 border rounded-2xl p-4 flex flex-col justify-between min-h-[100px] transition {{ $this->stats['net'] >= 0 ? 'border-crm-yellow/30 bg-crm-yellow/[0.02]' : 'border-red-500/20 bg-red-500/[0.01]' }}">
                <div class="flex items-center justify-between text-[10px] uppercase font-black tracking-widest {{ $this->stats['net'] >= 0 ? 'text-crm-yellow' : 'text-red-400' }}">
                    <span>Solde Net</span>
                    <span class="w-2 h-2 rounded-full {{ $this->stats['net'] >= 0 ? 'bg-crm-yellow' : 'bg-red-400' }}"></span>
                </div>
                <div class="mt-3">
                    <p class="text-xl md:text-2xl font-black font-mono leading-none {{ $this->stats['net'] >= 0 ? 'text-crm-yellow' : 'text-red-400' }}">
                        {{ $this->stats['net'] >= 0 ? '+' : '' }} {{ number_format($this->stats['net'], 0, ',', ' ') }} F
                    </p>
                </div>
            </div>
        </div>
    </header>

    <!-- Filters Bar Card -->
    <section class="bg-zinc-950 border border-zinc-900 rounded-2xl p-4 md:p-5 space-y-4 shadow-sm">
        <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <!-- Search Bar -->
            <div class="flex-1 w-full relative group">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none transition group-focus-within:text-crm-yellow">
                    <svg class="h-4 w-4 text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Rechercher une opération..." 
                    class="w-full bg-zinc-900 border border-zinc-800 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-zinc-600 focus:outline-none focus:ring-1 focus:ring-crm-yellow focus:border-crm-yellow transition font-medium">
            </div>

            <!-- Scrollable Period selectors -->
            <div class="flex flex-nowrap overflow-x-auto -mx-4 px-4 lg:mx-0 lg:px-0 gap-1.5 scrollbar-hide pb-1">
                <button wire:click="$set('period', 'aujourdhui')"
                    class="flex-none px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest border transition duration-300 {{ $period === 'aujourdhui' ? 'bg-crm-yellow text-black border-crm-yellow' : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:text-white hover:border-zinc-700' }}">
                    Aujourd'hui
                </button>
                <button wire:click="$set('period', 'semaine')"
                    class="flex-none px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest border transition duration-300 {{ $period === 'semaine' ? 'bg-crm-yellow text-black border-crm-yellow' : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:text-white hover:border-zinc-700' }}">
                    Semaine
                </button>
                <button wire:click="$set('period', 'mois')"
                    class="flex-none px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest border transition duration-300 {{ $period === 'mois' ? 'bg-crm-yellow text-black border-crm-yellow' : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:text-white hover:border-zinc-700' }}">
                    Mois
                </button>
                <button wire:click="$set('period', 'tous')"
                    class="flex-none px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest border transition duration-300 {{ $period === 'tous' ? 'bg-crm-yellow text-black border-crm-yellow' : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:text-white hover:border-zinc-700' }}">
                    Tous
                </button>
                <button wire:click="$set('period', 'personnalise')"
                    class="flex-none px-3.5 py-2 rounded-lg text-[10px] font-black uppercase tracking-widest border transition duration-300 {{ $period === 'personnalise' ? 'bg-crm-yellow text-black border-crm-yellow' : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:text-white hover:border-zinc-700' }}">
                    Date...
                </button>
            </div>

            <!-- Dropdown Filters Group -->
            <div class="grid grid-cols-2 sm:flex sm:items-center gap-2">
                <div class="relative">
                    <select wire:model.live="filterType" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl text-[10px] font-bold uppercase tracking-widest text-white px-3 py-2.5 outline-none focus:border-crm-yellow transition appearance-none cursor-pointer">
                        <option value="tous">Flux: Tous</option>
                        <option value="entree">Recettes</option>
                        <option value="sortie">Dépenses</option>
                    </select>
                </div>
                <div class="relative">
                    <select wire:model.live="filterCategory" class="w-full bg-zinc-900 border border-zinc-800 rounded-xl text-[10px] font-bold uppercase tracking-widest text-white px-3 py-2.5 outline-none focus:border-crm-yellow transition appearance-none cursor-pointer">
                        <option value="tous">Catégorie: Tout</option>
                        <option value="Chambre">Chambre</option>
                        <option value="Bar">Bar</option>
                        <option value="Approvisionnement">Stocks</option>
                        <option value="Salaires">Salaires</option>
                        <option value="Autres">Autres</option>
                    </select>
                </div>
                <button wire:click="resetFilters" class="sm:flex-none p-2.5 bg-zinc-900 border border-zinc-800 text-zinc-500 hover:text-white rounded-xl transition cursor-pointer flex items-center justify-center shadow-lg" title="Réinitialiser">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-4 h-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Custom Date Range Picker (shown when period === personnalise) -->
        @if($period === 'personnalise')
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-3 pt-3 border-t border-zinc-900" x-transition>
                <div class="space-y-1">
                    <label class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Date de Début</label>
                    <input wire:model="startDate" type="date"
                        class="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-1.5 text-xs text-white outline-none focus:border-crm-yellow">
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] uppercase font-bold text-zinc-500 tracking-wider">Date de Fin</label>
                    <input wire:model="endDate" type="date"
                        class="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-1.5 text-xs text-white outline-none focus:border-crm-yellow">
                </div>
            </div>
        @endif
    </section>

    <!-- Transactions List Section -->
    <section class="space-y-4">
        <div class="flex items-center justify-between px-1">
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-4 bg-crm-yellow rounded-full"></div>
                <h2 class="text-sm font-black text-white uppercase tracking-widest">Opérations ({{ count($this->transactions) }})</h2>
            </div>
            <span class="text-[10px] text-zinc-500 font-bold uppercase tracking-tighter">Période: {{ ucfirst($period) }}</span>
        </div>

        <!-- Desktop Table View -->
        <div class="hidden lg:block bg-zinc-950 border border-zinc-900 rounded-3xl overflow-hidden shadow-sm">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-zinc-900/50 border-b border-zinc-900 text-zinc-500 font-black uppercase tracking-[0.2em] text-[9px]">
                        <th class="px-6 py-4">Date</th>
                        <th class="px-6 py-4 text-center">Flux</th>
                        <th class="px-6 py-4">Catégorie</th>
                        <th class="px-6 py-4">Désignation</th>
                        <th class="px-6 py-4">Auteur</th>
                        <th class="px-6 py-4 text-right">Montant (CDF)</th>
                        <th class="px-6 py-4 text-center">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-900 text-zinc-300">
                    @forelse($this->transactions as $tx)
                        <tr wire:key="tx-desktop-{{ $tx->id }}" class="hover:bg-zinc-900/30 transition-colors duration-150 group">
                            <td class="px-6 py-4 font-bold whitespace-nowrap text-zinc-400">{{ $tx->date->format('d/m/Y') }}</td>
                            <td class="px-6 py-4 text-center">
                                @if($tx->type === 'entree')
                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-green-500/10 text-green-500 border border-green-500/20 rounded-lg text-[9px] font-black uppercase tracking-widest">
                                        <span class="w-1 h-1 rounded-full bg-green-500 animate-pulse"></span>
                                        Recette
                                    </div>
                                @else
                                    <div class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-red-500/10 text-red-400 border border-red-500/20 rounded-lg text-[9px] font-black uppercase tracking-widest">
                                        <span class="w-1 h-1 rounded-full bg-red-500"></span>
                                        Dépense
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $catColor = match ($tx->category) {
                                        'Chambre' => 'text-blue-400 bg-blue-500/10 border-blue-500/20',
                                        'Bar' => 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20',
                                        'Approvisionnement' => 'text-orange-400 bg-orange-500/10 border-orange-500/20',
                                        'Salaires' => 'text-purple-400 bg-purple-500/10 border-purple-500/20',
                                        default => 'text-zinc-500 bg-zinc-800/40 border-zinc-700/30'
                                    };
                                @endphp
                                <span class="px-2.5 py-1 text-[9px] font-black border rounded-lg uppercase tracking-widest {{ $catColor }}">
                                    {{ $tx->category }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-white leading-relaxed line-clamp-1 group-hover:line-clamp-none transition-all">{{ $tx->description }}</p>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-5 h-5 rounded-md bg-zinc-900 border border-zinc-800 flex items-center justify-center text-[8px] font-black text-zinc-500 uppercase">
                                        {{ substr($tx->user?->name ?? '?', 0, 1) }}
                                    </div>
                                    <span class="text-zinc-500 font-bold uppercase tracking-tighter">{{ $tx->user ? $tx->user->name : 'N/A' }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="font-mono font-black text-sm whitespace-nowrap {{ $tx->type === 'entree' ? 'text-green-500' : 'text-white' }}">
                                    {{ number_format($tx->amount, 0, ',', ' ') }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if(auth()->user()->isAdmin())
                                    <button wire:click="confirmDelete({{ $tx->id }})" class="p-2 text-zinc-700 hover:text-red-500 transition-colors cursor-pointer group/btn" title="Supprimer">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 transition-transform group-active/btn:scale-90">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                        </svg>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-12 text-center text-zinc-600 font-bold uppercase tracking-widest text-[10px] italic">
                                Aucune transaction enregistrée
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile Card View -->
        <div class="lg:hidden space-y-3">
            @forelse($this->transactions as $tx)
                <div wire:key="tx-mobile-{{ $tx->id }}" class="bg-zinc-950 border border-zinc-900 rounded-2xl p-4 shadow-sm space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="text-[10px] font-black text-zinc-500 uppercase tracking-widest">{{ $tx->date->format('d M Y') }}</span>
                            @if($tx->type === 'entree')
                                <span class="px-2 py-0.5 bg-green-500/10 text-green-500 border border-green-500/20 rounded-md text-[8px] font-black uppercase tracking-widest">Recette</span>
                            @else
                                <span class="px-2 py-0.5 bg-red-500/10 text-red-400 border border-red-500/20 rounded-md text-[8px] font-black uppercase tracking-widest">Dépense</span>
                            @endif
                        </div>
                        @if(auth()->user()->isAdmin())
                            <button wire:click="confirmDelete({{ $tx->id }})" class="p-1.5 bg-zinc-900 border border-zinc-800 text-zinc-600 hover:text-red-500 rounded-lg transition-colors">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        @endif
                    </div>

                    <div>
                        <div class="flex items-center gap-2 mb-1.5">
                            @php
                                $catColorMob = match ($tx->category) {
                                    'Chambre' => 'text-blue-400 bg-blue-500/10 border-blue-500/20',
                                    'Bar' => 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20',
                                    'Approvisionnement' => 'text-orange-400 bg-orange-500/10 border-orange-500/20',
                                    'Salaires' => 'text-purple-400 bg-purple-500/10 border-purple-500/20',
                                    default => 'text-zinc-500 bg-zinc-800/40 border-zinc-700/30'
                                };
                            @endphp
                            <span class="px-2 py-0.5 text-[8px] font-black border rounded uppercase tracking-widest {{ $catColorMob }}">
                                {{ $tx->category }}
                            </span>
                        </div>
                        <p class="text-xs font-black text-white leading-normal uppercase tracking-tight">{{ $tx->description }}</p>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-zinc-900">
                        <div class="flex items-center gap-2">
                            <div class="w-4 h-4 rounded bg-zinc-900 border border-zinc-800 flex items-center justify-center text-[7px] font-black text-zinc-600 uppercase">
                                {{ substr($tx->user?->name ?? '?', 0, 1) }}
                            </div>
                            <span class="text-[9px] text-zinc-500 font-bold uppercase tracking-tight">{{ $tx->user ? $tx->user->name : 'Système' }}</span>
                        </div>
                        <p class="font-mono font-black text-sm {{ $tx->type === 'entree' ? 'text-green-500' : 'text-white' }}">
                            {{ number_format($tx->amount, 0, ',', ' ') }} F
                        </p>
                    </div>
                </div>
            @empty
                <div class="py-12 flex flex-col items-center justify-center opacity-30">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-12 h-12 mb-3"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    <p class="text-[10px] font-black uppercase tracking-[0.2em]">Aucune Opération</p>
                </div>
            @endforelse
        </div>
    </section>

    <!-- RECORD TRANSACTION MODAL -->
    @if($showAddModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-md w-full overflow-hidden shadow-2xl">
                <div class="p-5 border-b border-zinc-900 flex justify-between items-center bg-zinc-900">
                    <div>
                        <h3 class="text-sm font-black text-white uppercase tracking-widest">Nouvelle Opération</h3>
                        <p class="text-[10px] text-zinc-500 font-bold uppercase tracking-tight mt-1">Saisie manuelle de flux</p>
                    </div>
                    <button wire:click="$set('showAddModal', false)"
                        class="w-8 h-8 flex items-center justify-center rounded-full bg-white/5 text-zinc-500 hover:text-white transition cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5"
                            stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form wire:submit.prevent="saveTransaction" class="p-5 space-y-5">

                    <!-- Type selector buttons -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Nature du flux</label>
                        <div class="grid grid-cols-2 gap-3">
                            <button type="button" wire:click="$set('txType', 'entree')"
                                class="py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border transition duration-300 flex items-center justify-center gap-2 {{ $txType === 'entree' ? 'bg-green-500 border-green-500 text-black shadow-lg shadow-green-500/20' : 'bg-zinc-900 border-zinc-800 text-zinc-500 hover:text-white' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $txType === 'entree' ? 'bg-black animate-pulse' : 'bg-green-500' }}"></span>
                                Recette
                            </button>
                            <button type="button" wire:click="$set('txType', 'sortie')"
                                class="py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest border transition duration-300 flex items-center justify-center gap-2 {{ $txType === 'sortie' ? 'bg-red-500 border-red-500 text-white shadow-lg shadow-red-500/20' : 'bg-zinc-900 border-zinc-800 text-zinc-500 hover:text-white' }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $txType === 'sortie' ? 'bg-white animate-pulse' : 'bg-red-500' }}"></span>
                                Dépense
                            </button>
                        </div>
                    </div>

                    <!-- Category -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Catégorie</label>
                        <select wire:model="txCategory"
                            class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-2xl px-4 py-3 text-xs text-white font-bold outline-none transition appearance-none cursor-pointer"
                            required>
                            @if($txType === 'entree')
                                <option value="Chambre">Hébergement (Chambre)</option>
                                <option value="Bar">Consommation (Bar)</option>
                                <option value="Autres">Autres Recettes</option>
                            @else
                                <option value="Approvisionnement">Approvisionnement Stocks</option>
                                <option value="Salaires">Salaires & Commissions</option>
                                <option value="Loyer">Loyer & Charges Fixes</option>
                                <option value="Entretien">Maintenance & Entretien</option>
                                <option value="Factures">Factures (Eau, Élec, etc.)</option>
                                <option value="Autres">Autres Dépenses</option>
                            @endif
                        </select>
                    </div>

                    <!-- Amount -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Montant total (CDF)</label>
                        <input wire:model.defer="txAmount" type="number" min="1" placeholder="0"
                            class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-2xl px-4 py-3 text-sm text-white font-black outline-none transition font-mono shadow-inner"
                            required>
                        @error('txAmount') <span class="text-[10px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span> @enderror
                    </div>

                    <!-- Description -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Libellé / Motif</label>
                        <input wire:model.defer="txDescription" type="text" placeholder="Détail de l'opération..."
                            class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-2xl px-4 py-3 text-xs text-white font-bold outline-none transition shadow-inner"
                            required>
                        @error('txDescription') <span class="text-[10px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span>
                        @enderror
                    </div>

                    <!-- Date -->
                    <div class="space-y-2">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Date de valeur</label>
                        <input wire:model.defer="txDate" type="date"
                            class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-2xl px-4 py-3 text-xs text-white font-bold outline-none transition shadow-inner"
                            required>
                        @error('txDate') <span class="text-[10px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span> @enderror
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-3 pt-4">
                        <button type="button" wire:click="$set('showAddModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-400 hover:text-white py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest transition cursor-pointer">
                            Annuler
                        </button>
                        <button type="submit"
                            class="flex-1 bg-white hover:bg-zinc-200 text-black py-3 rounded-2xl font-black text-[10px] uppercase tracking-widest transition cursor-pointer shadow-xl shadow-white/5">
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif


    <!-- DELETE CONFIRMATION MODAL -->
    @if($showDeleteModal)
        <div class="fixed inset-0 z-[110] bg-black/90 backdrop-blur-md flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-white/5 rounded-[32px] max-w-sm w-full overflow-hidden shadow-2xl animate-in fade-in zoom-in duration-300">
                <div class="p-8 text-center space-y-6">
                    <div class="w-20 h-20 bg-red-500/10 border border-red-500/20 rounded-3xl mx-auto flex items-center justify-center shadow-inner">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-10 h-10 text-red-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-white uppercase tracking-widest">Confirmation</h3>
                        <p class="text-[11px] text-zinc-500 font-bold uppercase tracking-tight mt-3 leading-relaxed">
                            Voulez-vous vraiment supprimer cette opération ? Cette action est définitive.
                        </p>
                    </div>

                    <div class="space-y-2 text-left">
                        <label class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Motif de suppression (Obligatoire)</label>
                        <textarea wire:model.defer="deleteReason" 
                            class="w-full bg-zinc-900 border border-zinc-800 focus:border-red-500 rounded-2xl px-4 py-3 text-xs text-white font-bold outline-none transition"
                            placeholder="Pourquoi supprimez-vous cette transaction ?" rows="3"></textarea>
                        @error('deleteReason') <span class="text-[9px] text-red-500 font-bold uppercase tracking-tight">{{ $message }}</span> @enderror
                    </div>

                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="$set('showDeleteModal', false)"
                            class="flex-1 bg-zinc-900 hover:bg-zinc-800 border border-white/5 text-zinc-400 hover:text-white py-3.5 rounded-2xl font-black text-[10px] uppercase tracking-widest transition cursor-pointer">
                            Annuler
                        </button>
                        <button wire:click="deleteTransaction"
                            class="flex-1 bg-red-600 hover:bg-red-700 text-white py-3.5 rounded-2xl font-black text-[10px] uppercase tracking-widest transition cursor-pointer shadow-lg shadow-red-600/20">
                            Supprimer
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>