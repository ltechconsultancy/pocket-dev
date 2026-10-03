{{-- Claude Code Remote Control toggle (continue this chat in claude.ai/code or the Claude app) --}}
{{-- Grey button; icon: grey = off · amber pulse = connecting · blue = paused (PocketDev is working) · green (+ green border) = on --}}
<button type="button"
        x-show="remoteControlAvailable"
        x-cloak
        @click="toggleRemoteControl()"
        :disabled="remoteControl.busy"
        {{-- Only classes present in the prebuilt Tailwind CSS (new ones need a Vite rebuild) --}}
        :class="remoteControl.connected
            ? 'text-green-400 border-green-500 hover:text-green-300'
            : (remoteControl.paused
                ? 'text-blue-400 border-transparent hover:text-blue-300'
                : (remoteControl.enabled
                    ? 'text-amber-400 border-transparent animate-pulse'
                    : 'text-gray-300 border-transparent hover:text-white'))"
        :title="remoteControlTitle"
        :aria-pressed="remoteControl.enabled ? 'true' : 'false'"
        class="{{ $sizeClass ?? 'w-12' }} bg-gray-700 hover:bg-gray-600 border rounded-lg text-xl md:text-base flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed shrink-0"
        style="touch-action: manipulation; padding-top: 9px; padding-bottom: 9px;" {{-- 9px + 1px border = same height as the mic button --}}>
    <i class="fa-solid fa-tower-broadcast"></i>
</button>
