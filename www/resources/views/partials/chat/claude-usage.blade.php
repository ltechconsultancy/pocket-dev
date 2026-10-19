{{-- Claude subscription usage: two nested rings (outer = week, inner = 5 hours), top right.
     Tap for percentages and reset times. Data: the existing GET /api/usage/claude-limits
     (same as Settings > Usage). Colors: public/themes/claude-usage.css. --}}
<div class="relative shrink-0" x-show="claudeUsage.five_hour || claudeUsage.seven_day" x-cloak>
    <button type="button" @click="showClaudeUsage = !showClaudeUsage"
            class="flex items-center justify-center cursor-pointer"
            style="width: 36px; height: 36px;"
            :title="claudeUsageTitle()">
        <svg viewBox="0 0 36 36" width="30" height="30" style="transform: rotate(-90deg);">
            {{-- Outer ring: 7 days --}}
            <circle cx="18" cy="18" r="15" fill="none" stroke-width="3.5" class="cu-track"></circle>
            <circle cx="18" cy="18" r="15" fill="none" stroke-width="3.5" stroke-linecap="round"
                    :class="'cu-ring ' + claudeUsageLevel(claudeUsage.seven_day)"
                    :stroke-dasharray="(2 * Math.PI * 15).toFixed(2)"
                    :stroke-dashoffset="((2 * Math.PI * 15) * (1 - Math.min(100, claudeUsage.seven_day?.percent || 0) / 100)).toFixed(2)"></circle>
            {{-- Inner ring: 5 hours --}}
            <circle cx="18" cy="18" r="9" fill="none" stroke-width="3.5" class="cu-track"></circle>
            <circle cx="18" cy="18" r="9" fill="none" stroke-width="3.5" stroke-linecap="round"
                    :class="'cu-ring ' + claudeUsageLevel(claudeUsage.five_hour)"
                    :stroke-dasharray="(2 * Math.PI * 9).toFixed(2)"
                    :stroke-dashoffset="((2 * Math.PI * 9) * (1 - Math.min(100, claudeUsage.five_hour?.percent || 0) / 100)).toFixed(2)"></circle>
        </svg>
    </button>

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
            <div class="flex items-center justify-between text-xs">
                <span class="text-gray-500" x-text="'Bijgewerkt ' + formatUsageAge(claudeUsage.fetched_at)"></span>
                <a href="{{ route('config.usage') }}" class="text-blue-400 hover:text-blue-300">Details <i class="fa-solid fa-arrow-right"></i></a>
            </div>
        </div>
    </template>
</div>
