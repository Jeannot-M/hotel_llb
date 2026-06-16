<?php

use Livewire\Volt\Component;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;

new #[Layout('components.layouts.crm')] class extends Component {
    public array $teamUsers = [];
    public string $newUserName = '';
    public string $newUserPhone = '';
    public string $newUserCode = '';
    public string $newUserRole = 'receptionist';
    public bool $newCanAccessTerrasse = true;
    public bool $newCanAccessHebergement = true;

    public bool $showDeleteUserModal = false;
    public bool $showEditRoleModal = false;
    public ?int $selectedUserId = null;
    public string $selectedUserName = '';
    public string $selectedUserRole = '';
    public bool $selectedCanAccessTerrasse = true;
    public bool $selectedCanAccessHebergement = true;

    public function mount(): void
    {
        if (!auth()->user()?->isAdmin()) {
            abort(403, 'Accès non autorisé aux employés non-administrateurs.');
        }
        $this->loadTeam();
    }

    public function loadTeam(): void
    {
        $this->teamUsers = User::orderBy('role', 'asc')
            ->orderBy('name')
            ->get(['id', 'name', 'phone_number', 'is_admin', 'role', 'can_access_terrasse', 'can_access_hebergement'])
            ->toArray();
    }

    public function createUser(): void
    {
        $this->validate([
            'newUserName' => 'required|string|min:2',
            'newUserPhone' => 'required|string|min:9|max:9',
            'newUserCode' => 'required|string|min:5|max:5',
            'newUserRole' => 'required|in:receptionist,accountant,admin',
        ]);

        $phoneFormatted = '+243' . preg_replace('/[^0-9]/', '', $this->newUserPhone);

        if (User::where('phone_number', $phoneFormatted)->exists()) {
            $this->addError('newUserPhone', 'Ce numéro est déjà utilisé.');
            return;
        }

        User::create([
            'name' => $this->newUserName,
            'phone_number' => $phoneFormatted,
            'password' => Hash::make($this->newUserCode),
            'role' => $this->newUserRole,
            'is_admin' => $this->newUserRole === 'admin',
            'can_access_terrasse' => $this->newUserRole === 'admin' ? true : $this->newCanAccessTerrasse,
            'can_access_hebergement' => $this->newUserRole === 'admin' ? true : $this->newCanAccessHebergement,
        ]);

        ActivityLog::create([
            'type' => 'equipe',
            'message' => "Création de l'accès de type '{$this->newUserRole}' pour {$this->newUserName} ({$phoneFormatted}) par " . auth()->user()->name . ".",
        ]);

        $this->newUserName = '';
        $this->newUserPhone = '';
        $this->newUserCode = '';
        $this->newUserRole = 'receptionist';
        $this->newCanAccessTerrasse = true;
        $this->newCanAccessHebergement = true;

        $this->loadTeam();

        $this->dispatch('toast', message: 'Nouvel accès créé avec succès !');
    }

    public function openEditRoleModal(int $userId, string $name, string $role): void
    {
        $this->selectedUserId = $userId;
        $this->selectedUserName = $name;
        $this->selectedUserRole = $role;
        
        $user = User::findOrFail($userId);
        $this->selectedCanAccessTerrasse = $user->can_access_terrasse;
        $this->selectedCanAccessHebergement = $user->can_access_hebergement;
        
        $this->showEditRoleModal = true;
    }

    public function updateRole(): void
    {
        $this->validate([
            'selectedUserRole' => 'required|in:receptionist,accountant,admin',
        ]);

        if ($this->selectedUserId) {
            $user = User::findOrFail($this->selectedUserId);
            $oldRole = $user->role;
            
            $user->update([
                'role' => $this->selectedUserRole,
                'is_admin' => $this->selectedUserRole === 'admin',
                'can_access_terrasse' => $this->selectedUserRole === 'admin' ? true : $this->selectedCanAccessTerrasse,
                'can_access_hebergement' => $this->selectedUserRole === 'admin' ? true : $this->selectedCanAccessHebergement,
            ]);

            ActivityLog::create([
                'type' => 'equipe',
                'message' => "Modification du rôle de {$user->name} : {$oldRole} -> {$this->selectedUserRole} par " . auth()->user()->name . ".",
            ]);
        }

        $this->showEditRoleModal = false;
        $this->selectedUserId = null;
        $this->loadTeam();

        $this->dispatch('toast', message: 'Rôle mis à jour avec succès !');
    }

    public function confirmDeleteUser(int $userId, string $name): void
    {
        if (auth()->id() === $userId) {
            $this->addError('team', 'Vous ne pouvez pas supprimer votre propre compte.');
            return;
        }

        $this->selectedUserId = $userId;
        $this->selectedUserName = $name;
        $this->showDeleteUserModal = true;
    }

    public function deleteUser(): void
    {
        if ($this->selectedUserId && auth()->id() !== $this->selectedUserId) {
            $user = User::find($this->selectedUserId);
            
            if (!$user) {
                $this->showDeleteUserModal = false;
                $this->selectedUserId = null;
                $this->dispatch('toast', message: 'Utilisateur introuvable.');
                return;
            }

            $userName = $user->name;
            $userPhone = $user->phone_number;

            $user->delete();

            ActivityLog::create([
                'type' => 'equipe',
                'message' => "Suppression de l'accès de {$userName} ({$userPhone}) par " . auth()->user()->name . ".",
            ]);
        }

        $this->showDeleteUserModal = false;
        $this->selectedUserId = null;
        $this->loadTeam();

        $this->dispatch('toast', message: 'Accès supprimé.');
    }
};
?>

<div class="w-full py-4 md:py-8 space-y-8">
    <!-- Header Section -->
    <header class="flex flex-col md:flex-row md:items-center md:justify-between border-b border-zinc-800 pb-6 gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-white uppercase font-sans">Gestion de l'Équipe</h1>
            <p class="text-xs text-zinc-400 uppercase tracking-widest font-medium mt-1">Gérer les comptes d'accès employés et administrateurs</p>
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
            @can('access-admin')
                <a href="{{ route('team') }}" wire:navigate class="bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 px-4 py-2 rounded-xl text-xs font-bold text-white transition cursor-pointer flex items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4 text-orange-500">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774a1.125 1.125 0 0 1 .12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.894.15c.542.09.94.56.94 1.109v1.094c0 .55-.398 1.02-.94 1.11l-.894.149c-.424.07-.764.383-.929.78-.165.398-.143.854.107 1.204l.527.738a1.125 1.125 0 0 1-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527a1.125 1.125 0 0 1-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15a1.125 1.125 0 0 1-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.774-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                    Configuration
                </a>
            @endcan
            <span class="text-xs text-zinc-400 font-medium bg-zinc-900 border border-zinc-800 px-3 py-1.5 rounded-lg">
                {{ count($teamUsers) }} Accès Actifs
            </span>
        </div>
    </header>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- Left Side: Add Member Form -->
        <section class="lg:col-span-5 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6 shadow-xl">
            <div>
                <h2 class="text-base font-bold text-white uppercase">Créer un Nouvel Accès</h2>
                <p class="text-xs text-zinc-500 mt-1">Seuls les administrateurs peuvent créer de nouveaux comptes.</p>
            </div>

            <form wire:submit.prevent="createUser" class="space-y-4">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Nom Complet</label>
                    <input wire:model.defer="newUserName" type="text" placeholder="Ex: Jean Serveur" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition" required>
                    @error('newUserName') <span class="text-xs text-red-500 font-semibold">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Numéro de Téléphone</label>
                    <div class="flex items-center gap-2 bg-zinc-900 border border-zinc-800 focus-within:border-crm-yellow focus-within:ring-1 focus-within:ring-crm-yellow rounded-xl px-4 py-2.5 transition">
                        <span class="text-zinc-500 text-sm font-bold border-r border-zinc-700 pr-3">+243</span>
                        <input wire:model.defer="newUserPhone" type="text" inputmode="numeric" maxlength="9" placeholder="812 345 678" class="flex-1 bg-transparent border-none outline-none text-sm text-white placeholder:text-zinc-600 p-0 focus:ring-0">
                    </div>
                    @error('newUserPhone') <span class="text-xs text-red-500 font-semibold">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Code PIN (5 chiffres)</label>
                    <input wire:model.defer="newUserCode" type="password" inputmode="numeric" maxlength="5" placeholder="•••••" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition tracking-widest text-center" required>
                    @error('newUserCode') <span class="text-xs text-red-500 font-semibold">{{ $message }}</span> @enderror
                </div>

                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-zinc-400">Rôle & Permissions</label>
                    <select wire:model="newUserRole" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition cursor-pointer">
                        <option value="receptionist">Réceptionniste (Hôtel)</option>
                        <option value="accountant">Comptable (Finance)</option>
                        <option value="admin">Administrateur (Total)</option>
                    </select>
                </div>

                @if($newUserRole !== 'admin')
                    <div class="bg-zinc-900/40 border border-zinc-800/80 rounded-xl p-4 space-y-3">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-zinc-500">Autorisations de Zone</p>
                        
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input wire:model="newCanAccessHebergement" type="checkbox" class="mt-0.5 w-4 h-4 bg-zinc-900 border-zinc-800 rounded text-crm-yellow focus:ring-crm-yellow transition cursor-pointer">
                            <div class="text-xs">
                                <p class="text-white font-bold">Accès Hébergement (Chambres)</p>
                                <p class="text-[10px] text-zinc-500">Voir et gérer les réservations, arrivées, et check-outs.</p>
                            </div>
                        </label>
                        
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input wire:model="newCanAccessTerrasse" type="checkbox" class="mt-0.5 w-4 h-4 bg-zinc-900 border-zinc-800 rounded text-crm-yellow focus:ring-crm-yellow transition cursor-pointer">
                            <div class="text-xs">
                                <p class="text-white font-bold">Accès Terrasse (Bar / Stock)</p>
                                <p class="text-[10px] text-zinc-500">Voir et gérer les ventes de boissons et les mouvements de stocks.</p>
                            </div>
                        </label>
                    </div>
                @endif

                <button type="submit" class="w-full bg-crm-yellow hover:bg-crm-yellow-hover text-black py-3 rounded-xl font-bold text-xs transition duration-150 shadow-lg shadow-yellow-500/5 cursor-pointer uppercase">
                    Enregistrer le Compte
                </button>
            </form>
        </section>

        <!-- Right Side: Members List -->
        <section class="lg:col-span-7 bg-zinc-950 border border-zinc-900 rounded-2xl p-6 space-y-6 shadow-xl">
            <div>
                <h2 class="text-base font-bold text-white uppercase">Membres Actifs</h2>
                <p class="text-xs text-zinc-500 mt-1">Liste de tous les comptes ayant accès au CRM.</p>
            </div>

            @error('team')
                <div class="bg-red-500/10 border border-red-500/20 text-red-400 p-3.5 rounded-xl text-xs font-semibold">
                    {{ $message }}
                </div>
            @enderror

            <div class="divide-y divide-zinc-900">
                @foreach($teamUsers as $u)
                    <div wire:key="user-{{ $u['id'] }}" class="py-4 flex items-center justify-between gap-4 group">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center font-bold text-sm {{ $u['role'] === 'admin' ? 'bg-crm-yellow text-black' : ($u['role'] === 'accountant' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'bg-zinc-900 border border-zinc-800 text-zinc-300') }} shrink-0">
                                {{ strtoupper(substr($u['name'], 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-bold text-white truncate">{{ $u['name'] }}</p>
                                    <span class="text-[8px] font-bold px-1.5 py-0.5 rounded uppercase tracking-wider {{ $u['role'] === 'admin' ? 'bg-crm-yellow/10 text-crm-yellow border border-crm-yellow/30' : ($u['role'] === 'accountant' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/30' : 'bg-zinc-900 text-zinc-500 border border-zinc-800') }}">
                                        {{ $u['role'] }}
                                    </span>
                                </div>
                                <p class="text-xs text-zinc-500 mt-0.5">{{ $u['phone_number'] }}</p>
                                <div class="flex items-center gap-1.5 mt-1.5">
                                    <span class="text-[8px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider {{ $u['can_access_hebergement'] ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'bg-red-500/10 text-red-500 border border-red-500/20' }}">
                                        Hébergement: {{ $u['can_access_hebergement'] ? 'Oui' : 'Non' }}
                                    </span>
                                    <span class="text-[8px] px-1.5 py-0.5 rounded font-bold uppercase tracking-wider {{ $u['can_access_terrasse'] ? 'bg-amber-500/10 text-amber-500 border border-amber-500/20' : 'bg-red-500/10 text-red-500 border border-red-500/20' }}">
                                        Terrasse: {{ $u['can_access_terrasse'] ? 'Oui' : 'Non' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="shrink-0 flex items-center gap-1">
                            @if(auth()->id() !== $u['id'])
                                <button wire:click="openEditRoleModal({{ $u['id'] }}, '{{ $u['name'] }}', '{{ $u['role'] }}')" class="p-2 hover:bg-zinc-900 border border-transparent hover:border-zinc-800 text-zinc-500 hover:text-white rounded-xl transition cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4.5 h-4.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487l1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                    </svg>
                                </button>
                                <button wire:click="confirmDeleteUser({{ $u['id'] }}, '{{ $u['name'] }}')" class="p-2 hover:bg-zinc-900 border border-transparent hover:border-zinc-800 text-zinc-500 hover:text-red-500 rounded-xl transition cursor-pointer">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4.5 h-4.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                    </svg>
                                </button>
                            @else
                                <span class="px-3 py-1 bg-zinc-900 border border-zinc-800 text-zinc-500 text-[10px] font-bold rounded-lg uppercase">Vous</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    </div>

    <!-- MODALS -->
    @if($showEditRoleModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 space-y-4">
                    <h3 class="text-base font-bold text-white uppercase text-center">Modifier le Rôle</h3>
                    <p class="text-xs text-zinc-500 text-center">Changer les accès pour <span class="text-white font-bold">{{ $selectedUserName }}</span></p>
                    
                    <div class="space-y-4 pt-2">
                        <select wire:model="selectedUserRole" class="w-full bg-zinc-900 border border-zinc-800 focus:border-crm-yellow focus:ring-1 focus:ring-crm-yellow rounded-xl px-4 py-2.5 text-sm text-white outline-none transition">
                            <option value="receptionist">Réceptionniste</option>
                            <option value="accountant">Comptable</option>
                            <option value="admin">Administrateur</option>
                        </select>

                        @if($selectedUserRole !== 'admin')
                            <div class="bg-zinc-900 border border-zinc-800 rounded-xl p-3.5 space-y-3 text-left">
                                <p class="text-[9px] font-bold uppercase tracking-wider text-zinc-500">Autorisations de Zone</p>
                                
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input wire:model="selectedCanAccessHebergement" type="checkbox" class="mt-0.5 w-4 h-4 bg-zinc-900 border-zinc-800 rounded text-crm-yellow focus:ring-crm-yellow transition cursor-pointer">
                                    <div class="text-[11px]">
                                        <p class="text-white font-bold">Hébergement (Chambres)</p>
                                    </div>
                                </label>
                                
                                <label class="flex items-start gap-2.5 cursor-pointer">
                                    <input wire:model="selectedCanAccessTerrasse" type="checkbox" class="mt-0.5 w-4 h-4 bg-zinc-900 border-zinc-800 rounded text-crm-yellow focus:ring-crm-yellow transition cursor-pointer">
                                    <div class="text-[11px]">
                                        <p class="text-white font-bold">Terrasse (Bar / Stock)</p>
                                    </div>
                                </label>
                            </div>
                        @endif

                        <div class="flex items-center gap-3">
                            <button wire:click="$set('showEditRoleModal', false)" class="flex-1 bg-zinc-900 text-zinc-400 py-2.5 rounded-xl font-bold text-xs border border-zinc-800 cursor-pointer">Annuler</button>
                            <button wire:click="updateRole" class="flex-1 bg-crm-yellow text-black py-2.5 rounded-xl font-bold text-xs cursor-pointer">Enregistrer</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if($showDeleteUserModal)
        <div class="fixed inset-0 z-50 bg-black/85 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-zinc-950 border border-zinc-800 rounded-2xl max-w-sm w-full overflow-hidden shadow-2xl">
                <div class="p-6 text-center space-y-4">
                    <div class="w-14 h-14 bg-red-500/10 border border-red-500/20 rounded-2xl mx-auto flex items-center justify-center text-red-500">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-7 h-7"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                    </div>
                    <h3 class="text-base font-bold text-white uppercase">Supprimer l'Accès</h3>
                    <p class="text-sm text-zinc-400">Voulez-vous vraiment retirer l'accès de <span class="font-bold text-white">{{ $selectedUserName }}</span> ?</p>
                    <div class="flex items-center gap-3 pt-2">
                        <button wire:click="$set('showDeleteUserModal', false)" class="flex-1 bg-zinc-900 text-zinc-400 py-2.5 rounded-xl font-bold text-xs border border-zinc-800 cursor-pointer">Annuler</button>
                        <button wire:click="deleteUser" class="flex-1 bg-red-600 text-white py-2.5 rounded-xl font-bold text-xs cursor-pointer">Supprimer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
