{{-- Sub-agent pill: first row of the chat input bar (in flow, so the messages area
     moves up with it and the pill never covers text). Opens partials/chat/subagents.
     Colors: public/themes/subagents.css (+ brutalism.css). --}}
<div x-show="currentSession && (subagents.running > 0 || subagentFlash)" x-cloak class="{{ $wrapperClass ?? '' }}">
<button type="button"
        @click="showSubagentSheet = true; refreshSubagents()"
        class="sa-pill flex items-center gap-2 rounded-full text-sm cursor-pointer"
        :class="subagents.running > 0 ? '' : 'sa-done'"
        title="Sub-agents van deze sessie">
    <template x-if="subagents.running > 0">
        <span class="flex items-center gap-2">
            <x-spinner class="!w-3 !h-3 sa-accent" />
            <i class="fa-solid fa-robot sa-accent"></i>
            <b x-text="subagents.running + (subagents.running === 1 ? ' sub-agent actief' : ' sub-agents actief')"></b>
            <span class="sa-accent" x-text="'· ' + formatSubagentDuration(longestRunningSeconds())"></span>
            <i class="fa-solid fa-chevron-up sa-chevron"></i>
        </span>
    </template>
    <template x-if="subagents.running === 0">
        <span class="flex items-center gap-2">
            <i class="fa-solid fa-check sa-accent"></i>
            <b>Sub-agent klaar</b>
        </span>
    </template>
</button>
</div>
