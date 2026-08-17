{{-- Fixed Bottom Input (Mobile) --}}
<div x-ref="mobileInput" class="js-chat-input fixed bottom-0 left-0 right-0 z-20 bg-gray-800 border-t border-gray-700 safe-area-bottom">
    <div x-show="queuedFollowUps.length > 0 && getScreen(activeScreenId)?.type === 'chat'" x-cloak class="px-2 pt-2 flex flex-col gap-1 max-h-28 overflow-y-auto">
        <div class="text-[11px] text-amber-400/80" x-text="isStreaming ? 'Queued — sends after current tool' : 'Queued — tap Send to deliver'"></div>
        <template x-for="item in queuedFollowUps" :key="item.id">
            <div class="flex items-start gap-2 bg-white/5 border border-white/10 rounded px-2 py-1 text-xs">
                <span class="text-gray-200 flex-1 min-w-0 truncate" x-text="item.prompt"></span>
                <button type="button" @click="cancelFollowUp(item.id)" class="text-gray-500 hover:text-red-400 shrink-0" title="Remove from queue">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </template>
    </div>
    {{-- Single Row: Voice | Textarea | Send --}}
    <div class="p-2 flex gap-2 items-end">
        {{-- Voice Button --}}
        <button type="button"
                @click="toggleVoiceRecording()"
                :class="voiceButtonClass"
                :disabled="isProcessing || waitingForFinalTranscript"
                class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed"
                title="Voice input">
            <x-spinner x-show="isProcessing || waitingForFinalTranscript" x-cloak />
            <i x-show="!isProcessing && !waitingForFinalTranscript && isRecording" class="fa-solid fa-stop" x-cloak></i>
            <i x-show="!isProcessing && !waitingForFinalTranscript && !isRecording" class="fa-solid fa-microphone"></i>
        </button>

        {{-- Textarea with Skill Autocomplete --}}
        <div class="flex-1 min-w-0 relative">
            {{-- Active Skill Chip --}}
            <div x-show="activeSkill"
                 x-cloak
                 class="absolute bottom-full left-0 mb-4 max-w-full">
                <div class="inline-flex items-center gap-2 px-1.5 py-1.5 bg-gray-800 border border-gray-700 rounded-lg shadow-lg max-w-full">
                    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 bg-green-600/20 border border-green-500/50 text-green-400 rounded text-xs font-medium whitespace-nowrap">
                        <span x-text="'/' + (activeSkill?.name || '')"></span>
                        <button type="button"
                                @click="clearActiveSkill()"
                                class="hover:text-green-200 transition-colors"
                                title="Remove skill">
                            <i class="fa-solid fa-times text-xs"></i>
                        </button>
                    </span>
                    <span class="text-xs text-gray-400 truncate min-w-0" x-text="activeSkill?.when_to_use || ''"></span>
                </div>
            </div>

            <textarea x-model="prompt"
                      x-ref="promptInput"
                      :placeholder="activeSkill ? 'Add context... (optional)' : 'Hey PocketDev... (/ for skills)'"
                      rows="1"
                      class="block w-full px-3 py-2 bg-gray-900 border border-gray-700 rounded-lg focus:outline-none focus:border-blue-500 text-white resize-none"
                      style="height: 40px; min-height: 40px; max-height: 168px; overflow-y: hidden;"
                      x-effect="prompt; $nextTick(() => { $el.style.height = 'auto'; const sh = $el.scrollHeight; $el.style.height = Math.min(sh, 168) + 'px'; $el.style.overflowY = sh > 168 ? 'auto' : 'hidden'; if (prompt) $el.scrollTop = $el.scrollHeight; }); updateSkillSuggestions();"
                      @keydown="handleSkillKeydown($event)"
                      @paste="
                          const items = $event.clipboardData?.items;
                          if (items) {
                              for (const item of items) {
                                  if (item.type.startsWith('image/')) {
                                      const blob = item.getAsFile();
                                      if (blob) {
                                          const now = new Date();
                                          const timestamp = now.toISOString().replace(/[-:T]/g, '').slice(0, 14);
                                          const ext = item.type.split('/')[1]?.replace('jpeg', 'jpg') || 'png';
                                          const filename = `pasted-image-${timestamp}.${ext}`;
                                          const file = new File([blob], filename, { type: item.type });
                                          Alpine.store('attachments').addFile(file);
                                      }
                                  }
                              }
                          }
                      "></textarea>

            {{-- Connection health indicator - badge on textarea corner, matches template indicator pattern --}}
            {{-- Three states: connected (green pulsing), processing (amber, tap for info), disconnected (amber, tap for info) --}}
            <template x-if="isStreaming">
                <span class="absolute -top-1 -right-1 z-10"
                      :class="getIndicatorState() === 'connected' ? 'pointer-events-none' : 'cursor-pointer'"
                      @click="if (getIndicatorState() !== 'connected') {
                          showToast(getIndicatorState() === 'processing'
                              ? 'Processing context...'
                              : 'Connection may be lost');
                      }">
                    <span class="relative flex w-3 h-3">
                        <span class="absolute inline-flex h-full w-full rounded-full opacity-75"
                              :class="getIndicatorState() === 'connected' ? 'bg-emerald-400 animate-ping' : 'bg-amber-400'"
                        ></span>
                        <span class="relative inline-flex w-3 h-3 rounded-full border border-gray-800"
                              :class="getIndicatorState() === 'connected' ? 'bg-emerald-500' : 'bg-amber-500'"
                        ></span>
                    </span>
                </span>
            </template>

            {{-- Skill Suggestions Dropdown (Mobile) --}}
            <div x-show="showSkillSuggestions"
                 x-cloak
                 @click.outside="showSkillSuggestions = false"
                 class="absolute bottom-full left-0 right-0 mb-1 bg-gray-800 border border-gray-700 rounded-lg shadow-xl overflow-hidden z-50 max-h-48 overflow-y-auto">
                <template x-for="(skill, index) in skillSuggestions" :key="skill.name">
                    <button type="button"
                            @click="selectSkill(skill)"
                            :class="index === selectedSkillIndex ? 'bg-blue-600/50' : ''"
                            class="w-full px-3 py-2 text-left flex flex-col gap-0.5 border-b border-gray-700 last:border-0 active:bg-gray-700">
                        <span class="text-sm font-medium text-green-400" x-text="'/' + skill.name"></span>
                        <span class="text-xs text-gray-400 line-clamp-1" x-text="skill.when_to_use"></span>
                    </button>
                </template>
                <div x-show="skillSuggestions.length === 0 && prompt.startsWith('/')"
                     class="px-3 py-2 text-xs text-gray-500">
                    No matching skills
                </div>
            </div>
        </div>

        {{-- Send/Stop/Queue. During a Cursor stream Stop stays left, Queue (+) is on the
             right in the Send slot so it is visible immediately — not only after typing. --}}
        <template x-if="isStreaming && _streamState.abortPending">
            <button type="button"
                    disabled
                    class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-not-allowed bg-gray-600 text-gray-300"
                    title="Stopping...">
                <x-spinner />
            </button>
        </template>
        <template x-if="isStreaming && !_streamState.abortPending">
            <div class="flex gap-1 shrink-0">
                <button type="button"
                        @click="abortStream()"
                        class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer bg-rose-600/90 hover:bg-rose-500 text-white"
                        style="touch-action: manipulation;"
                        title="Stop streaming">
                    <i class="fa-solid fa-stop"></i>
                </button>
                <button type="button"
                        @click="handleSendClick($event); if(!isRecording && !isProcessing && !waitingForFinalTranscript) sendMessage()"
                        :disabled="!canQueueFollowUp || isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading || (!prompt.trim() && !Alpine.store('attachments').hasFiles && !activeSkill)"
                        :class="!canQueueFollowUp || isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading || (!prompt.trim() && !Alpine.store('attachments').hasFiles && !activeSkill) ? 'bg-gray-600/80 text-gray-400' : 'bg-emerald-500/90 hover:bg-emerald-400 text-white'"
                        class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed"
                        style="touch-action: manipulation;"
                        title="Queue follow-up — sends after current tool">
                    <i class="fa-solid fa-plus"></i>
                </button>
            </div>
        </template>
        <template x-if="!isStreaming">
            <button type="button"
                    @click="handleSendClick($event); if(!isRecording && !isProcessing && !waitingForFinalTranscript) sendMessage()"
                    :disabled="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading || (!prompt.trim() && !Alpine.store('attachments').hasFiles && !activeSkill && queuedFollowUps.length === 0)"
                    :class="isProcessing || isRecording || waitingForFinalTranscript || Alpine.store('attachments').isUploading ? 'bg-gray-600/80 text-gray-400' : 'bg-emerald-500/90 hover:bg-emerald-400 text-white'"
                    class="w-12 py-[10px] rounded-lg text-xl flex items-center justify-center transition-colors cursor-pointer disabled:cursor-not-allowed"
                    style="touch-action: manipulation;"
                    title="Send message">
                <i class="fa-solid fa-paper-plane"></i>
            </button>
        </template>
    </div>
</div>
