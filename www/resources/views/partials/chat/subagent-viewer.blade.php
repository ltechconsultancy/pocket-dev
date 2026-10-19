{{-- Sub-agent viewer: the full history of one sub-agent (prompt, thinking, text,
     tool calls with input + result), live while it runs. Data: GET /api/subagents/{task}/events.
     Opened from the sub-agents sheet (partials/chat/subagents). --}}
<template x-teleport="body">
    <div x-show="subagentViewer.open" x-cloak
         @keydown.escape.window="if (subagentViewer.open) closeSubagentViewer()"
         class="fixed inset-0 flex items-center justify-center"
         style="z-index: 70;">
        <div class="absolute inset-0" style="background: rgba(0,0,0,.6);" @click="closeSubagentViewer()"></div>

        <div class="relative w-full h-full bg-gray-900 flex flex-col overflow-hidden"
             :class="windowWidth >= 768 ? 'sa-viewer-panel-desktop' : ''">

            {{-- Header --}}
            <div class="flex items-center gap-3 px-3 py-2 bg-gray-800 border-b border-gray-700 shrink-0">
                <button type="button" @click="closeSubagentViewer()" class="text-gray-400 hover:text-white p-2 cursor-pointer" title="Terug">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <span class="sa-status sa-sm flex items-center justify-center shrink-0" :class="'sa-status-' + (subagentViewer.status || 'cancelled')">
                    <template x-if="subagentViewer.status === 'running' || subagentViewer.status === 'pending'">
                        <x-spinner class="!w-4 !h-4" />
                    </template>
                    <i x-show="subagentViewer.status === 'completed'" class="fa-solid fa-check"></i>
                    <i x-show="subagentViewer.status === 'failed'" class="fa-solid fa-triangle-exclamation"></i>
                    <i x-show="subagentViewer.status === 'cancelled'" class="fa-solid fa-stop"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-semibold text-gray-100 truncate" x-text="subagentViewer.title"></div>
                    <div class="text-xs text-gray-400 truncate" x-text="subagentViewerMeta()"></div>
                </div>
                <button type="button" x-show="!subagentViewer.native && (subagentViewer.status === 'running' || subagentViewer.status === 'pending')"
                        @click="cancelSubagent({ id: subagentViewer.taskId, title: subagentViewer.title })"
                        class="sa-stop-solid rounded cursor-pointer shrink-0"
                        title="Stoppen">
                    <i class="fa-solid fa-stop"></i> Stop
                </button>
            </div>

            {{-- Timeline --}}
            <div x-ref="subagentLog" class="flex-1 overflow-y-auto min-h-0 px-3 py-3 relative"
                 @scroll.passive="subagentViewer.stick = ($el.scrollHeight - $el.scrollTop - $el.clientHeight) < 80">
                <div x-show="subagentViewer.loading && subagentViewer.items.length === 0" class="flex justify-center py-10 text-gray-400">
                    <x-spinner class="mr-2" /> Geschiedenis laden…
                </div>

                <div class="flex flex-col gap-2">
                    <template x-for="item in subagentViewer.items" :key="item.key">
                        <div>
                            {{-- Prompt / user message --}}
                            <template x-if="item.kind === 'user'">
                                <div class="sa-prompt rounded-lg text-sm">
                                    <div class="flex items-center justify-between cursor-pointer" @click="item.open = !item.open">
                                        <span class="sa-label"><i class="fa-solid fa-robot mr-1"></i>Opdracht van de hoofdagent</span>
                                        <i class="fa-solid text-xs sa-label" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <div class="text-gray-100 whitespace-pre-wrap break-words mt-1"
                                         :style="item.open ? '' : 'max-height: 4.5em; overflow: hidden;'" x-text="item.text"></div>
                                </div>
                            </template>

                            {{-- Thinking --}}
                            <template x-if="item.kind === 'thinking'">
                                <div class="sa-thinking rounded-lg text-sm">
                                    <div class="sa-head flex items-center gap-2 cursor-pointer" @click="item.open = !item.open">
                                        <i class="fa-regular fa-lightbulb"></i><b style="font-size: 12px;">Denken</b>
                                        <x-spinner x-show="!item.done" class="!w-3 !h-3" />
                                        <span class="flex-1"></span>
                                        <i class="fa-solid text-xs" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <div class="sa-body whitespace-pre-wrap break-words mt-1"
                                         :style="item.open ? '' : 'max-height: 3em; overflow: hidden; opacity: .8;'" x-text="item.text"></div>
                                </div>
                            </template>

                            {{-- Text --}}
                            <template x-if="item.kind === 'text'">
                                <div class="markdown-content text-sm text-gray-100 px-1" x-html="renderMarkdown(item.text)"></div>
                            </template>

                            {{-- Tool call + result --}}
                            <template x-if="item.kind === 'tool'">
                                <div class="sa-tool rounded-lg font-mono overflow-hidden" :class="item.isError ? 'sa-tool-error' : ''">
                                    <div class="sa-head flex items-center gap-2 cursor-pointer" @click="item.open = !item.open">
                                        <x-spinner x-show="!item.hasResult" class="!w-3 !h-3 sa-name" />
                                        <i x-show="item.hasResult" class="fa-solid"
                                           :class="item.isError ? 'fa-xmark sa-fail' : 'fa-check sa-ok'"></i>
                                        <b class="sa-name" x-text="item.name"></b>
                                        <span class="truncate flex-1 min-w-0 text-gray-400" x-text="toolArgSummary(item)"></span>
                                        <i class="fa-solid text-gray-500" style="font-size: 10px;" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <template x-if="item.open">
                                        <div class="sa-section">
                                            <div class="sa-caption">Invoer</div>
                                            <pre class="whitespace-pre-wrap break-words" style="max-height: 300px;" x-text="toolInputPretty(item)"></pre>
                                            <template x-if="item.hasResult">
                                                <div>
                                                    <div class="sa-caption sa-section">Resultaat</div>
                                                    <pre class="whitespace-pre-wrap break-words" style="max-height: 400px;"
                                                         :class="item.isError ? 'sa-result-error' : ''" x-text="item.result || '(leeg)'"></pre>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- End / error marker --}}
                            <template x-if="item.kind === 'end'">
                                <div class="text-center text-xs py-2" :class="item.error ? 'sa-end-error' : 'text-gray-500'" x-text="item.text"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Jump to newest when scrolled up --}}
            <button type="button" x-show="!subagentViewer.stick && subagentViewer.items.length" x-cloak
                    @click="subagentViewer.stick = true; $refs.subagentLog.scrollTop = $refs.subagentLog.scrollHeight"
                    class="sa-newest absolute rounded-full bg-gray-700 hover:bg-gray-600 text-gray-200 cursor-pointer">
                <i class="fa-solid fa-arrow-down mr-1"></i>Nieuwste
            </button>
        </div>
    </div>
</template>
