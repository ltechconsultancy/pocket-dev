@extends('layouts.config')

@section('title', 'OpenCode Go')

@section('content')
<div class="space-y-6 max-w-3xl" x-data="{
    status: @js($status),
    modelCount: null,
    checking: false,
    copied: false,
    async check() {
        this.checking = true;
        try {
            const res = await fetch('{{ route('opencode.auth.status') }}', { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            this.status = data;
            this.modelCount = data.model_count ?? null;
        } catch (e) {
            console.error(e);
        } finally {
            this.checking = false;
        }
    },
    copy(text) {
        navigator.clipboard?.writeText(text);
        this.copied = true;
        setTimeout(() => this.copied = false, 1500);
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
            <x-button variant="secondary" size="sm" @click="check()" x-bind:disabled="checking">
                <span x-show="!checking"><i class="fa-solid fa-rotate mr-1"></i>Opnieuw controleren</span>
                <span x-show="checking" x-cloak>Controleren…</span>
            </x-button>
        </div>
        <p class="text-sm">
            <span class="text-gray-500">CLI:</span>
            <span x-show="status.installed" class="text-green-400" x-text="status.version"></span>
            <span x-show="!status.installed" x-cloak class="text-red-400">niet geïnstalleerd</span>
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

    {{-- Install --}}
    <div x-show="!status.installed" x-cloak class="bg-gray-800 rounded-lg p-4 space-y-2">
        <h3 class="font-medium text-white">1. Installeren</h3>
        <p class="text-sm text-gray-400">Voer dit uit in een terminal in PocketDev (of vraag het de AI):</p>
        <div class="flex items-center gap-2">
            <code class="flex-1 bg-gray-900 rounded px-3 py-2 text-sm font-mono text-gray-200">npm install -g @opencode/cli</code>
            <x-button variant="secondary" size="sm" @click="copy('npm install -g @opencode/cli')"><i class="fa-regular fa-copy"></i></x-button>
        </div>
    </div>

    {{-- Login --}}
    <div class="bg-gray-800 rounded-lg p-4 space-y-3">
        <h3 class="font-medium text-white">Inloggen</h3>
        <p class="text-sm text-gray-400">Voer dit uit in een terminal in PocketDev, open de link die verschijnt, keur de code goed en klik daarna op <b>Opnieuw controleren</b>:</p>
        <div class="flex items-center gap-2">
            <code class="flex-1 bg-gray-900 rounded px-3 py-2 text-sm font-mono text-gray-200">opencode auth login opencode</code>
            <x-button variant="secondary" size="sm" @click="copy('opencode auth login opencode')"><i class="fa-regular fa-copy"></i></x-button>
        </div>
        <p x-show="copied" x-cloak class="text-xs text-green-400">Gekopieerd</p>
        <p class="text-xs text-gray-500">Alternatief: voeg een credential <span class="font-mono">OPENCODE_API_KEY</span> toe met een key uit de OpenCode-console.</p>
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
