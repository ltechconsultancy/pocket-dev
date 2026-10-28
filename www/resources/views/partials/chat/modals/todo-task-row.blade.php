{{-- One task row (+ its subtasks and the "add subtask" input). Expects `t` in Alpine scope (todos.blade.php). --}}
<div class="flex items-center gap-2 px-3 py-1 rounded-md hover:bg-gray-700" style="margin: 0 4px;">
    <button type="button" @click="toggle('task', t)" class="cursor-pointer shrink-0" style="width: 18px;" :title="t.done ? 'Heropenen' : 'Afvinken'">
        <i class="fa-regular" :class="t.done ? 'fa-square-check text-emerald-400' : 'fa-square text-gray-400'"></i>
    </button>
    <span class="flex-1 min-w-0 text-sm text-gray-200 cursor-pointer" style="overflow-wrap: anywhere;"
          :class="t.done && 'opacity-60'" :style="{ textDecoration: t.done ? 'line-through' : 'none' }"
          @click="openEdit('task', t)">
        <span x-text="t.title"></span>
        <i x-show="t.notes" class="fa-regular fa-note-sticky text-gray-500 text-xs" title="Heeft notities"></i>
    </span>
    <span x-show="subCount(t.id)" class="text-xs text-gray-500 shrink-0" x-text="subCount(t.id)"></span>
    <span x-show="t.deadline" class="text-xs shrink-0" :style="'color:' + deadlineInfo(t.deadline, t.done)?.color" x-text="deadlineInfo(t.deadline, t.done)?.label"></span>
    <i x-show="t.created_by === 'ai' || t.done_by === 'ai'" class="fa-solid fa-robot text-xs text-purple-400 shrink-0"
       :title="[t.created_by === 'ai' ? 'Toegevoegd door AI' : '', t.done_by === 'ai' ? 'Afgevinkt door AI' : ''].filter(Boolean).join(' · ')"></i>
    <button type="button" x-show="t.app_tray_item_id && app(t.app_tray_item_id)" @click="openApp(t.app_tray_item_id)"
            class="text-xs text-gray-400 hover:text-white cursor-pointer shrink-0" :title="'Open ' + (app(t.app_tray_item_id)?.title || '')">
        <i class="fa-solid fa-grip"></i>
    </button>
    <button type="button" x-show="t.session_id" @click="goSession(t.session_id)"
            class="text-xs text-gray-400 hover:text-white cursor-pointer shrink-0" :title="'Sessie: ' + sessionName(t.session_id)">
        <i class="fa-solid fa-comments"></i>
    </button>
    <button type="button" x-show="!t.parent_id" @click="subFor = (subFor === t.id ? null : t.id); subTitle = ''"
            class="text-xs text-gray-500 hover:text-white cursor-pointer shrink-0" title="Subtaak toevoegen">
        <i class="fa-solid fa-plus"></i>
    </button>
</div>

<template x-if="!t.parent_id">
    <div style="padding-left: 26px;">
        <template x-for="s in subtasksOf(t.id)" :key="s.id">
            <div class="flex items-center gap-2 px-3 rounded-md hover:bg-gray-700" style="margin: 0 4px; padding-top: 2px; padding-bottom: 2px;">
                <button type="button" @click="toggle('task', s)" class="cursor-pointer shrink-0" style="width: 18px;">
                    <i class="fa-regular text-sm" :class="s.done ? 'fa-square-check text-emerald-400' : 'fa-square text-gray-500'"></i>
                </button>
                <span class="flex-1 min-w-0 text-gray-300 cursor-pointer" style="font-size: 13px; overflow-wrap: anywhere;"
                      :class="s.done && 'opacity-60'" :style="{ textDecoration: s.done ? 'line-through' : 'none' }"
                      @click="openEdit('task', s)" x-text="s.title"></span>
                <span x-show="s.deadline" class="text-xs shrink-0" :style="'color:' + deadlineInfo(s.deadline, s.done)?.color" x-text="deadlineInfo(s.deadline, s.done)?.label"></span>
                <i x-show="s.created_by === 'ai' || s.done_by === 'ai'" class="fa-solid fa-robot text-xs text-purple-400 shrink-0"></i>
            </div>
        </template>
        <form x-show="subFor === t.id" @submit.prevent="addSubtask(t)" class="flex items-center gap-2 px-3 py-1" style="margin: 0 4px;">
            <input type="text" x-model="subTitle" x-effect="if (subFor === t.id) $nextTick(() => $el.focus())" placeholder="Subtaak..." maxlength="255"
                   @keydown.escape.stop="subFor = null"
                   class="flex-1 min-w-0 bg-gray-900 border border-gray-600 rounded-md px-2 py-1 text-gray-100" style="font-size: 13px;">
            <button type="submit" class="text-blue-400 hover:text-blue-300 cursor-pointer text-sm"><i class="fa-solid fa-check"></i></button>
        </form>
    </div>
</template>
