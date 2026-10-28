{{-- Todo app: clients > projects > tasks > subtasks. Shared with agents (`pd todo`).
     Own Alpine scope inside the modal; uses the chat's currentSession / loadSession() for session links.
     Inline styles where the prebuilt Tailwind CSS lacks the class. --}}
<x-modal show="showTodos" title="Todo" max-width="2xl">
<div x-data="{
    data: { clients: [], projects: [], tasks: [], apps: [], sessions: {} },
    loading: false,
    error: '',
    showDone: false,
    collapsed: JSON.parse(localStorage.getItem('todoCollapsed') || '{}'),
    quick: { title: '', project_id: localStorage.getItem('todoQuickProject') || '', deadline: '' },
    subFor: null,      // task id with an open subtask input
    subTitle: '',
    edit: null,        // { type, id|null, fields }

    async load() {
        this.loading = true;
        try {
            const r = await fetch('/api/todos', { headers: { 'Accept': 'application/json' } });
            if (r.ok) {
                this.data = await r.json();
                this.checkQuickProject();
            }
        } catch (e) { /* offline: keep what we had */ }
        finally { this.loading = false; }
    },

    async api(method, url, body) {
        this.error = '';
        const r = await fetch(url, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || ''
            },
            body: body ? JSON.stringify(body) : undefined
        });
        const json = await r.json().catch(() => ({}));
        if (!r.ok || json.ok === false) {
            this.error = json.error || json.message || 'Opslaan mislukt';
            throw new Error(this.error);
        }
        return json;
    },

    // ----- Lookups -----
    project(id) { return this.data.projects.find(p => p.id === id); },
    client(id) { return this.data.clients.find(c => c.id === id); },
    app(id) { return this.data.apps.find(a => a.id === id); },
    sessionName(id) { return this.data.sessions[id]?.name || 'Sessie'; },

    sortTasks(list) {
        return [...list].sort((a, b) => {
            if (a.done !== b.done) return a.done ? 1 : -1;
            if (a.done) return (b.done_at || '').localeCompare(a.done_at || '');
            if ((a.deadline || 'z') !== (b.deadline || 'z')) return (a.deadline || 'z').localeCompare(b.deadline || 'z');
            return a.sort_order - b.sort_order;
        });
    },
    tasksOf(projectId) {
        return this.sortTasks(this.data.tasks.filter(t => !t.parent_id && (t.project_id || null) === projectId && (this.showDone || !t.done)));
    },
    subtasksOf(taskId) {
        return this.sortTasks(this.data.tasks.filter(t => t.parent_id === taskId && (this.showDone || !t.done)));
    },
    subCount(taskId) {
        const subs = this.data.tasks.filter(t => t.parent_id === taskId);
        return subs.length ? subs.filter(t => t.done).length + '/' + subs.length : '';
    },
    projectCount(p) {
        const all = this.data.tasks.filter(t => t.project_id === p.id && !t.parent_id);
        return all.filter(t => t.done).length + '/' + all.length;
    },
    projectsOf(clientId) {
        return this.data.projects.filter(p => (p.client_id || null) === clientId && (this.showDone || !p.done));
    },
    get groups() {
        const groups = [];
        const inbox = this.tasksOf(null);
        if (inbox.length) groups.push({ key: 'inbox', label: 'Inbox', icon: 'fa-solid fa-inbox', client: null, tasks: inbox, projects: [] });
        for (const c of this.data.clients) {
            groups.push({ key: c.id, label: c.name, icon: 'fa-solid fa-building', client: c, tasks: [], projects: this.projectsOf(c.id) });
        }
        const loose = this.projectsOf(null);
        if (loose.length) groups.push({ key: 'noclient', label: 'Zonder klant', icon: 'fa-solid fa-folder-open', client: null, tasks: [], projects: loose });
        return groups;
    },
    get openCount() { return this.data.tasks.filter(t => !t.done).length; },
    get urgentCount() {
        const today = this.today();
        return this.data.tasks.filter(t => !t.done && t.deadline && t.deadline <= today).length;
    },

    // ----- Deadlines -----
    today() { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); },
    deadlineInfo(date, done) {
        if (!date) return null;
        const days = Math.round((new Date(date + 'T00:00:00') - new Date(this.today() + 'T00:00:00')) / 86400000);
        const nice = new Date(date + 'T00:00:00').toLocaleDateString('nl-NL', { weekday: 'short', day: 'numeric', month: 'short' });
        let label = nice, color = '#9ca3af';
        if (!done) {
            if (days < 0) { label = 'te laat · ' + nice; color = '#f87171'; }
            else if (days === 0) { label = 'vandaag'; color = '#fbbf24'; }
            else if (days === 1) { label = 'morgen'; color = '#fbbf24'; }
            else if (days <= 7) { color = '#60a5fa'; }
        }
        return { label, color };
    },

    toggleCollapse(key) {
        this.collapsed[key] = !this.collapsed[key];
        localStorage.setItem('todoCollapsed', JSON.stringify(this.collapsed));
    },

    // ----- Actions -----
    checkQuickProject() {
        const p = this.quick.project_id && this.project(this.quick.project_id);
        if (!p || p.done) this.quick.project_id = '';
    },
    async quickAdd() {
        this.checkQuickProject();
        const title = this.quick.title.trim();
        if (!title) return;
        localStorage.setItem('todoQuickProject', this.quick.project_id);
        try {
            await this.api('POST', '/api/todos/task', { title, project_id: this.quick.project_id || null, deadline: this.quick.deadline || null });
            this.quick.title = '';
            this.quick.deadline = '';
            await this.load();
        } catch (e) {}
    },
    async addSubtask(parent) {
        const title = this.subTitle.trim();
        if (!title) { this.subFor = null; return; }
        try {
            await this.api('POST', '/api/todos/task', { title, parent_id: parent.id });
            this.subTitle = '';
            await this.load();
        } catch (e) {}
    },
    async toggle(type, item) {
        const done = !item.done;
        item.done = done; // optimistic
        try {
            await this.api('PATCH', `/api/todos/${type}/${item.id}`, { done });
            await this.load();
        } catch (e) { item.done = !done; }
    },

    openEdit(type, item, preset = {}) {
        const f = item ? { ...item } : { name: '', title: '', notes: '', deadline: '', client_id: null, project_id: null, parent_id: null, session_id: null, app_tray_item_id: null, ...preset };
        this.edit = { type, id: item?.id || null, fields: f };
    },
    async saveEdit() {
        const { type, id, fields } = this.edit;
        const keys = { client: ['name', 'notes'], project: ['name', 'notes', 'client_id', 'deadline', 'session_id', 'app_tray_item_id'], task: ['title', 'notes', 'project_id', 'parent_id', 'deadline', 'session_id', 'app_tray_item_id'] }[type];
        const body = {};
        keys.forEach(k => body[k] = fields[k] === '' ? null : fields[k]);
        if (type === 'task' && fields.parent_id) delete body.project_id;
        try {
            await this.api(id ? 'PATCH' : 'POST', id ? `/api/todos/${type}/${id}` : `/api/todos/${type}`, body);
            this.edit = null;
            await this.load();
        } catch (e) {}
    },
    async deleteEdit() {
        const { type, id } = this.edit;
        const msg = { client: 'Klant verwijderen? Projecten blijven bestaan (zonder klant).', project: 'Project en alle taken verwijderen?', task: 'Taak (en subtaken) verwijderen?' }[type];
        if (!confirm(msg)) return;
        try {
            await this.api('DELETE', `/api/todos/${type}/${id}`);
            this.edit = null;
            await this.load();
        } catch (e) {}
    },
    goSession(id) {
        showTodos = false;
        loadSession(id);
    },
    openApp(id) {
        const a = this.app(id);
        if (!a) return;
        if (a.url.startsWith('/')) window.location.href = a.url;
        else window.open(a.url, '_blank', 'noopener');
    }
}" @todos-open.window="edit = null; error = ''; load()">

    {{-- ===== Edit view ===== --}}
    <template x-if="edit">
        <div>
            <button type="button" @click="edit = null; error = ''" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white mb-4 cursor-pointer">
                <i class="fa-solid fa-chevron-left"></i> Terug
            </button>
            <div class="text-base font-semibold text-gray-100 mb-3"
                 x-text="(edit.id ? '' : 'Nieuwe ') + ({ client: 'klant', project: 'project', task: edit.fields.parent_id ? 'subtaak' : 'taak' }[edit.type])"
                 style="text-transform: capitalize;"></div>

            <div class="flex flex-col gap-3 text-sm">
                <label class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs" x-text="edit.type === 'task' ? 'Titel' : 'Naam'"></span>
                    <input x-show="edit.type === 'task'" type="text" x-model="edit.fields.title" @keydown.enter="saveEdit()" maxlength="255"
                           class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full">
                    <input x-show="edit.type !== 'task'" type="text" x-model="edit.fields.name" @keydown.enter="saveEdit()" maxlength="150"
                           class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full">
                </label>

                <label x-show="edit.type === 'project'" class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">Klant</span>
                    <select x-model="edit.fields.client_id" class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full">
                        <option value="">Zonder klant</option>
                        <template x-for="c in data.clients" :key="c.id">
                            <option :value="c.id" x-text="c.name" :selected="c.id === edit.fields.client_id"></option>
                        </template>
                    </select>
                </label>

                <label x-show="edit.type === 'task' && !edit.fields.parent_id" class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">Project</span>
                    <select x-model="edit.fields.project_id" class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full">
                        <option value="">Inbox (geen project)</option>
                        <template x-for="p in data.projects.filter(p => !p.done || p.id === edit.fields.project_id)" :key="p.id">
                            <option :value="p.id" :selected="p.id === edit.fields.project_id"
                                    x-text="(client(p.client_id)?.name ? client(p.client_id).name + ' · ' : '') + p.name"></option>
                        </template>
                    </select>
                </label>

                <label x-show="edit.type !== 'client'" class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">Deadline</span>
                    <div class="flex items-center gap-2">
                        <input type="date" x-model="edit.fields.deadline" class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100" style="color-scheme: dark;">
                        <button type="button" x-show="edit.fields.deadline" @click="edit.fields.deadline = ''" class="text-xs text-gray-400 hover:text-white cursor-pointer">wissen</button>
                    </div>
                </label>

                <label x-show="edit.type !== 'client'" class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">App</span>
                    <select x-model="edit.fields.app_tray_item_id" class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full">
                        <option value="">Geen app</option>
                        <template x-for="a in data.apps" :key="a.id">
                            <option :value="a.id" x-text="a.title" :selected="a.id === edit.fields.app_tray_item_id"></option>
                        </template>
                    </select>
                </label>

                <div x-show="edit.type !== 'client'" class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">Sessie</span>
                    <div class="flex items-center gap-2 flex-wrap">
                        <template x-if="edit.fields.session_id">
                            <span class="flex items-center gap-2 bg-gray-700 rounded-md px-3 py-1.5 text-gray-100">
                                <i class="fa-solid fa-comments text-blue-400"></i>
                                <span x-text="edit.fields.session_id === currentSession?.id ? (currentSession?.name || 'Huidige sessie') : sessionName(edit.fields.session_id)"></span>
                                <button type="button" @click="edit.fields.session_id = null" class="text-gray-400 hover:text-white cursor-pointer" title="Ontkoppelen"><i class="fa-solid fa-xmark"></i></button>
                            </span>
                        </template>
                        <button type="button" x-show="currentSession?.id && edit.fields.session_id !== currentSession?.id"
                                @click="edit.fields.session_id = currentSession.id"
                                class="text-xs text-blue-400 hover:text-blue-300 cursor-pointer">
                            <i class="fa-solid fa-link"></i> Koppel aan huidige sessie
                        </button>
                        <span x-show="!edit.fields.session_id && !currentSession?.id" class="text-xs text-gray-500">Open een sessie om te koppelen</span>
                    </div>
                </div>

                <label class="flex flex-col gap-1">
                    <span class="text-gray-400 text-xs">Notities</span>
                    <textarea x-model="edit.fields.notes" rows="4" class="bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-gray-100 w-full"></textarea>
                </label>

                <div x-show="edit.id && edit.type !== 'client'" class="text-xs text-gray-500">
                    <span x-text="'Toegevoegd door ' + (edit.fields.created_by === 'ai' ? 'AI' : 'jou')"></span><span
                          x-show="edit.fields.done" x-text="' · afgevinkt door ' + (edit.fields.done_by === 'ai' ? 'AI' : 'jou')"></span>
                </div>

                <div x-show="error" class="text-sm text-red-400" x-text="error"></div>

                <div class="flex items-center gap-2 pt-1">
                    <x-button variant="primary" @click="saveEdit()">Opslaan</x-button>
                    <x-button variant="secondary" @click="edit = null; error = ''">Annuleren</x-button>
                    <span class="flex-1"></span>
                    <x-button variant="danger" x-show="edit.id" @click="deleteEdit()"><i class="fa-solid fa-trash"></i></x-button>
                </div>
            </div>
        </div>
    </template>

    {{-- ===== List view ===== --}}
    <div x-show="!edit" style="min-height: 160px;">
        {{-- Quick add --}}
        <form @submit.prevent="quickAdd()" class="flex items-center gap-2 mb-2">
            <input type="text" x-model="quick.title" placeholder="Nieuwe taak..." maxlength="255"
                   class="flex-1 min-w-0 bg-gray-900 border border-gray-600 rounded-md px-3 py-2 text-sm text-gray-100">
            <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white rounded-md px-3 py-2 text-sm cursor-pointer shrink-0" title="Toevoegen">
                <i class="fa-solid fa-plus"></i>
            </button>
        </form>
        <div class="flex items-center gap-2 mb-3 text-xs">
            <select x-model="quick.project_id" class="min-w-0 bg-gray-900 border border-gray-700 rounded-md px-2 py-1 text-gray-300" style="max-width: 55%;">
                <option value="">Inbox</option>
                <template x-for="p in data.projects.filter(p => !p.done)" :key="p.id">
                    <option :value="p.id" :selected="p.id === quick.project_id"
                            x-text="(client(p.client_id)?.name ? client(p.client_id).name + ' · ' : '') + p.name"></option>
                </template>
            </select>
            <input type="date" x-model="quick.deadline" class="bg-gray-900 border border-gray-700 rounded-md px-2 py-1 text-gray-300" style="color-scheme: dark;" title="Deadline">
        </div>

        {{-- Toolbar --}}
        <div class="flex items-center gap-3 mb-3 text-xs text-gray-400 flex-wrap">
            <span><span x-text="openCount"></span> open</span>
            <span x-show="urgentCount" class="text-red-400"><i class="fa-solid fa-clock"></i> <span x-text="urgentCount"></span> vandaag / te laat</span>
            <span class="flex-1"></span>
            <button type="button" @click="openEdit('project', null)" class="hover:text-white cursor-pointer"><i class="fa-solid fa-plus"></i> Project</button>
            <button type="button" @click="openEdit('client', null)" class="hover:text-white cursor-pointer"><i class="fa-solid fa-plus"></i> Klant</button>
            <button type="button" @click="showDone = !showDone" class="hover:text-white cursor-pointer" :class="showDone && 'text-blue-400'">
                <i class="fa-solid" :class="showDone ? 'fa-eye' : 'fa-eye-slash'"></i> Afgerond
            </button>
        </div>

        <div x-show="error" class="text-sm text-red-400 mb-2" x-text="error"></div>

        <div x-show="loading && !data.tasks.length && !data.projects.length" class="flex justify-center py-8 text-gray-400"><x-spinner /></div>

        <div x-show="!loading && !groups.length" x-cloak class="text-center py-8 text-sm text-gray-400">
            <i class="fa-solid fa-list-check text-2xl mb-2 block"></i>
            Nog niks te doen. Typ hierboven een taak,<br>of vraag de AI: <span class="text-gray-300">"zet dit op mijn todo"</span>.
        </div>

        {{-- Groups: Inbox, clients, projects without client --}}
        <div class="flex flex-col gap-3">
            <template x-for="g in groups" :key="g.key">
                <div class="rounded-lg border border-gray-700" style="background: rgba(17,24,39,0.4);">
                    <div class="flex items-center gap-2 px-3 py-2 cursor-pointer" @click="toggleCollapse(g.key)">
                        <i class="fa-solid fa-chevron-right text-gray-500 text-xs transition-transform" :class="!collapsed[g.key] && 'rotate-90'"></i>
                        <i :class="g.icon" class="text-gray-400 text-sm"></i>
                        <span class="font-semibold text-gray-100 text-sm flex-1 truncate" x-text="g.label"></span>
                        <button type="button" x-show="g.client" @click.stop="openEdit('project', null, { client_id: g.client?.id })"
                                class="text-xs text-gray-400 hover:text-white cursor-pointer px-1" title="Project toevoegen"><i class="fa-solid fa-plus"></i></button>
                        <button type="button" x-show="g.client" @click.stop="openEdit('client', g.client)"
                                class="text-xs text-gray-400 hover:text-white cursor-pointer px-1" title="Klant bewerken"><i class="fa-solid fa-pen"></i></button>
                    </div>

                    <div x-show="!collapsed[g.key]" class="pb-2">
                        {{-- Inbox tasks --}}
                        <template x-for="t in g.tasks" :key="t.id">
                            <div>@include('partials.chat.modals.todo-task-row')</div>
                        </template>

                        <div x-show="g.client && !g.projects.length" class="px-3 py-1 text-xs text-gray-500" style="padding-left: 36px;">Geen projecten</div>

                        {{-- Projects --}}
                        <template x-for="p in g.projects" :key="p.id">
                            <div class="mt-1">
                                <div class="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-700 rounded-md" style="margin: 0 4px;">
                                    <button type="button" @click="toggleCollapse(p.id)" class="text-gray-500 cursor-pointer" style="width: 14px;">
                                        <i class="fa-solid fa-chevron-right text-xs transition-transform" :class="!collapsed[p.id] && 'rotate-90'"></i>
                                    </button>
                                    <button type="button" @click="toggle('project', p)" class="cursor-pointer shrink-0" title="Project afronden">
                                        <i class="fa-regular" :class="p.done ? 'fa-square-check text-emerald-400' : 'fa-folder text-blue-400'"></i>
                                    </button>
                                    <span class="flex-1 min-w-0 truncate text-sm font-medium text-gray-100 cursor-pointer" :class="p.done && 'opacity-60'"
                                          :style="{ textDecoration: p.done ? 'line-through' : 'none' }"
                                          @click="openEdit('project', p)" x-text="p.name"></span>
                                    <span x-show="p.deadline" class="text-xs shrink-0" :style="'color:' + deadlineInfo(p.deadline, p.done)?.color"
                                          x-text="deadlineInfo(p.deadline, p.done)?.label"></span>
                                    <button type="button" x-show="p.app_tray_item_id && app(p.app_tray_item_id)" @click="openApp(p.app_tray_item_id)"
                                            class="text-xs text-gray-400 hover:text-white cursor-pointer" :title="'Open ' + (app(p.app_tray_item_id)?.title || '')"><i class="fa-solid fa-grip"></i></button>
                                    <button type="button" x-show="p.session_id" @click="goSession(p.session_id)"
                                            class="text-xs text-gray-400 hover:text-white cursor-pointer" :title="'Sessie: ' + sessionName(p.session_id)"><i class="fa-solid fa-comments"></i></button>
                                    <span class="text-xs text-gray-500 shrink-0" x-text="projectCount(p)"></span>
                                    <button type="button" @click="openEdit('task', null, { project_id: p.id, session_id: currentSession?.id || null })"
                                            class="text-xs text-gray-400 hover:text-white cursor-pointer px-1" title="Taak toevoegen"><i class="fa-solid fa-plus"></i></button>
                                </div>
                                <div x-show="!collapsed[p.id]" style="padding-left: 18px;">
                                    <template x-for="t in tasksOf(p.id)" :key="t.id">
                                        <div>@include('partials.chat.modals.todo-task-row')</div>
                                    </template>
                                    <div x-show="!tasksOf(p.id).length" class="text-xs text-gray-500" style="padding: 2px 0 4px 44px;">Geen open taken</div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
</x-modal>
