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
             :style="windowWidth >= 768 ? 'max-width: 860px; height: 88vh; border: 1px solid #4b5563; border-radius: 12px;' : ''">

            {{-- Header --}}
            <div class="flex items-center gap-3 px-3 py-2 bg-gray-800 border-b border-gray-700 shrink-0">
                <button type="button" @click="closeSubagentViewer()" class="text-gray-400 hover:text-white p-2 cursor-pointer" title="Terug">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <span class="flex items-center justify-center shrink-0"
                      style="width: 30px; height: 30px; border-radius: 9px;"
                      :style="{ background: { running: '#4338ca', pending: '#4338ca', completed: '#065f46', failed: '#7f1d1d', cancelled: '#374151' }[subagentViewer.status] || '#374151' }">
                    <template x-if="subagentViewer.status === 'running' || subagentViewer.status === 'pending'">
                        <x-spinner class="!w-4 !h-4 text-white" />
                    </template>
                    <i x-show="subagentViewer.status === 'completed'" class="fa-solid fa-check" style="color: #6ee7b7;"></i>
                    <i x-show="subagentViewer.status === 'failed'" class="fa-solid fa-triangle-exclamation" style="color: #fca5a5;"></i>
                    <i x-show="subagentViewer.status === 'cancelled'" class="fa-solid fa-stop text-gray-300"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-semibold text-gray-100 truncate" x-text="subagentViewer.title"></div>
                    <div class="text-xs text-gray-400 truncate" x-text="subagentViewerMeta()"></div>
                </div>
                <button type="button" x-show="subagentViewer.status === 'running' || subagentViewer.status === 'pending'"
                        @click="cancelSubagent({ id: subagentViewer.taskId, title: subagentViewer.title })"
                        class="rounded cursor-pointer shrink-0" style="padding: 6px 10px; font-size: 12px; background: #7f1d1d; color: #fecaca;"
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
                                <div class="rounded-lg text-sm" style="background: #312e81; padding: 10px 12px;">
                                    <div class="flex items-center justify-between cursor-pointer" @click="item.open = !item.open">
                                        <span style="font-size: 11px; color: #a5b4fc;"><i class="fa-solid fa-robot mr-1"></i>Opdracht van de hoofdagent</span>
                                        <i class="fa-solid text-xs" style="color: #a5b4fc;" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <div class="text-gray-100 whitespace-pre-wrap break-words mt-1"
                                         :style="item.open ? '' : 'max-height: 4.5em; overflow: hidden;'" x-text="item.text"></div>
                                </div>
                            </template>

                            {{-- Thinking --}}
                            <template x-if="item.kind === 'thinking'">
                                <div class="rounded-lg text-sm" style="background: rgba(88,28,135,.25); border: 1px solid #6b21a8; padding: 8px 12px;">
                                    <div class="flex items-center gap-2 cursor-pointer" style="color: #d8b4fe;" @click="item.open = !item.open">
                                        <i class="fa-regular fa-lightbulb"></i><b style="font-size: 12px;">Denken</b>
                                        <x-spinner x-show="!item.done" class="!w-3 !h-3" />
                                        <span class="flex-1"></span>
                                        <i class="fa-solid text-xs" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <div class="whitespace-pre-wrap break-words mt-1" style="color: #e9d5ff; font-size: 13px;"
                                         :style="item.open ? '' : 'max-height: 3em; overflow: hidden; opacity: .8;'" x-text="item.text"></div>
                                </div>
                            </template>

                            {{-- Text --}}
                            <template x-if="item.kind === 'text'">
                                <div class="markdown-content text-sm text-gray-100 px-1" x-html="renderMarkdown(item.text)"></div>
                            </template>

                            {{-- Tool call + result --}}
                            <template x-if="item.kind === 'tool'">
                                <div class="rounded-lg font-mono overflow-hidden" style="font-size: 12px;"
                                     :style="{ background: '#0b1220', border: '1px solid ' + (item.isError ? '#7f1d1d' : '#1e3a8a') }">
                                    <div class="flex items-center gap-2 cursor-pointer" style="padding: 7px 10px;" @click="item.open = !item.open">
                                        <x-spinner x-show="!item.hasResult" class="!w-3 !h-3" style="color: #93c5fd;" />
                                        <i x-show="item.hasResult" class="fa-solid"
                                           :class="item.isError ? 'fa-xmark' : 'fa-check'"
                                           :style="{ color: item.isError ? '#fca5a5' : '#10b981' }"></i>
                                        <b style="color: #93c5fd;" x-text="item.name"></b>
                                        <span class="truncate flex-1 min-w-0 text-gray-400" x-text="toolArgSummary(item)"></span>
                                        <i class="fa-solid text-gray-500" style="font-size: 10px;" :class="item.open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                                    </div>
                                    <template x-if="item.open">
                                        <div style="border-top: 1px solid #1f2937;">
                                            <div class="text-gray-500" style="padding: 6px 10px 0; font-size: 10px; text-transform: uppercase; letter-spacing: .05em;">Invoer</div>
                                            <pre class="whitespace-pre-wrap break-words text-gray-300" style="margin: 0; padding: 4px 10px 8px; max-height: 300px; overflow: auto;" x-text="toolInputPretty(item)"></pre>
                                            <template x-if="item.hasResult">
                                                <div>
                                                    <div class="text-gray-500" style="padding: 6px 10px 0; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; border-top: 1px solid #1f2937;">Resultaat</div>
                                                    <pre class="whitespace-pre-wrap break-words" style="margin: 0; padding: 4px 10px 8px; max-height: 400px; overflow: auto;"
                                                         :style="{ color: item.isError ? '#fca5a5' : '#d1d5db' }" x-text="item.result || '(leeg)'"></pre>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- End / error marker --}}
                            <template x-if="item.kind === 'end'">
                                <div class="text-center text-xs py-2" :style="{ color: item.error ? '#fca5a5' : '#6b7280' }" x-text="item.text"></div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Jump to newest when scrolled up --}}
            <button type="button" x-show="!subagentViewer.stick && subagentViewer.items.length" x-cloak
                    @click="subagentViewer.stick = true; $refs.subagentLog.scrollTop = $refs.subagentLog.scrollHeight"
                    class="absolute rounded-full bg-gray-700 hover:bg-gray-600 text-gray-200 cursor-pointer"
                    style="right: 16px; bottom: 16px; padding: 8px 12px; font-size: 12px; box-shadow: 0 4px 12px rgba(0,0,0,.5);">
                <i class="fa-solid fa-arrow-down mr-1"></i>Nieuwste
            </button>
        </div>
    </div>
</template>
