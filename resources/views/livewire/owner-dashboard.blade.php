<?php

use Livewire\Volt\Component;
use App\Models\Room;
use App\Models\Beverage;
use App\Models\Transaction;
use App\Models\ActivityLog;
use App\Models\User;
use Livewire\Attributes\Layout;
use Carbon\Carbon;

new #[Layout('components.layouts.crm')] class extends Component {
    
    public bool $showMobileMenu = false;

    // Filters and pagination for Activity Log
    public string $activitySearch = '';
    public string $activityType = 'tous';
    public string $activityUserId = 'tous';
    public int $activityLimit = 15;

    // Custom period for PDF exports
    public string $reportStartDate = '';
    public string $reportEndDate = '';

    public function mount(): void
    {
        $this->reportStartDate = now()->toDateString();
        $this->reportEndDate = now()->toDateString();
    }

    public function loadMoreActivity(): void
    {
        $this->activityLimit += 15;
    }

    public function resetActivityFilters(): void
    {
        $this->activitySearch = '';
        $this->activityType = 'tous';
        $this->activityUserId = 'tous';
        $this->activityLimit = 15;
    }

    public function getUsersProperty()
    {
        return User::orderBy('name')->get();
    }

    public function getStatsProperty()
    {
        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();

        // Today Stats
        $incomeToday = Transaction::where('type', 'entree')->whereDate('date', $today)->sum('amount');
        $expenseToday = Transaction::where('type', 'sortie')->whereDate('date', $today)->sum('amount');

        // Week Stats
        $incomeWeek = Transaction::where('type', 'entree')->whereDate('date', '>=', $startOfWeek)->sum('amount');
        $expenseWeek = Transaction::where('type', 'sortie')->whereDate('date', '>=', $startOfWeek)->sum('amount');
        
        // Month Stats
        $incomeMonth = Transaction::where('type', 'entree')->whereDate('date', '>=', $startOfMonth)->sum('amount');
        $expenseMonth = Transaction::where('type', 'sortie')->whereDate('date', '>=', $startOfMonth)->sum('amount');

        // Hotel Stats
        $rooms = Room::all();
        $totalRooms = $rooms->count();
        $occupiedRooms = $rooms->where('status', 'occupé')->count();
        $dirtyRooms = $rooms->where('status', 'nettoyage')->count();
        $overstayedCount = $rooms->filter(fn($r) => $r->isOverstayed())->count();
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

        // Stock Stats
        $lowStockCount = Beverage::where('stock', '<=', 5)->count();

        return [
            'income_today' => $incomeToday,
            'expense_today' => $expenseToday,
            'net_today' => $incomeToday - $expenseToday,
            'income_week' => $incomeWeek,
            'expense_week' => $expenseWeek,
            'net_week' => $incomeWeek - $expenseWeek,
            'income_month' => $incomeMonth,
            'expense_month' => $expenseMonth,
            'net_month' => $incomeMonth - $expenseMonth,
            'total_rooms' => $totalRooms,
            'occupied_rooms' => $occupiedRooms,
            'dirty_rooms' => $dirtyRooms,
            'overstayed_count' => $overstayedCount,
            'occupancy_rate' => $occupancyRate,
            'low_stock_count' => $lowStockCount,
        ];
    }

    public function getUpcomingCheckoutsProperty()
    {
        $occupiedRooms = Room::where('status', 'occupé')->get();
        $checkouts = [];

        foreach ($occupiedRooms as $room) {
            if (!$room->checked_in_at) continue;

            $checkoutTime = null;
            if ($room->stay_type === 'passage') {
                $checkoutTime = $room->checked_in_at->addHours(4);
            } else {
                $checkoutTime = $room->checked_in_at->addDays($room->stay_duration)->startOfDay()->addHours(12);
            }

            $diff = now()->diff($checkoutTime);
            $isLate = now()->gt($checkoutTime);

            $checkouts[] = [
                'room_number' => $room->number,
                'guest_name' => $room->guest_name,
                'checkout_at' => $checkoutTime,
                'stay_type' => $room->stay_type,
                'is_late' => $isLate,
                'remaining' => $isLate ? "En retard" : $this->formatDiff($diff),
            ];
        }

        usort($checkouts, fn($a, $b) => $a['checkout_at'] <=> $b['checkout_at']);

        return array_slice($checkouts, 0, 10);
    }

    private function formatDiff($diff)
    {
        if ($diff->d > 0) return $diff->d . "j " . $diff->h . "h";
        if ($diff->h > 0) return $diff->h . "h " . $diff->i . "m";
        return $diff->i . " min";
    }

    public function getCriticalStocksProperty()
    {
        return Beverage::where('stock', '<=', 10)->orderBy('stock')->take(5)->get();
    }

    public function getRecentActivitiesProperty()
    {
        $query = ActivityLog::with('user');

        if ($this->activitySearch) {
            $query->where('message', 'like', '%' . $this->activitySearch . '%');
        }
        if ($this->activityType !== 'tous') {
            $query->where('type', $this->activityType);
        }
        if ($this->activityUserId !== 'tous') {
            $query->where('user_id', $this->activityUserId);
        }

        return $query->orderBy('created_at', 'desc')->take($this->activityLimit)->get();
    }

    public function getRecentTransactionsProperty()
    {
        return Transaction::with('user')->orderBy('created_at', 'desc')->take(6)->get();
    }

    public function getDeletedTransactionsCountProperty()
    {
        return ActivityLog::where('type', 'finance')
            ->where('message', 'like', '%SUPPRESSION TRANSACTION%')
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();
    }

    public function getUserStatsProperty()
    {
        $users = User::orderBy('name')->get();
        $stats = [];
        $today = now()->toDateString();

        foreach ($users as $user) {
            $barToday = Transaction::where('user_id', $user->id)
                ->where('category', 'Bar')
                ->where('type', 'entree')
                ->whereDate('date', $today)
                ->sum('amount');
            
            $hotelToday = Transaction::where('user_id', $user->id)
                ->where('category', 'Chambre')
                ->where('type', 'entree')
                ->whereDate('date', $today)
                ->sum('amount');
            
            $otherToday = Transaction::where('user_id', $user->id)
                ->where('type', 'entree')
                ->whereNotIn('category', ['Bar', 'Chambre'])
                ->whereDate('date', $today)
                ->sum('amount');

            $txCountToday = Transaction::where('user_id', $user->id)
                ->whereDate('date', $today)
                ->count();

            $totalRevenueToday = $barToday + $hotelToday + $otherToday;

            if ($totalRevenueToday > 0 || $txCountToday > 0) {
                $stats[] = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'bar_revenue' => $barToday,
                    'hotel_revenue' => $hotelToday,
                    'total_revenue' => $totalRevenueToday,
                    'tx_count' => $txCountToday,
                ];
            }
        }

        return $stats;
    }

    public function logout(): void
    {
        auth()->guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
        $this->redirect('/', navigate: true);
    }
}; ?>
<div class="w-full py-6 md:py-10 space-y-10">
    <!-- Header Section -->
    <header class="flex flex-col gap-6 border-b border-zinc-800 pb-8">
        <div class="flex items-center justify-between w-full gap-4">
            <!-- Logo Section -->
            <div class="flex items-center gap-4">
                <img src="{{ asset('images/logo.png') }}" alt="Logo" class="h-10 w-auto grayscale-0 brightness-110">
                <div class="h-6 w-px bg-zinc-800"></div>
                <div>
                    <h1 class="text-sm font-black text-white leading-none">VUE AUDIT</h1>
                    <p class="text-[11px] text-zinc-500 uppercase tracking-widest mt-0.5">LULUABOURG</p>
                </div>
            </div>

            <!-- Controls (Date pickers & PDF on desktop, Alerts, Hamburger) -->
            <div class="flex items-center gap-3">
                <!-- Date pickers & PDF (Hidden on mobile, flex on md and up) -->
                <div class="hidden md:flex items-center bg-zinc-900/50 border border-zinc-800 rounded-xl px-4 py-2 gap-3">
                    <div class="flex items-center gap-2">
                        <div class="flex flex-col">
                            <span class="text-xs text-zinc-500 font-black uppercase tracking-widest">Du</span>
                            <input type="date" wire:model.live="reportStartDate" class="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-0.5 text-xs text-white font-mono outline-none focus:border-crm-yellow">
                        </div>
                        <div class="flex flex-col">
                            <span class="text-xs text-zinc-500 font-black uppercase tracking-widest">Au</span>
                            <input type="date" wire:model.live="reportEndDate" class="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-0.5 text-xs text-white font-mono outline-none focus:border-crm-yellow">
                        </div>
                    </div>
                    <a href="{{ route('report.daily', ['start_date' => $reportStartDate, 'end_date' => $reportEndDate]) }}" target="_blank" class="p-2 bg-zinc-800 hover:bg-zinc-700 text-white rounded-xl transition cursor-pointer flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-green-500">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231a1.125 1.125 0 0 1-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-14.326 0C3.768 7.44 3 8.375 3 9.456V15.75a2.25 2.25 0 0 0 2.25 2.25h1.091M9 10.125h6M9 13h4" />
                        </svg>
                        <span class="text-xs font-black uppercase whitespace-nowrap">PDF Rapport</span>
                    </a>
                </div>

                <!-- Alerts (Hidden on mobile, shown on sm) -->
                @if($this->deleted_transactions_count > 0)
                    <div class="hidden sm:flex px-3 py-2 bg-red-950/40 border border-red-900/40 text-red-400 rounded-xl items-center gap-2 shadow-lg animate-pulse">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <span class="text-xs font-black uppercase tracking-wider">Suppressions: {{ $this->deleted_transactions_count }}</span>
                    </div>
                @endif

                <!-- Hamburger Menu Button -->
                <button wire:click="$set('showMobileMenu', true)" class="p-3 bg-zinc-900 border border-zinc-800 rounded-2xl text-zinc-400 hover:text-white transition cursor-pointer shadow-lg active:scale-95">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
            </div>
        </div>

        <!-- Mobile-Only Controls Section (Date Pickers & PDF, Alerts) -->
        <div class="flex flex-col gap-3 md:hidden">
            <div class="px-4 py-3 bg-zinc-900/50 border border-zinc-800 rounded-2xl flex flex-row items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex flex-col">
                        <span class="text-xs text-zinc-500 font-black uppercase tracking-widest">Du</span>
                        <input type="date" wire:model.live="reportStartDate" class="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-0.5 text-xs text-white font-mono outline-none focus:border-crm-yellow">
                    </div>
                    <div class="flex flex-col">
                        <span class="text-xs text-zinc-500 font-black uppercase tracking-widest">Au</span>
                        <input type="date" wire:model.live="reportEndDate" class="bg-zinc-950 border border-zinc-800 rounded-lg px-2 py-0.5 text-xs text-white font-mono outline-none focus:border-crm-yellow">
                    </div>
                </div>
                <a href="{{ route('report.daily', ['start_date' => $reportStartDate, 'end_date' => $reportEndDate]) }}" target="_blank" class="p-2.5 bg-crm-yellow text-black font-black rounded-xl transition cursor-pointer flex items-center gap-1.5 shadow-lg shadow-yellow-500/5">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231a1.125 1.125 0 0 1-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-14.326 0C3.768 7.44 3 8.375 3 9.456V15.75a2.25 2.25 0 0 0 2.25 2.25h1.091M9 10.125h6M9 13h4" />
                    </svg>
                    <span class="text-xs font-black uppercase whitespace-nowrap">Exporter</span>
                </a>
            </div>

            @if($this->deleted_transactions_count > 0)
                <div class="px-3 py-2 bg-red-950/40 border border-red-900/40 text-red-400 rounded-xl flex items-center justify-center gap-2 shadow-lg animate-pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <span class="text-xs font-black uppercase tracking-wider">Suppressions ce mois : {{ $this->deleted_transactions_count }}</span>
                </div>
            @endif
        </div>

        <!-- Title & Subtitle -->
        <div class="space-y-1">
            <h1 class="text-2xl md:text-3xl font-black tracking-tighter text-white uppercase font-sans leading-none">
                Vue Audit
            </h1>
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-crm-yellow animate-pulse"></div>
                <p class="text-xs md:text-sm text-zinc-500 uppercase tracking-[0.2em] font-bold">Rapport Global & Performance</p>
            </div>
        </div>
    </header>

    <!-- Financial Stats Grid (Comparative Cards) -->
    <section class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Aujourd'hui -->
        <div class="bg-zinc-950 border border-zinc-900 p-5 rounded-2xl space-y-4 hover:border-zinc-800 transition duration-200 shadow-xl">
            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm font-black text-zinc-500 uppercase tracking-widest">Aujourd'hui</span>
                <span class="w-2.5 h-2.5 rounded-full bg-blue-500 animate-pulse"></span>
            </div>
            <div class="space-y-1">
                <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase tracking-wider">Bilan Net</p>
                <p class="text-2xl md:text-3xl font-black font-mono tracking-tighter {{ $this->stats['net_today'] >= 0 ? 'text-green-500' : 'text-red-500' }}">
                    {{ $this->stats['net_today'] >= 0 ? '+' : '' }}{{ number_format($this->stats['net_today'], 0, ',', ' ') }} F
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4 border-t border-zinc-900 pt-3">
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Recettes</p>
                    <p class="text-sm md:text-base font-bold text-white font-mono">+{{ number_format($this->stats['income_today'], 0, ',', ' ') }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Dépenses</p>
                    <p class="text-sm md:text-base font-bold text-zinc-400 font-mono">-{{ number_format($this->stats['expense_today'], 0, ',', ' ') }}</p>
                </div>
            </div>
        </div>

        <!-- Cette Semaine -->
        <div class="bg-zinc-950 border border-zinc-900 p-5 rounded-2xl space-y-4 hover:border-zinc-800 transition duration-200 shadow-xl">
            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm font-black text-zinc-500 uppercase tracking-widest">Cette Semaine</span>
                <span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span>
            </div>
            <div class="space-y-1">
                <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase tracking-wider">Bilan Net</p>
                <p class="text-2xl md:text-3xl font-black font-mono tracking-tighter {{ $this->stats['net_week'] >= 0 ? 'text-green-500' : 'text-red-500' }}">
                    {{ $this->stats['net_week'] >= 0 ? '+' : '' }}{{ number_format($this->stats['net_week'], 0, ',', ' ') }} F
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4 border-t border-zinc-900 pt-3">
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Recettes</p>
                    <p class="text-sm md:text-base font-bold text-white font-mono">+{{ number_format($this->stats['income_week'], 0, ',', ' ') }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Dépenses</p>
                    <p class="text-sm md:text-base font-bold text-zinc-400 font-mono">-{{ number_format($this->stats['expense_week'], 0, ',', ' ') }}</p>
                </div>
            </div>
        </div>

        <!-- Ce Mois -->
        <div class="bg-zinc-950 border border-crm-yellow/20 p-5 rounded-2xl space-y-4 hover:border-zinc-850 transition duration-200 shadow-xl bg-crm-yellow/[0.01]">
            <div class="flex items-center justify-between">
                <span class="text-xs md:text-sm font-black text-crm-yellow uppercase tracking-widest font-bold">Ce Mois</span>
                <span class="px-2 py-0.5 bg-crm-yellow text-black rounded text-xs font-black uppercase tracking-widest">{{ now()->translatedFormat('F') }}</span>
            </div>
            <div class="space-y-1">
                <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase tracking-wider">Bilan Net</p>
                <p class="text-2xl md:text-3xl font-black text-crm-yellow font-mono tracking-tighter">
                    {{ $this->stats['net_month'] >= 0 ? '+' : '' }}{{ number_format($this->stats['net_month'], 0, ',', ' ') }} CDF
                </p>
            </div>
            <div class="grid grid-cols-2 gap-4 border-t border-zinc-900 pt-3">
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Recettes</p>
                    <p class="text-sm md:text-base font-bold text-white font-mono">+{{ number_format($this->stats['income_month'], 0, ',', ' ') }}</p>
                </div>
                <div>
                    <p class="text-xs text-zinc-600 font-black uppercase mb-0.5">Dépenses</p>
                    <p class="text-sm md:text-base font-bold text-zinc-400 font-mono">-{{ number_format($this->stats['expense_month'], 0, ',', ' ') }}</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Left & Center Columns (Real-time monitoring tables) -->
        <div class="lg:col-span-8 space-y-8">
            
            <!-- Employee Performance Grid (Real-time productivity) -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="p-6 border-b border-zinc-900">
                    <h2 class="text-sm md:text-base font-black text-white uppercase tracking-widest flex items-center gap-2">
                        <span class="w-1 md:w-1.5 h-3 md:h-4 bg-crm-yellow rounded-full"></span>
                        Performance des Agents (Aujourd'hui)
                    </h2>
                </div>
                <div class="px-2 md:px-6 py-4 overflow-x-auto scrollbar-hide">
                    <table class="w-full text-left text-sm md:text-base border-collapse">
                        <thead>
                            <tr class="text-zinc-500 font-black uppercase tracking-widest text-xs md:text-sm border-b border-zinc-900">
                                <th class="pb-3 pr-2">Agent</th>
                                <th class="pb-3 px-2">Rôle</th>
                                <th class="pb-3 px-2 text-right">Recette Terrasse</th>
                                <th class="pb-3 px-2 text-right">Recette Hôtel</th>
                                <th class="pb-3 px-2 text-center">Opérations</th>
                                <th class="pb-3 pl-2 text-right">Total Généré</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-900/50">
                            @forelse($this->user_stats as $u_stat)
                                <tr class="group hover:bg-zinc-900/30 transition-colors">
                                    <td class="py-4 pr-2 font-bold text-white uppercase tracking-tight">
                                        {{ $u_stat['name'] }}
                                    </td>
                                    <td class="py-4 px-2">
                                        <span class="text-xs md:text-sm font-bold px-1.5 py-0.5 rounded uppercase tracking-wider {{ $u_stat['role'] === 'admin' ? 'bg-crm-yellow/10 text-crm-yellow border border-crm-yellow/30' : ($u_stat['role'] === 'accountant' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/30' : 'bg-zinc-900 text-zinc-500 border border-zinc-800') }}">
                                            {{ $u_stat['role'] }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-2 text-right font-mono font-bold text-zinc-400">
                                        {{ number_format($u_stat['bar_revenue'], 0, ',', ' ') }} F
                                    </td>
                                    <td class="py-4 px-2 text-right font-mono font-bold text-zinc-400">
                                        {{ number_format($u_stat['hotel_revenue'], 0, ',', ' ') }} F
                                    </td>
                                    <td class="py-4 px-2 text-center font-mono font-bold text-zinc-500">
                                        {{ $u_stat['tx_count'] }}
                                    </td>
                                    <td class="py-4 pl-2 text-right font-mono font-black text-white whitespace-nowrap">
                                        {{ number_format($u_stat['total_revenue'], 0, ',', ' ') }} CDF
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-zinc-600 font-bold uppercase tracking-widest text-xs md:text-sm italic opacity-30">
                                        Aucune activité aujourd'hui pour les agents
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Real-time Financial Flows Feed -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="p-6 border-b border-zinc-900 flex justify-between items-center">
                    <h2 class="text-sm md:text-base font-black text-white uppercase tracking-widest flex items-center gap-2">
                        <span class="w-1 md:w-1.5 h-3 md:h-4 bg-green-500 rounded-full"></span>
                        Flux Financier (Dernières Opérations)
                    </h2>
                    <a href="{{ route('accounting') }}" class="text-sm font-black uppercase tracking-widest text-crm-yellow hover:underline flex items-center gap-1">
                        Tout voir
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
                        </svg>
                    </a>
                </div>
                <div class="px-2 md:px-6 py-4 overflow-x-auto scrollbar-hide">
                    <table class="w-full text-left text-sm md:text-base border-collapse">
                        <thead>
                            <tr class="text-zinc-500 font-black uppercase tracking-widest text-xs md:text-sm border-b border-zinc-900">
                                <th class="pb-3 pr-2">Date</th>
                                <th class="pb-3 px-2">Type</th>
                                <th class="pb-3 px-2">Catégorie</th>
                                <th class="pb-3 px-2">Description</th>
                                <th class="pb-3 px-2">Agent</th>
                                <th class="pb-3 pl-2 text-right">Montant</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-900/50">
                            @forelse($this->recent_transactions as $tx)
                                <tr class="group hover:bg-zinc-900/30 transition-colors">
                                    <td class="py-4 pr-2 font-bold text-zinc-500 whitespace-nowrap">
                                        {{ $tx->date->format('d/m/Y') }}
                                    </td>
                                    <td class="py-4 px-2">
                                        @if($tx->type === 'entree')
                                            <span class="px-1.5 py-0.5 bg-green-500/10 text-green-500 border border-green-500/20 rounded text-xs font-black uppercase tracking-wider">Recette</span>
                                        @else
                                            <span class="px-1.5 py-0.5 bg-red-500/10 text-red-500 border border-red-500/20 rounded text-xs font-black uppercase tracking-wider">Dépense</span>
                                        @endif
                                    </td>
                                    <td class="py-4 px-2">
                                        <span class="text-xs font-black text-zinc-400 uppercase">{{ $tx->category }}</span>
                                    </td>
                                    <td class="py-4 px-2 text-white font-medium">
                                        {{ $tx->description }}
                                    </td>
                                    <td class="py-4 px-2 text-zinc-500 uppercase font-bold text-xs">
                                        {{ $tx->user ? $tx->user->name : 'Système' }}
                                    </td>
                                    <td class="py-4 pl-2 text-right font-mono font-black {{ $tx->type === 'entree' ? 'text-green-500' : 'text-white' }} whitespace-nowrap">
                                        {{ number_format($tx->amount, 0, ',', ' ') }} F
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-zinc-600 font-bold uppercase tracking-widest text-xs md:text-sm italic opacity-30">
                                        Aucun flux enregistré récemment
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
            
            <!-- Hotel Occupancy Section -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="p-6 md:p-8 border-b border-zinc-900 flex items-center justify-between">
                    <div>
                        <h2 class="text-sm md:text-base font-black text-white uppercase tracking-widest flex items-center gap-2">
                            <span class="w-1 md:w-1.5 h-3 md:h-4 bg-crm-yellow rounded-full"></span>
                            État des Chambres
                        </h2>
                        <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase mt-1">Direct Live</p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl md:text-4xl font-black text-white font-mono leading-none tracking-tighter">{{ $this->stats['occupancy_rate'] }}%</p>
                        <p class="text-xs md:text-sm text-zinc-500 font-black uppercase tracking-widest mt-1">Occupation</p>
                    </div>
                </div>
                <div class="p-6 md:p-8 grid grid-cols-3 gap-4 md:gap-8">
                    <div class="space-y-2 md:space-y-4">
                        <div class="flex items-center justify-between text-xs md:text-sm font-black uppercase tracking-widest text-zinc-500">
                            <span>Total</span>
                            <span class="text-white">{{ $this->stats['total_rooms'] }}</span>
                        </div>
                        <div class="h-1.5 md:h-2 w-full bg-zinc-900 rounded-full overflow-hidden">
                            <div class="h-full bg-zinc-700 w-full"></div>
                        </div>
                    </div>
                    <div class="space-y-2 md:space-y-4">
                        <div class="flex items-center justify-between text-xs md:text-sm font-black uppercase tracking-widest text-green-500">
                            <span>Occ.</span>
                            <span>{{ $this->stats['occupied_rooms'] }}</span>
                        </div>
                        <div class="h-1.5 md:h-2 w-full bg-zinc-900 rounded-full overflow-hidden">
                            <div class="h-full bg-green-500" style="width: {{ $this->stats['occupancy_rate'] }}%"></div>
                        </div>
                    </div>
                    <div class="space-y-2 md:space-y-4">
                        <div class="flex items-center justify-between text-xs md:text-sm font-black uppercase tracking-widest text-orange-500">
                            <span>Net.</span>
                            <span>{{ $this->stats['dirty_rooms'] }}</span>
                        </div>
                        <div class="h-1.5 md:h-2 w-full bg-zinc-900 rounded-full overflow-hidden">
                            <div class="h-full bg-orange-500" style="width: {{ ($this->stats['dirty_rooms'] / max(1, $this->stats['total_rooms'])) * 100 }}%"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Upcoming Checkouts (Timeline) -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="p-6 md:p-8 border-b border-zinc-900">
                    <h2 class="text-sm md:text-base font-black text-white uppercase tracking-widest flex items-center gap-2">
                        <span class="w-1 md:w-1.5 h-3 md:h-4 bg-blue-500 rounded-full"></span>
                        Prochains Départs
                    </h2>
                </div>
                <div class="px-2 md:px-8 py-4 overflow-x-auto scrollbar-hide">
                    <table class="w-full text-left text-sm md:text-base border-collapse">
                        <thead>
                            <tr class="text-zinc-500 font-black uppercase tracking-widest text-xs md:text-sm border-b border-zinc-900">
                                <th class="pb-3 pr-2">Ch.</th>
                                <th class="pb-3 px-2">Client</th>
                                <th class="pb-3 px-2">Heure</th>
                                <th class="pb-3 pl-2 text-right">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-900/50">
                            @forelse($this->upcoming_checkouts as $checkout)
                                <tr class="group hover:bg-zinc-900/30 transition-colors">
                                    <td class="py-4 pr-4 font-black text-white uppercase tracking-tighter text-sm md:text-base">#{{ $checkout['room_number'] }}</td>
                                    <td class="py-4 px-4">
                                        <p class="text-zinc-300 font-bold text-sm md:text-base uppercase tracking-tight">{{ $checkout['guest_name'] }}</p>
                                        <p class="text-xs text-zinc-600 font-black uppercase mt-0.5 tracking-widest">{{ $checkout['stay_type'] === 'passage' ? 'Passage' : 'Nuitée' }}</p>
                                    </td>
                                    <td class="py-4 px-4 text-zinc-400 font-mono text-sm md:text-base">
                                        {{ $checkout['checkout_at']->format('H:i') }} <span class="text-xs text-zinc-600 uppercase ml-1">{{ $checkout['checkout_at']->format('d M') }}</span>
                                    </td>
                                    <td class="py-4 pl-4 text-right">
                                        <span class="px-2.5 py-1 rounded-lg text-xs md:text-sm font-black uppercase tracking-widest {{ $checkout['is_late'] ? 'bg-red-500/10 text-red-500 border border-red-500/20 animate-pulse' : 'bg-blue-500/10 text-blue-400 border border-blue-500/20' }}">
                                            {{ $checkout['remaining'] }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-12 text-center text-zinc-600 font-bold uppercase tracking-widest text-xs md:text-sm italic opacity-30">
                                        Aucun départ prévu prochainement
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- Right Column (Audit activities and warnings) -->
        <div class="lg:col-span-4 space-y-8">
            
            <!-- Journal d'Audit Avancé (Filtrable) -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl flex flex-col">
                <div class="p-5 border-b border-zinc-900 space-y-4">
                    <div>
                        <h2 class="text-sm md:text-base font-black text-white uppercase tracking-widest">Journal d'Audit</h2>
                        <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase mt-1">Actions administratives et opérationnelles</p>
                    </div>
                    
                    <!-- Filters Stack -->
                    <div class="space-y-2">
                        <!-- Search bar -->
                        <div class="relative group">
                            <input wire:model.live.debounce.300ms="activitySearch" type="text" placeholder="Rechercher une action..." 
                                class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-xl pl-8 pr-3 py-2 text-xs md:text-sm text-white placeholder-zinc-600 focus:outline-none transition font-medium">
                            <span class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-zinc-500 group-focus-within:text-crm-yellow">
                                <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </span>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-2">
                            <!-- Select Activity Type -->
                            <select wire:model.live="activityType" class="bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-xl px-2 py-1.5 text-xs md:text-sm font-bold uppercase tracking-widest text-zinc-400 focus:text-white transition outline-none cursor-pointer">
                                <option value="tous">Type: Tout</option>
                                <option value="chambre">Chambres</option>
                                <option value="stock">Terrasse/Stock</option>
                                <option value="finance">Comptabilité</option>
                                <option value="equipe">Gestion Équipe</option>
                            </select>

                            <!-- Select User Auteur -->
                            <select wire:model.live="activityUserId" class="bg-zinc-900 border border-zinc-800 focus:border-crm-yellow rounded-xl px-2 py-1.5 text-xs md:text-sm font-bold uppercase tracking-widest text-zinc-400 focus:text-white transition outline-none cursor-pointer">
                                <option value="tous">Auteur: Tous</option>
                                @foreach($this->users as $user)
                                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->role }})</option>
                                @endforeach
                            </select>
                        </div>
                        
                        @if($activitySearch || $activityType !== 'tous' || $activityUserId !== 'tous')
                            <button wire:click="resetActivityFilters" class="w-full py-1 text-xs md:text-sm font-black text-crm-yellow hover:text-white uppercase tracking-widest transition cursor-pointer">
                                Réinitialiser les filtres
                            </button>
                        @endif
                    </div>
                </div>
                
                <!-- Logs List -->
                <div class="flex-1 p-5 space-y-4 max-h-[450px] overflow-y-auto scrollbar-hide divide-y divide-zinc-900/50">
                    @forelse($this->recent_activities as $log)
                        @php
                            $isDeleteAction = str_contains(strtoupper($log->message), 'SUPPRESSION');
                            $isInventoryDiscrepancy = str_contains(strtoupper($log->message), "ÉCART D'INVENTAIRE");
                            
                            $borderClass = $isDeleteAction 
                                ? 'border-l-2 border-red-500 pl-3' 
                                : ($isInventoryDiscrepancy ? 'border-l-2 border-orange-500 pl-3' : '');
                        @endphp
                        <div class="pt-3 first:pt-0 flex gap-3 group {{ $borderClass }}">
                            <div class="flex-none mt-1">
                                @php
                                    $bulletColor = match($log->type) {
                                        'finance' => $isDeleteAction ? 'bg-red-500' : 'bg-green-500',
                                        'stock' => $isInventoryDiscrepancy ? 'bg-orange-500 animate-pulse' : 'bg-orange-500',
                                        'chambre' => 'bg-blue-500',
                                        'equipe' => 'bg-purple-500',
                                        default => 'bg-zinc-500'
                                    };
                                @endphp
                                <div class="w-2.5 h-2.5 rounded-full {{ $bulletColor }} shadow-sm"></div>
                            </div>
                            <div class="space-y-1 flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs md:text-sm font-black text-zinc-500 uppercase tracking-widest">{{ $log->type }}</span>
                                    <span class="text-xs md:text-sm text-zinc-600 font-bold uppercase tracking-tight">{{ $log->created_at->diffForHumans() }}</span>
                                </div>
                                
                                <p class="text-sm md:text-base leading-relaxed text-zinc-300 font-medium whitespace-normal break-words">
                                    {{ $log->message }}
                                </p>
                                
                                @if($log->user)
                                    <div class="flex items-center gap-1.5 mt-1">
                                        <span class="text-xs font-black px-1.5 py-0.5 rounded uppercase tracking-wider {{ $log->user->isAdmin() ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-zinc-900 text-zinc-500 border border-zinc-800' }}">
                                            Par: {{ $log->user->name }} ({{ $log->user->role }})
                                        </span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="py-12 text-center text-zinc-600 font-bold uppercase tracking-widest text-xs md:text-sm italic opacity-50">
                            Aucune action enregistrée
                        </div>
                    @endforelse
                </div>
                
                <!-- Load More Button -->
                @if(count($this->recent_activities) >= $activityLimit)
                    <div class="p-3 bg-zinc-900/40 border-t border-zinc-900 flex justify-center">
                        <button wire:click="loadMoreActivity" class="w-full py-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 hover:border-zinc-700 text-xs md:text-sm font-black text-zinc-400 hover:text-white text-center uppercase tracking-widest rounded-xl transition duration-150 cursor-pointer">
                            Voir plus d'activités
                        </button>
                    </div>
                @endif
            </section>

            <!-- Critical Stock -->
            <section class="bg-zinc-950 border border-zinc-900 rounded-2xl overflow-hidden shadow-2xl">
                <div class="p-6 md:p-8 border-b border-zinc-900 flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-black text-white uppercase tracking-widest">Alerte Stock</h2>
                        <p class="text-xs md:text-sm text-zinc-500 font-bold uppercase mt-1">Articles bientôt épuisés</p>
                    </div>
                    @if($this->stats['low_stock_count'] > 0)
                        <span class="px-2 py-1 bg-red-500 text-white rounded-lg text-xs md:text-sm font-black animate-pulse">
                            {{ $this->stats['low_stock_count'] }}
                        </span>
                    @endif
                </div>
                <div class="p-6 md:p-8 space-y-6">
                    @forelse($this->critical_stocks as $item)
                        <div class="flex items-center justify-between group">
                            <div class="space-y-1">
                                <p class="text-sm md:text-base font-black text-white uppercase tracking-tight group-hover:text-crm-yellow transition-colors">{{ $item->name }}</p>
                                <div class="flex items-center gap-2">
                                    <p class="text-xs md:text-sm text-zinc-600 font-bold uppercase tracking-widest">Seuil: 10</p>
                                    <span class="w-1.5 h-1.5 rounded-full bg-zinc-800"></span>
                                    <p class="text-xs md:text-sm text-green-500 font-black uppercase tracking-widest">Marge: {{ $item->margin_percentage }}%</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-base md:text-lg font-black font-mono {{ $item->stock <= 5 ? 'text-red-500' : 'text-orange-500' }}">{{ $item->stock }}</p>
                                <p class="text-xs md:text-sm text-zinc-600 font-black uppercase mt-0.5 tracking-tighter">Restants</p>
                            </div>
                        </div>
                    @empty
                        <p class="text-center py-4 text-zinc-600 font-bold uppercase tracking-widest text-xs md:text-sm italic opacity-50">Stock optimal</p>
                    @endforelse
                </div>
            </section>
        </div>
    </div>

    <!-- Mobile Navigation Sidebar (Overlay) -->
    @if($showMobileMenu)
        <div class="fixed inset-0 z-[100] lg:hidden animate-in fade-in duration-300">
            <!-- Glass Overlay -->
            <div wire:click="$set('showMobileMenu', false)" class="absolute inset-0 bg-black/60 backdrop-blur-md"></div>
            
            <!-- Sidebar Panel -->
            <div class="absolute right-0 top-0 bottom-0 w-[280px] bg-zinc-950 border-l border-zinc-800 shadow-2xl flex flex-col p-8 animate-in slide-in-from-right duration-500">
                <div class="flex items-center justify-between mb-12">
                    <img src="{{ asset('images/logo.png') }}" class="h-8 w-auto grayscale" alt="Logo">
                    <button wire:click="$set('showMobileMenu', false)" class="p-2 text-zinc-500 hover:text-white cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                    </button>
                </div>

                <nav class="flex-1 space-y-6">
                    <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-4 text-zinc-400 hover:text-white transition group">
                        <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center group-hover:bg-crm-yellow group-hover:text-black transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Opérations</span>
                    </a>

                    @can('access-accounting')
                    <a href="{{ route('accounting') }}" wire:navigate class="flex items-center gap-4 text-zinc-400 hover:text-white transition group">
                        <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center group-hover:bg-green-500 group-hover:text-black transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Trésorerie</span>
                    </a>
                    @endcan

                    <a href="{{ route('audit') }}" wire:navigate class="flex items-center gap-4 text-white transition group">
                        <div class="w-10 h-10 rounded-2xl bg-crm-yellow text-black border border-crm-yellow flex items-center justify-center transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 0 0 6 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0 1 18 16.5h-2.25m-7.5 0h7.5m-7.5 0V3m0 13.5V21m7.5-18v13.5m0 0V21m-12-4.5v4.5m16.5-4.5v4.5" />
                            </svg>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Vue Audit</span>
                    </a>

                    @can('access-admin')
                    <a href="{{ route('team') }}" wire:navigate class="flex items-center gap-4 text-zinc-400 hover:text-white transition group">
                        <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center group-hover:bg-orange-500 group-hover:text-black transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774a1.125 1.125 0 0 1 .12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.894.15c.542.09.94.56.94 1.109v1.094c0 .55-.398 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738a1.125 1.125 0 0 1-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527a1.125 1.125 0 0 1-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15a1.125 1.125 0 0 1-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.774-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Configuration</span>
                    </a>
                    @endcan
                </nav>

                <div class="mt-auto pt-8 border-t border-zinc-900">
                    <button wire:click="logout" class="flex items-center gap-4 text-zinc-500 hover:text-red-500 transition cursor-pointer w-full">
                        <div class="w-10 h-10 rounded-2xl bg-zinc-900 border border-zinc-800 flex items-center justify-center">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9V5.25A2.25 2.25 0 0 1 10.5 3h6a2.25 2.25 0 0 1 2.25 2.25v13.5A2.25 2.25 0 0 1 16.5 21h-6a2.25 2.25 0 0 1-2.25-2.25V15m-3 0-3-3m0 0 3-3m-3 3H15" /></svg>
                        </div>
                        <span class="text-xs font-black uppercase tracking-widest">Déconnexion</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
