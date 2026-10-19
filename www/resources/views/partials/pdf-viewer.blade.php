{{-- Minimal PDF viewer for the file preview: zoom, rotate, fullscreen.
     Only dependency: PDF.js, loaded from the CDN the first time a PDF is opened.
     Pages get a correctly sized slot at once and are drawn when scrolled into view.
     id="file-preview-media" so the header fullscreen button works for PDFs too. --}}
<div id="file-preview-media"
     class="h-full w-full flex flex-col min-h-0"
     style="background: #0b1220;"
     x-data="{
        numPages: 0, page: 1, scale: 1, fit: true, rotation: 0,
        loading: true, error: null, _gen: 0, _io: null, _ro: null, _resizeTimer: null, _sizes: [],

        // The PDF.js document lives on the DOM element, never in reactive state or a getter:
        // Alpine wraps those in a Proxy, and PDF.js objects use private class fields that
        // cannot be read through one. Always read it as this.$root._pdfDoc.

        async init() {
            try {
                const base = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/';
                const lib = await (window.__pdfjs ??= import(base + 'pdf.min.mjs'));
                if (!lib.GlobalWorkerOptions.workerPort) {
                    // A worker script from another origin is not allowed; a same-origin blob that imports it is
                    const blob = new Blob(['import \'' + base + 'pdf.worker.min.mjs\';'], { type: 'text/javascript' });
                    lib.GlobalWorkerOptions.workerPort = new Worker(URL.createObjectURL(blob), { type: 'module' });
                }
                this.$root._pdfDoc = await lib.getDocument({ url: this.$store.filePreview.mediaUrl, withCredentials: true }).promise;
                this.numPages = this.$root._pdfDoc.numPages;
                for (let i = 1; i <= this.numPages; i++) {
                    const v = (await this.$root._pdfDoc.getPage(i)).getViewport({ scale: 1 });
                    this._sizes.push([v.width, v.height]);
                }
                this.loading = false;
                await this.$nextTick();
                this.layout();
                this._ro = new ResizeObserver(() => {
                    clearTimeout(this._resizeTimer);
                    this._resizeTimer = setTimeout(() => { if (this.fit) this.layout(); }, 150);
                });
                this._ro.observe(this.$refs.scroller);
            } catch (e) {
                this.loading = false;
                this.error = 'PDF kon niet worden geladen: ' + (e?.message || e);
            }
        },

        destroy() {
            this._ro?.disconnect();
            this._io?.disconnect();
            this.$root._pdfDoc?.destroy();
        },

        fitScale() {
            const [w, h] = this._sizes[0] || [612, 792];
            const pageWidth = this.rotation % 180 ? h : w;
            // Fit the width, but no wider than a readable 1000px column on big screens
            return Math.max(0.1, Math.min(this.$refs.scroller.clientWidth - 24, 1000) / pageWidth);
        },

        layout() {
            const gen = ++this._gen;
            if (this.fit) this.scale = this.fitScale();
            this._io?.disconnect();
            const box = this.$refs.pages;
            box.innerHTML = '';
            this._io = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && !entry.target.dataset.done) {
                        entry.target.dataset.done = '1';
                        this.renderPage(+entry.target.dataset.page, entry.target, gen);
                    }
                }
            }, { root: this.$refs.scroller, rootMargin: '800px 0px' });
            const turned = this.rotation % 180 !== 0;
            this._sizes.forEach(([w, h], i) => {
                const slot = document.createElement('div');
                slot.dataset.page = i + 1;
                slot.style.cssText = 'flex:none;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.5);'
                    + 'width:' + (turned ? h : w) * this.scale + 'px;height:' + (turned ? w : h) * this.scale + 'px';
                box.appendChild(slot);
                this._io.observe(slot);
            });
            this.onScroll();
        },

        async renderPage(n, slot, gen) {
            try {
                const page = await this.$root._pdfDoc.getPage(n);
                if (gen !== this._gen) return;
                const viewport = page.getViewport({ scale: this.scale, rotation: (page.rotate + this.rotation) % 360 });
                // Sharp on retina, but cap the canvas size so deep zoom doesn't eat all memory
                let ratio = Math.min(window.devicePixelRatio || 1, 2);
                const maxPixels = 16e6;
                if (viewport.width * viewport.height * ratio * ratio > maxPixels) {
                    ratio = Math.sqrt(maxPixels / (viewport.width * viewport.height));
                }
                const canvas = document.createElement('canvas');
                canvas.width = Math.floor(viewport.width * ratio);
                canvas.height = Math.floor(viewport.height * ratio);
                canvas.style.cssText = 'display:block;width:100%;height:100%';
                await page.render({
                    canvasContext: canvas.getContext('2d'),
                    viewport,
                    transform: ratio !== 1 ? [ratio, 0, 0, ratio, 0, 0] : null,
                }).promise;
                if (gen === this._gen) slot.replaceChildren(canvas);
            } catch (e) {
                // Page failed or render was superseded by a new layout: leave the blank slot
            }
        },

        zoom(factor) {
            const scroller = this.$refs.scroller;
            const position = scroller.scrollTop / Math.max(1, scroller.scrollHeight);
            this.fit = false;
            this.scale = Math.min(6, Math.max(0.2, this.scale * factor));
            this.layout();
            this.$nextTick(() => { scroller.scrollTop = position * scroller.scrollHeight; });
        },

        fitWidth() {
            this.fit = true;
            this.layout();
        },

        rotate() {
            this.rotation = (this.rotation + 90) % 360;
            this.layout();
        },

        onScroll() {
            const scroller = this.$refs.scroller;
            if (!scroller) return;
            const marker = scroller.scrollTop + scroller.clientHeight / 3;
            let current = 1;
            for (const slot of this.$refs.pages.children) {
                if (slot.offsetTop <= marker) current = +slot.dataset.page;
                else break;
            }
            this.page = current;
        },
     }">

    {{-- Toolbar --}}
    <div class="flex items-center justify-center gap-1 px-2 py-1 bg-gray-800 border-b border-gray-700 shrink-0 text-gray-300 text-sm select-none">
        <span class="px-2 text-gray-400" style="font-variant-numeric: tabular-nums;" x-show="numPages" x-text="page + ' / ' + numPages"></span>
        <span class="mx-1 text-gray-600">|</span>
        <button type="button" @click="zoom(1 / 1.25)" class="p-2 rounded hover:bg-gray-700 hover:text-white cursor-pointer" title="Uitzoomen">
            <i class="fa-solid fa-magnifying-glass-minus"></i>
        </button>
        <button type="button" @click="fitWidth()" class="px-2 py-1 rounded hover:bg-gray-700 hover:text-white cursor-pointer"
                :class="fit ? 'text-blue-400' : ''" title="Passend op breedte"
                style="min-width: 56px; font-variant-numeric: tabular-nums;" x-text="Math.round(scale * 100) + '%'"></button>
        <button type="button" @click="zoom(1.25)" class="p-2 rounded hover:bg-gray-700 hover:text-white cursor-pointer" title="Inzoomen">
            <i class="fa-solid fa-magnifying-glass-plus"></i>
        </button>
        <span class="mx-1 text-gray-600">|</span>
        <button type="button" @click="rotate()" class="p-2 rounded hover:bg-gray-700 hover:text-white cursor-pointer" title="Draaien">
            <i class="fa-solid fa-rotate-right"></i>
        </button>
        <button type="button" @click="$store.filePreview.toggleFullscreen()" class="p-2 rounded hover:bg-gray-700 hover:text-white cursor-pointer" title="Volledig scherm">
            <i class="fa-solid fa-expand"></i>
        </button>
    </div>

    {{-- Pages --}}
    <div x-ref="scroller" class="flex-1 overflow-auto min-h-0 relative" @scroll.passive="onScroll()">
        <div x-show="loading" class="flex items-center justify-center h-full text-gray-400">
            <x-spinner class="mr-2" /> PDF laden...
        </div>
        <div x-show="error" x-cloak class="flex items-center justify-center h-full text-center p-6 text-gray-300" x-text="error"></div>
        <div x-ref="pages" class="flex flex-col items-center gap-3 py-3" style="min-width: min-content; padding-left: 12px; padding-right: 12px;"></div>
    </div>
</div>
