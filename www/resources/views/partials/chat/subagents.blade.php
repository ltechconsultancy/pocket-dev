{{-- Sub-agents of this session: pill above the input while any run, tap for a sheet
     with live activity, stop and status. Data: GET /api/sessions/{id}/subagents (polled). --}}

{{-- Pill --}}
<button type="button"
        x-show="currentSession && (subagents.running > 0 || subagentFlash)" x-cloak
        x-transition.opacity
        @click="showSubagentSheet = true; refreshSubagents()"
        class="fixed flex items-center gap-2 rounded-full text-sm cursor-pointer"
        :style="{
            bottom: ((windowWidth >= 768 ? desktopInputHeight : mobileInputHeight) + 10) + 'px',
            left: windowWidth >= 768 ? '272px' : '12px',
            zIndex: 30,
            padding: '7px 12px',
            boxShadow: '0 4px 14px rgba(0,0,0,.45)',
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

{{-- Sheet: bottom sheet on mobile, centered panel on desktop --}}
<template x-teleport="body">
    <div x-show="showSubagentSheet" x-cloak
         @keydown.escape.window="showSubagentSheet = false"
         class="fixed inset-0 flex items-end justify-center"
         :style="{ zIndex: 60, alignItems: windowWidth >= 768 ? 'center' : 'flex-end' }">
        <div class="absolute inset-0" style="background: rgba(0,0,0,.55);" @click="showSubagentSheet = false"></div>

        <div class="relative w-full bg-gray-800 border-gray-600 overflow-hidden flex flex-col"
             :style="windowWidth >= 768
                ? 'border: 1px solid; border-color: #4b5563; border-radius: 12px; max-height: 80vh; max-width: 512px;'
                : 'border-top: 1px solid #4b5563; border-radius: 16px 16px 0 0; max-height: 80vh;'"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 translate-y-4"
             x-transition:enter-end="opacity-100 translate-y-0">
            <div class="md:hidden mx-auto rounded-full bg-gray-600" style="width: 40px; height: 4px; margin-top: 8px;"></div>
            <div class="flex items-center justify-between px-4 pt-3 pb-2">
                <b class="text-base text-gray-100">Sub-agents</b>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-gray-400"
                          x-text="subagents.running + ' actief · ' + (subagents.items.length - subagents.running) + ' klaar'"></span>
                    <button type="button" @click="showSubagentSheet = false" class="text-gray-400 hover:text-white p-1 cursor-pointer" title="Sluiten">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <div class="overflow-y-auto pb-3">
                <div x-show="subagents.items.length === 0" class="text-center text-sm text-gray-400 py-8">
                    Geen sub-agents in deze sessie (afgelopen 2 uur).
                </div>

                <template x-for="item in subagents.items" :key="item.id">
                    <div class="px-4 py-3 border-t border-gray-700">
                        <div class="flex items-center gap-3">
                            {{-- Status icon --}}
                            <span class="flex items-center justify-center shrink-0"
                                  style="width: 34px; height: 34px; border-radius: 10px;"
                                  :style="{ background: { running: '#4338ca', pending: '#4338ca', completed: '#065f46', failed: '#7f1d1d', cancelled: '#374151' }[item.status] }">
                                <template x-if="item.status === 'running' || item.status === 'pending'">
                                    <x-spinner class="!w-4 !h-4 text-white" />
                                </template>
                                <i x-show="item.status === 'completed'" class="fa-solid fa-check" style="color: #6ee7b7;"></i>
                                <i x-show="item.status === 'failed'" class="fa-solid fa-triangle-exclamation" style="color: #fca5a5;"></i>
                                <i x-show="item.status === 'cancelled'" class="fa-solid fa-stop text-gray-300"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-gray-100 truncate" :title="item.title" x-text="item.title"></div>
                                <div class="text-xs text-gray-400 truncate" x-text="subagentMeta(item)"></div>
                            </div>

                            <div class="flex items-center gap-1 shrink-0">
                                <button type="button" x-show="item.steps.length"
                                        @click="subagentOpen[item.id] = !subagentOpen[item.id]"
                                        class="rounded bg-gray-700 hover:bg-gray-600 text-gray-200 cursor-pointer"
                                        style="padding: 6px 9px; font-size: 12px;"
                                        :title="subagentOpen[item.id] ? 'Minder tonen' : 'Live meekijken'">
                                    <i class="fa-solid" :class="subagentOpen[item.id] ? 'fa-eye-slash' : 'fa-eye'"></i>
                                </button>
                                <button type="button" x-show="item.status === 'running' || item.status === 'pending'"
                                        @click="cancelSubagent(item)"
                                        class="rounded bg-gray-700 hover:bg-gray-600 cursor-pointer"
                                        style="padding: 6px 9px; font-size: 12px; color: #fca5a5;"
                                        title="Stoppen">
                                    <i class="fa-solid fa-stop"></i>
                                </button>
                            </div>
                        </div>

                        {{-- Current activity (always), recent steps (expanded) --}}
                        <template x-if="item.steps.length">
                            <div class="font-mono rounded-md mt-2"
                                 style="margin-left: 46px; background: #0b1220; padding: 6px 8px; font-size: 11.5px; line-height: 1.7;">
                                <template x-for="(step, i) in (subagentOpen[item.id] ? item.steps : item.steps.slice(0, 1))" :key="i">
                                    <div class="truncate" :style="{ color: step.done ? '#6b7280' : '#93c5fd' }">
                                        <i class="fa-solid" :class="step.done ? 'fa-check' : 'fa-angle-right'"
                                           :style="{ color: step.done ? '#10b981' : '#6b7280', width: '14px' }"></i>
                                        <span x-text="step.label"></span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="item.error" class="text-xs mt-2" style="margin-left: 46px; color: #fca5a5;" x-text="item.error"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
