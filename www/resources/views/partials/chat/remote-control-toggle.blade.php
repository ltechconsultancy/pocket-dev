{{-- Claude Code Remote Control toggle (continue this chat in claude.ai/code or the Claude app) --}}
{{-- Off: grey · starting: amber pulse · connected: orange --}}
<button type="button"
        x-show="remoteControlAvailable"
        x-cloak
        @click="toggleRemoteControl()"
        :disabled="remoteControl.busy"
        :class="remoteControl.connected
            ? 'bg-orange-500/90 text-white hover:bg-orange-400'
            : (remoteControl.enabled
                ? 'bg-amber-500/80 text-white animate-pulse'
                : 'bg-gray-700 text-gray-300 hover:bg-gray-600 hover:text-white')"
        :title="remoteControlTitle"
        :aria-pressed="remoteControl.enabled ? 'true' : 'false'"
        class="{{ $sizeClass ?? 'w-12' }} py-[10px] rounded-lg text-xl md:text-base flex items-center justify-center transition-colors cursor-pointer disabled:cursor-wait shrink-0"
        style="touch-action: manipulation;">
    <i class="fa-solid fa-tower-broadcast"></i>
</button>
