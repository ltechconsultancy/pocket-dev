@extends('layouts.config')

@section('title', 'OpenCode Go')

@section('content')
<div class="space-y-6 max-w-3xl" x-data="{
    status: @js($status),
    modelCount: null,
    checking: false,
    login: { status: 'none', verification_url: null, user_code: null, error: null },
    pollTimer: null,
    copied: false,

    csrf() { return document.querySelector('meta[name=csrf-token]')?.content || ''; },

    async check() {
        this.checking = true;
        try {
            const res = await fetch('{{ route('opencode.auth.status') }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            this.status = data;
            this.modelCount = data.model_count ?? null;
        } catch (e) { console.error(e); }
        finally { this.checking = false; }
    },

    async startLogin() {
        this.login = { status: 'starting', verification_url: null, user_code: null, error: null };
        try {
            const res = await fetch('{{ route('opencode.auth.login') }}', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() },
            });
            this.login = Object.assign(this.login, await res.json());
        } catch (e) {
            this.login = { status: 'failed', error: 'Kon de login niet starten.' };
            return;
        }
        this.poll();
    },

    poll() {
        clearTimeout(this.pollTimer);
        this.pollTimer = setTimeout(async () => {
            try {
                const res = await fetch('{{ route('opencode.auth.loginStatus') }}', { headers: { 'Accept': 'application/json' } });
                const data = await res.json();
                if (data.status === 'none' && this.login.status === 'starting') {
                    return this.poll(); // job not picked up yet
                }
                this.login = data;
            } catch (e) { /* network hiccup: keep polling */ }

            if (this.login.status === 'authenticated') {
                await this.check();
                return;
            }
            if (['starting', 'ready'].includes(this.login.status)) {
                this.poll();
            }
        }, 2000);
    },

    async cancelLogin() {
        clearTimeout(this.pollTimer);
        await fetch('{{ route('opencode.auth.cancel') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() } });
        this.login = { status: 'none' };
    },

    async logout() {
        if (!confirm('Uitloggen bij OpenCode?')) return;
        this.checking = true;
        try {
            const res = await fetch('{{ route('opencode.auth.logout') }}', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.csrf() } });
            this.status = await res.json();
            this.login = { status: 'none' };
        } finally { this.checking = false; }
    },

    copy(text) {
        navigator.clipboard?.writeText(text);
        this.copied = true;
        setTimeout(() => this.copied = false, 1500);
    },

    init() {
        // Resume a login that is still running (e.g. after a page reload)
        fetch('{{ route('opencode.auth.loginStatus') }}', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => { if (['starting', 'ready'].includes(data.status)) { this.login = data; this.poll(); } })
            .catch(() => {});
    }
}">
    <div>
        <a href="{{ route('config.credentials') }}" class="text-sm text-gray-400 hover:text-white"><i class="fa-solid fa-arrow-left mr-1"></i>Providers</a>
        <h2 class="text-lg font-semibold mt-2 text-gray-200">OpenCode Go (CLI)</h2>
        <p class="text-sm text-gray-400">PocketDev draait de <span class="font-mono">opencode</span> CLI met je Go-abonnement. Alle Go-modellen werken, ook Qwen, MiniMax en Gemini.</p>
    </div>

    {{-- Status --}}
    <div class="bg-gray-800 rounded-lg p-4 space-y-2">
        <div class="flex items-center justify-between gap-3">
            <h3 class="font-medium text-white">Status</h3>
            <div class="flex items-center gap-2">
                <x-button variant="secondary" size="sm" @click="check()" x-bind:disabled="checking">
                    <span x-show="!checking"><i class="fa-solid fa-rotate mr-1"></i>Vernieuwen</span>
                    <span x-show="checking" x-cloak>Bezig…</span>
                </x-button>
                <x-button x-show="status.logged_in" x-cloak variant="ghost" size="sm" class="text-red-400 hover:text-red-300" @click="logout()">Uitloggen</x-button>
            </div>
        </div>
        <p class="text-sm">
            <span class="text-gray-500">CLI:</span>
            <span x-show="status.installed" class="text-green-400" x-text="status.version"></span>
            <span x-show="!status.installed" x-cloak class="text-red-400">niet geïnstalleerd (vraag de AI: <span class="font-mono">npm install -g @opencode/cli</span>)</span>
        </p>
        <p class="text-sm">
            <span class="text-gray-500">Login:</span>
            <span x-show="status.logged_in" class="text-green-400">ingelogd</span>
            <span x-show="!status.logged_in" x-cloak class="text-amber-400">nog niet ingelogd</span>
        </p>
        <template x-for="account in status.accounts" :key="account">
            <p class="text-sm font-mono text-gray-300" x-text="account"></p>
        </template>
        <p x-show="modelCount !== null" x-cloak class="text-sm text-gray-400" x-text="modelCount + ' Go-modellen beschikbaar'"></p>
    </div>

    {{-- Login --}}
    <div x-show="status.installed && !status.logged_in" x-cloak class="bg-gray-800 rounded-lg p-4 space-y-3">
        <h3 class="font-medium text-white">Inloggen</h3>

        <div x-show="['none', 'expired', 'failed', 'cancelled'].includes(login.status)" class="space-y-2">
            <p class="text-sm text-gray-400">Je krijgt een link en een code. Open de link, log in bij OpenCode en keur de code goed.</p>
            <p x-show="login.status === 'expired'" class="text-sm text-amber-400">De vorige code is verlopen. Probeer het opnieuw.</p>
            <p x-show="login.status === 'failed'" class="text-sm text-red-400" x-text="login.error"></p>
            <x-button variant="primary" @click="startLogin()"><i class="fa-solid fa-right-to-bracket mr-1"></i>Inloggen met OpenCode</x-button>
        </div>

        <div x-show="login.status === 'starting'" class="text-sm text-gray-400 flex items-center gap-2">
            <x-spinner class="!w-4 !h-4" /> Inlogcode ophalen…
        </div>

        <div x-show="login.status === 'ready'" class="space-y-3">
            <p class="text-sm text-gray-300">1. Open deze link en log in bij OpenCode:</p>
            <a :href="login.verification_url" target="_blank" rel="noopener" class="inline-block px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">
                <i class="fa-solid fa-arrow-up-right-from-square mr-1"></i>OpenCode openen
            </a>
            <p class="text-sm text-gray-300">2. Controleer dat deze code er staat en keur hem goed:</p>
            <div class="flex items-center gap-2">
                <code class="bg-gray-900 rounded px-3 py-2 text-lg font-mono tracking-widest text-white" x-text="login.user_code"></code>
                <x-button variant="secondary" size="sm" @click="copy(login.user_code)"><i class="fa-regular fa-copy"></i></x-button>
                <span x-show="copied" x-cloak class="text-xs text-green-400">Gekopieerd</span>
            </div>
            <p class="text-sm text-gray-400 flex items-center gap-2">
                <x-spinner class="!w-3 !h-3" /> Wachten op goedkeuring… <span class="text-gray-500" x-text="login.expires_in ? '(nog ' + Math.ceil(login.expires_in / 60) + ' min)' : ''"></span>
            </p>
            <x-button variant="ghost" size="sm" @click="cancelLogin()">Annuleren</x-button>
        </div>

        <p x-show="login.status === 'authenticated'" class="text-sm text-green-400"><i class="fa-solid fa-check mr-1"></i>Ingelogd.</p>
    </div>

    {{-- Contributor warning --}}
    <div class="pd-contributor-warning rounded-lg p-3 text-sm">
        <div class="flex items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation text-lg mt-0.5"></i>
            <div>
                <b class="block mb-1">Contributor-modellen trainen op je data</b>
                <span>Modellen met "Contributor" in de naam (bijv. Gemini 4 Argon Contributor, Muse Spark Contributor) zijn goedkoop omdat Google of Meta je prompts en antwoorden mag gebruiken voor training. PocketDev markeert ze rood in de modelkeuze, de agentlijst en de chat. Gebruik ze nooit voor klantcode, wachtwoorden of vertrouwelijk werk.</span>
            </div>
        </div>
    </div>

    {{-- Next step --}}
    <div class="bg-gray-800 rounded-lg p-4 space-y-2">
        <h3 class="font-medium text-white">Daarna</h3>
        <p class="text-sm text-gray-400">Maak een agent met provider <b>OpenCode Go</b> en kies een model, bijv. <span class="font-mono">kimi-k2.7-code</span> voor programmeerwerk of <span class="font-mono">qwen3.8-max</span> voor lastige problemen.</p>
        <a href="{{ route('config.agents') }}" class="inline-block px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white text-sm rounded">Naar Agents</a>
    </div>
</div>
@endsection
