{{-- Sub-agent pill: first row of the chat input bar (in flow, so the messages area
     moves up with it and the pill never covers text). Opens partials/chat/subagents. --}}
<div x-show="currentSession && (subagents.running > 0 || subagentFlash)" x-cloak class="{{ $wrapperClass ?? '' }}">
<button type="button"
        @click="showSubagentSheet = true; refreshSubagents()"
        class="flex items-center gap-2 rounded-full text-sm cursor-pointer"
        :style="{
            padding: '6px 12px',
            background: subagents.running > 0 ? '#1e1b4b' : '#022c22',
            border: '1px solid ' + (subagents.running > 0 ? '#4338ca' : '#065f46'),
            color: subagents.running > 0 ? '#e0e7ff' : '#d1fae5'
        }"
        title="Sub-agents van deze sessie">
    <template x-if="subagents.running > 0">
        <span class="flex items-center gap-2">
            <x-spinner class="!w-3 !h-3" style="color: #a5b4fc;" />
            <i class="fa-solid fa-robot" style="color: #a5b4fc;"></i>
            <b x-text="subagents.running + (subagents.running === 1 ? ' sub-agent actief' : ' sub-agents actief')"></b>
            <span style="color: #a5b4fc;" x-text="'· ' + formatSubagentDuration(longestRunningSeconds())"></span>
            <i class="fa-solid fa-chevron-up" style="color: #818cf8; font-size: 11px;"></i>
        </span>
    </template>
    <template x-if="subagents.running === 0">
        <span class="flex items-center gap-2">
            <i class="fa-solid fa-check" style="color: #6ee7b7;"></i>
            <b>Sub-agent klaar</b>
        </span>
    </template>
</button>
</div>
