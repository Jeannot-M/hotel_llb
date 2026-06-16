<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string')]
    public string $phone_number = '';

    #[Validate('required|string|min:5|max:5')]
    public string $password = '';

    public bool $remember = true;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (!Auth::attempt(['phone_number' => $this->phone_number, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'phone_number' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (!RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'phone_number' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->phone_number) . '|' . request()->ip());
    }
}; ?>

<div class="flex flex-col gap-6 w-full max-w-md mx-auto" style="font-family: 'Outfit', sans-serif;" x-data="{
        step: 1,
        raw_phone: '',
        code: ['', '', '', '', ''],
        get fullCode() { return this.code.join(''); },
        init() {
            this.$watch('raw_phone', value => {
                // Keep only digits
                let numbers = value.replace(/[^0-9]/g, '').slice(0, 9);
                
                // Format as 000-000-000
                let formatted = numbers;
                if (numbers.length > 3 && numbers.length <= 6) {
                    formatted = numbers.slice(0, 3) + '-' + numbers.slice(3);
                } else if (numbers.length > 6) {
                    formatted = numbers.slice(0, 3) + '-' + numbers.slice(3, 6) + '-' + numbers.slice(6);
                }

                // Update input value with formatting
                this.raw_phone = formatted;
                
                // Keep livewire variable unformatted (just numbers)
                $wire.phone_number = '+243' + numbers;
            });
        },
        nextStep() {
            let numbers = this.raw_phone.replace(/[^0-9]/g, '');
            if (numbers.length === 9) {
                this.step = 2;
                setTimeout(() => { document.getElementById('code0')?.focus(); }, 150);
            }
        },
        prevStep() {
            this.step = 1;
        },
        handleInput(index, event) {
            let value = event.target.value.replace(/[^0-9]/g, '');
            if (value.length > 1) {
                // handle paste
                let pasted = value.split('').slice(0, 5);
                for(let i=0; i<pasted.length; i++) {
                    this.code[i] = pasted[i];
                }
                $wire.set('password', this.fullCode);
                let nextFocus = Math.min(pasted.length, 4);
                setTimeout(() => { document.getElementById('code' + nextFocus)?.focus(); }, 10);
            } else {
                this.code[index] = value;
                $wire.set('password', this.fullCode);
                if (value !== '' && index < 4) {
                    setTimeout(() => { document.getElementById('code' + (index + 1))?.focus(); }, 10);
                }
            }
        },
        handleKeydown(index, event) {
            if (event.key === 'Backspace') {
                if (this.code[index] === '' && index > 0) {
                    setTimeout(() => { document.getElementById('code' + (index - 1))?.focus(); }, 10);
                } else {
                    this.code[index] = '';
                    $wire.set('password', this.fullCode);
                }
            }
            if (event.key === 'Enter' && this.fullCode.length === 5) {
                $wire.login();
            }
        }
    }">

    <div class="text-center mb-6 md:mb-8">
        <img src="{{ asset('images/logo.png') }}" alt="Logo LULUABOURG"
            class="w-28 h-28 md:w-32 md:h-32 mx-auto mb-4 md:mb-5 object-contain drop-shadow-2xl">
        <p class="text-zinc-400 text-sm md:text-base font-bold tracking-tight px-4" x-show="step === 1" x-transition>Saisissez votre numéro de téléphone</p>
        <p class="text-zinc-400 text-sm md:text-base font-bold tracking-tight px-4" x-show="step === 2" x-transition style="display: none;">Saisissez votre code PIN à 5 chiffres</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6" @submit.prevent autocomplete="off">

        <!-- STEP 1: Phone Number -->
        <div x-show="step === 1" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 -translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
            class="flex flex-col gap-4">
            <div>
               
                <div
                    class="flex items-center gap-3 w-full bg-zinc-900/50 border border-zinc-800 rounded-xl px-4 py-3 focus-within:ring-2 focus-within:ring-white focus-within:border-transparent transition-all">
                    <span class="text-zinc-400 font-bold border-r border-zinc-700 pr-3">+243</span>
                    <input x-model="raw_phone" @keydown.enter.prevent="nextStep" type="text" inputmode="numeric"
                        required autofocus autocomplete="new-password" placeholder="000-000-000" maxlength="11"
                        class="bg-transparent border-none outline-none w-full text-white placeholder:text-zinc-600 p-0 focus:ring-0" />
                </div>
                @error('phone_number') <span class="text-red-500 text-xs mt-1">{{ $message }}</span> @enderror
            </div>

            <button type="button" @click="nextStep" :disabled="raw_phone.replace(/[^0-9]/g, '').length !== 9"
                class="w-full bg-white hover:bg-zinc-200 text-black font-bold py-3.5 rounded-xl transition-all active:scale-[0.98] mt-2 shadow-lg shadow-white/10 disabled:opacity-50 disabled:cursor-not-allowed">
                Suivant
            </button>
        </div>

        <!-- STEP 2: 5-Digit Code -->
        <div x-show="step === 2" style="display: none;" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
            class="flex flex-col gap-6">

            <div>
                <div class="flex justify-center gap-2 md:gap-3">
                    <template x-for="(digit, index) in code" :key="index">
                        <input type="password" x-model="code[index]" :id="'code' + index"
                            @input="handleInput(index, $event)" @keydown="handleKeydown(index, $event)" maxlength="1"
                            inputmode="numeric" autocomplete="off" data-lpignore="true" data-1p-ignore
                            class="w-12 h-14 md:w-16 md:h-20 bg-zinc-900/80 border border-zinc-700/50 text-white text-center text-2xl md:text-3xl font-black rounded-2xl focus:outline-none focus:ring-2 focus:ring-crm-yellow focus:border-transparent transition-all shadow-inner" />
                    </template>
                </div>
                <input type="hidden" wire:model="password">
                @error('password') <span class="text-red-500 text-[10px] mt-3 block text-center uppercase font-bold tracking-tighter">{{ $message }}</span>
                @enderror
            </div>

            <button type="button" wire:click="login" :disabled="fullCode.length !== 5"
                class="w-full bg-white hover:bg-zinc-200 text-black font-bold py-3.5 rounded-xl transition-all active:scale-[0.98] mt-2 shadow-lg shadow-white/10 disabled:opacity-50 disabled:cursor-not-allowed">
                Se connecter
            </button>

            <div class="flex justify-center mt-2">
                <button type="button" @click="prevStep"
                    class="flex items-center gap-2 text-xs md:text-sm text-zinc-500 hover:text-white transition-colors font-bold uppercase tracking-widest">
                    <svg class="w-3.5 h-3.5 md:w-4 md:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    <span>Changer de numéro</span>
                </button>
            </div>
        </div>
    </form>

    <!-- Footer Note -->
    <div class="mt-8 pt-6 border-t border-white/[0.03] text-center">
        <p class="text-[10px] md:text-xs text-zinc-500 font-medium leading-relaxed max-w-[280px] mx-auto">
            En cas de soucis de connexion, veuillez informer l'administrateur du système.
        </p>
    </div>
</div>