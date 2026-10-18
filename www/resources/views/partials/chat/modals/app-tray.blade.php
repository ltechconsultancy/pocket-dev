{{-- App Tray: link tiles added by agents via `pd app-tray` (icon + title + subtitle).
     An app with sub-links opens a detail view (Open + specific pages) instead of navigating.
     Inline styles where the prebuilt Tailwind CSS lacks the class. --}}
<x-modal show="showAppTray" title="Apps" max-width="2xl">
    <div style="min-height: 120px;">

        {{-- ===== Grid ===== --}}
        <div x-show="!appTraySelected">
            <div x-show="appTrayLoading && appTrayItems.length === 0" class="flex justify-center py-8 text-gray-400">
                <x-spinner />
            </div>

            <div x-show="!appTrayLoading && appTrayItems.length === 0" x-cloak class="text-center py-8 text-sm text-gray-400">
                <i class="fa-solid fa-grip text-2xl mb-2 block"></i>
                Nog geen apps. Vraag een agent om er een toe te voegen,<br>
                bijvoorbeeld: <span class="text-gray-300">"zet deze app in de app tray"</span>.
            </div>

            <div x-show="appTrayItems.length > 0" class="grid gap-3"
                 style="grid-template-columns: repeat(auto-fill, minmax(96px, 1fr));">
                <template x-for="item in appTrayItems" :key="item.id">
                    <a :href="item.url"
                       :target="item.url.startsWith('/') ? '_self' : '_blank'"
                       rel="noopener noreferrer"
                       @click="openAppTrayItem(item, $event)"
                       :title="item.subtitle ? item.title + ' - ' + item.subtitle : item.title"
                       class="flex flex-col items-center text-center p-3 rounded-xl hover:bg-gray-700 transition-colors">
                        <span class="relative mb-2" style="width: 56px; height: 56px;">
                            <span class="flex items-center justify-center bg-gray-700 border border-gray-600 overflow-hidden text-2xl text-blue-400"
                                  style="width: 56px; height: 56px; border-radius: 16px;">
                                <i x-show="item.icon_type === 'fa'" :class="item.icon"></i>
                                {{-- x-if: only real image icons get an <img>; a hidden one with an empty src would fire "error" too --}}
                                <template x-if="item.icon_type === 'image'">
                                    <img :src="item.icon" alt=""
                                         x-on:error="item.icon_type = 'fa'; item.icon = 'fa-solid fa-link'"
                                         style="width: 100%; height: 100%; object-fit: cover;">
                                </template>
                                <span x-show="item.icon_type === 'text'" x-text="item.icon"></span>
                            </span>
                            {{-- Badge: this app has sub-pages --}}
                            <span x-show="item.links && item.links.length" x-cloak
                                  class="absolute flex items-center justify-center rounded-full bg-blue-600 text-white font-bold border-2 border-gray-800"
                                  style="right: -6px; bottom: -6px; width: 22px; height: 22px; font-size: 10px;"
                                  x-text="item.links ? item.links.length : ''"></span>
                        </span>
                        <span class="text-sm text-gray-100 font-medium leading-tight truncate w-full" x-text="item.title"></span>
                        <span x-show="item.subtitle" class="text-gray-400 leading-tight mt-0.5 line-clamp-2 w-full"
                              style="font-size: 11px;" x-text="item.subtitle"></span>
                    </a>
                </template>
            </div>
        </div>

        {{-- ===== Detail: app with sub-links ===== --}}
        <template x-if="appTraySelected">
            <div>
                <button type="button" @click="appTraySelected = null"
                        class="flex items-center gap-2 text-sm text-gray-400 hover:text-white mb-4 cursor-pointer">
                    <i class="fa-solid fa-chevron-left"></i> Apps
                </button>

                <div class="flex items-center gap-3 mb-4">
                    <span class="flex items-center justify-center bg-gray-700 border border-gray-600 overflow-hidden text-2xl text-blue-400 shrink-0"
                          style="width: 56px; height: 56px; border-radius: 16px;">
                        <i x-show="appTraySelected.icon_type === 'fa'" :class="appTraySelected.icon"></i>
                        <template x-if="appTraySelected.icon_type === 'image'">
                            <img :src="appTraySelected.icon" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                        </template>
                        <span x-show="appTraySelected.icon_type === 'text'" x-text="appTraySelected.icon"></span>
                    </span>
                    <div class="min-w-0">
                        <div class="text-base font-semibold text-gray-100 truncate" x-text="appTraySelected.title"></div>
                        <div x-show="appTraySelected.subtitle" class="text-xs text-gray-400" x-text="appTraySelected.subtitle"></div>
                    </div>
                </div>

                <div class="flex flex-col gap-1">
                    <a :href="appTraySelected.url"
                       :target="appTraySelected.url.startsWith('/') ? '_self' : '_blank'"
                       rel="noopener noreferrer"
                       @click="if (!appTraySelected.url.startsWith('/')) showAppTray = false"
                       class="flex items-center gap-3 px-3 py-3 rounded-lg bg-blue-600 hover:bg-blue-500 text-white transition-colors">
                        <i class="fa-solid fa-arrow-up-right-from-square w-5 text-center"></i>
                        <span class="flex-1 font-medium">Openen</span>
                    </a>
                    <template x-for="link in appTraySelected.links" :key="link.title">
                        <a :href="link.url"
                           :target="link.url.startsWith('/') ? '_self' : '_blank'"
                           rel="noopener noreferrer"
                           @click="if (!link.url.startsWith('/')) showAppTray = false"
                           class="flex items-center gap-3 px-3 py-3 rounded-lg hover:bg-gray-700 text-gray-100 transition-colors">
                            <span class="w-5 text-center text-blue-400 shrink-0">
                                <i x-show="link.icon_type === 'fa'" :class="link.icon"></i>
                                <template x-if="link.icon_type === 'image'">
                                    <img :src="link.icon" alt="" style="width: 20px; height: 20px; object-fit: contain; display: inline-block;">
                                </template>
                                <span x-show="link.icon_type === 'text'" x-text="link.icon"></span>
                            </span>
                            <span class="flex-1 min-w-0 truncate" x-text="link.title"></span>
                            <i class="fa-solid fa-chevron-right text-gray-500 text-xs"></i>
                        </a>
                    </template>
                </div>
            </div>
        </template>
    </div>
</x-modal>
