{{-- Claude subscription usage: two nested rings (outer = week, inner = 5 hours), top right.
     Tap for percentages and reset times. Data: the existing GET /api/usage/claude-limits
     (same as Settings > Usage). Colors: public/themes/claude-usage.css. --}}
<div class="relative shrink-0 flex items-center" x-show="claudeUsage.five_hour || claudeUsage.seven_day || claudeAccounts.accounts.length > 0 || claudeAccounts.pending_login" x-cloak>
    {{-- One ring per saved Claude account (letter = e-mail domain). Active ring: tap for details.
         Dimmed ring: tap to switch to that account. --}}
    <template x-for="ring in claudeRingsToShow()" :key="ring.id">
        <button type="button"
                @click="ring.active ? (showClaudeUsage = !showClaudeUsage, showClaudeUsage && loadClaudeAccounts()) : switchClaudeAccount(ring)"
                class="relative flex items-center justify-center cursor-pointer"
                :style="'width: 34px; height: 36px;' + (ring.active ? '' : ' opacity: 0.45;')"
                :title="claudeRingTitle(ring)">
            <svg viewBox="0 0 36 36" width="30" height="30" style="transform: rotate(-90deg);">
                {{-- Outer ring: 7 days --}}
                <circle cx="18" cy="18" r="15" fill="none" stroke-width="3.5" class="cu-track"></circle>
                <circle cx="18" cy="18" r="15" fill="none" stroke-width="3.5" stroke-linecap="round"
                        :class="'cu-ring ' + claudeUsageLevel(ring.seven_day)"
                        :stroke-dasharray="(2 * Math.PI * 15).toFixed(2)"
                        :stroke-dashoffset="((2 * Math.PI * 15) * (1 - Math.min(100, ring.seven_day?.percent || 0) / 100)).toFixed(2)"></circle>
                {{-- Inner ring: 5 hours --}}
                <circle cx="18" cy="18" r="9" fill="none" stroke-width="3.5" class="cu-track"></circle>
                <circle cx="18" cy="18" r="9" fill="none" stroke-width="3.5" stroke-linecap="round"
                        :class="'cu-ring ' + claudeUsageLevel(ring.five_hour)"
                        :stroke-dasharray="(2 * Math.PI * 9).toFixed(2)"
                        :stroke-dashoffset="((2 * Math.PI * 9) * (1 - Math.min(100, ring.five_hour?.percent || 0) / 100)).toFixed(2)"></circle>
            </svg>
            <span x-show="ring.short" class="absolute text-gray-200 font-bold pointer-events-none"
                  style="font-size: 8px; line-height: 1; top: 50%; left: 50%; transform: translate(-50%, -50%);"
                  x-text="ring.short"></span>
            <span x-show="ring.active && claudeRings.length > 1" class="absolute rounded-full bg-emerald-400"
                  style="width: 4px; height: 4px; bottom: 1px; left: 50%; transform: translateX(-50%);"></span>
        </button>
    </template>

    <template x-teleport="body">
        <div x-show="showClaudeUsage" x-cloak
             @click.outside="showClaudeUsage = false"
             @keydown.escape.window="showClaudeUsage = false"
             class="cu-popover fixed bg-gray-800 border border-gray-600 rounded-lg shadow-lg text-sm"
             style="top: 58px; right: 8px; width: 250px; z-index: 100; padding: 12px 14px;">
            <div class="flex items-center justify-between mb-3">
                <b class="text-gray-100">Claude-verbruik</b>
                <button type="button" @click="showClaudeUsage = false" class="text-gray-400 hover:text-white cursor-pointer" title="Sluiten">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <template x-for="row in [
                    { key: 'five_hour', label: '5 uur (binnenste ring)' },
                    { key: 'seven_day', label: 'Week (buitenste ring)' }
                ]" :key="row.key">
                <div class="mb-3" x-show="claudeUsage[row.key]">
                    <div class="flex items-center justify-between mb-1">
                        <span class="text-gray-200 font-medium" x-text="row.label"></span>
                        <span class="cu-pct font-semibold" :class="claudeUsageLevel(claudeUsage[row.key])" x-text="Math.round(claudeUsage[row.key]?.percent || 0) + '%'"></span>
                    </div>
                    <div class="cu-bar-track rounded-full overflow-hidden" style="height: 6px;">
                        <div class="cu-bar rounded-full" style="height: 100%;"
                             :class="claudeUsageLevel(claudeUsage[row.key])"
                             :style="{ width: Math.min(100, claudeUsage[row.key]?.percent || 0) + '%' }"></div>
                    </div>
                    <div class="text-xs text-gray-400 mt-1" x-text="'Reset ' + formatUsageReset(claudeUsage[row.key]?.resets_at)"></div>
                </div>
            </template>
            <div x-show="!claudeUsage.five_hour && !claudeUsage.seven_day" class="text-xs text-gray-400 mb-3">
                Nog geen verbruik bekend voor dit account. Verschijnt na de volgende Claude-beurt.
            </div>

            {{-- Saved Claude Code accounts: switch, add, remove --}}
            <div class="border-t border-gray-700 pt-3 mb-3">
                <div class="text-xs text-gray-400 mb-2">Account</div>
                <template x-for="acc in claudeAccounts.accounts" :key="acc.id">
                    <div class="flex items-center gap-2 rounded-md px-2 py-1.5 mb-1"
                         :class="acc.active ? 'bg-gray-700' : 'hover:bg-gray-700 cursor-pointer'"
                         @click="switchClaudeAccount(acc)">
                        <i class="fa-solid" :class="acc.active ? 'fa-circle-check text-emerald-400' : 'fa-circle text-gray-500'" style="font-size: 12px;"></i>
                        <div class="flex-1 min-w-0">
                            <div class="text-gray-100 truncate" style="font-size: 13px;" x-text="acc.label"></div>
                            <div class="text-gray-400 truncate" style="font-size: 11px;"
                                 x-text="[acc.email !== acc.label ? acc.email : null, acc.subscription].filter(Boolean).join(' · ')"></div>
                        </div>
                        <button type="button" x-show="!acc.active" @click.stop="removeClaudeAccount(acc)"
                                class="text-gray-500 hover:text-red-400 cursor-pointer" title="Verwijderen"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </template>

                <div x-show="claudeAccounts.pending_login" class="text-xs text-amber-300 bg-gray-900 rounded-md p-2 mb-2">
                    Log nu in met het andere account:
                    @if(config('backup.user_id') !== null)
                        <div class="font-mono text-green-400 select-all mt-1" style="font-size: 10px; overflow-wrap: anywhere;">docker exec -it -u {{ config('backup.user_id') }} {{ config('pocketdev.project_name', 'pocket-dev') }}-queue claude</div>
                        <div class="mt-1 text-gray-400">en typ <code>/login</code>. Of upload de inlog op <a href="{{ url('/claude/auth') }}" class="text-blue-400">Claude-auth</a>.</div>
                    @else
                        <a href="{{ url('/claude/auth') }}" class="text-blue-400">Claude-auth</a>
                    @endif
                    <div class="flex gap-3 mt-2">
                        <button type="button" @click="loadClaudeAccounts()" class="text-blue-400 hover:text-blue-300 cursor-pointer">Klaar, vernieuwen</button>
                        <button type="button" @click="cancelAddClaudeAccount()" class="text-gray-400 hover:text-white cursor-pointer">Annuleren</button>
                    </div>
                </div>

                <button type="button" x-show="!claudeAccounts.pending_login" @click="addClaudeAccount()"
                        class="text-xs text-blue-400 hover:text-blue-300 cursor-pointer" :disabled="claudeAccounts.busy">
                    <i class="fa-solid fa-plus"></i> Account toevoegen
                </button>
                <div x-show="claudeAccounts.error" class="text-xs text-red-400 mt-1" x-text="claudeAccounts.error"></div>
            </div>

            <div class="flex items-center justify-between text-xs">
                <span class="text-gray-500" x-text="'Bijgewerkt ' + formatUsageAge(claudeUsage.fetched_at)"></span>
                <a href="{{ route('config.usage') }}" class="text-blue-400 hover:text-blue-300">Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
    </template>
</div>
