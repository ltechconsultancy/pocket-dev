{{-- Sub-agents of this session: sheet with live activity, stop and status.
     The pill that opens it lives in the input bars (partials/chat/subagent-pill).
     Data: GET /api/sessions/{id}/subagents (polled). --}}

{{-- Sheet: bottom sheet on mobile, centered panel on desktop --}}
<template x-teleport="body">
    <div x-show="showSubagentSheet" x-cloak
         @keydown.escape.window="showSubagentSheet = false"
         class="fixed inset-0 flex items-end justify-center"
         :style="{ zIndex: 60, alignItems: windowWidth >= 768 ? 'center' : 'flex-end' }">
        <div class="absolute inset-0" style="background: rgba(0,0,0,.55);" @click="showSubagentSheet = false"></div>

        <div class="sa-sheet relative w-full bg-gray-800 overflow-hidden flex flex-col"
             :style="windowWidth >= 768
                ? 'border-width: 1px; border-style: solid; border-radius: 12px; max-height: 80vh; max-width: 512px;'
                : 'border-top-width: 1px; border-top-style: solid; border-radius: 16px 16px 0 0; max-height: 80vh;'"
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
                            <span class="sa-status flex items-center justify-center shrink-0" :class="'sa-status-' + item.status">
                                <template x-if="item.status === 'running' || item.status === 'pending'">
                                    <x-spinner class="!w-4 !h-4" />
                                </template>
                                <i x-show="item.status === 'completed'" class="fa-solid fa-check"></i>
                                <i x-show="item.status === 'failed'" class="fa-solid fa-triangle-exclamation"></i>
                                <i x-show="item.status === 'cancelled'" class="fa-solid fa-stop"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-gray-100 truncate" :title="item.title" x-text="item.title"></div>
                                <div class="text-xs text-gray-400 truncate" x-text="subagentMeta(item)"></div>
                            </div>

                            <div class="flex items-center gap-1 shrink-0">
                                <button type="button"
                                        @click="openSubagentViewer(item)"
                                        class="sa-iconbtn rounded bg-gray-700 hover:bg-gray-600 text-gray-200 cursor-pointer"
                                        title="Volledige geschiedenis bekijken">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <button type="button" x-show="item.status === 'running' || item.status === 'pending'"
                                        @click="cancelSubagent(item)"
                                        class="sa-iconbtn sa-stop rounded bg-gray-700 hover:bg-gray-600 cursor-pointer"
                                        title="Stoppen">
                                    <i class="fa-solid fa-stop"></i>
                                </button>
                            </div>
                        </div>

                        {{-- Current activity (always), recent steps (expanded) --}}
                        <template x-if="item.steps.length">
                            <div class="sa-log font-mono rounded-md mt-2 cursor-pointer"
                                 @click="subagentOpen[item.id] = !subagentOpen[item.id]"
                                 :title="subagentOpen[item.id] ? 'Minder tonen' : 'Laatste stappen tonen'">
                                <template x-for="(step, i) in (subagentOpen[item.id] ? item.steps : item.steps.slice(0, 1))" :key="i">
                                    <div class="sa-step truncate" :class="step.done ? 'sa-done-step' : 'sa-active'">
                                        <i class="fa-solid" :class="step.done ? 'fa-check' : 'fa-angle-right'"></i>
                                        <span x-text="step.label"></span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <div x-show="item.error" class="sa-error text-xs mt-2" x-text="item.error"></div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

@include('partials.chat.subagent-viewer')
