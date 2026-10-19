{{-- Contributor model warning: the provider may train on everything in this chat.
     First row of the chat input bar, next to the sub-agent pill. Colors: public/themes/contributor.css --}}
<div x-show="String(model || '').toLowerCase().includes('contributor')" x-cloak class="{{ $wrapperClass ?? '' }}">
    <div class="pd-contributor-banner rounded-lg flex items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
        <span><b>Contributor-model:</b> alles in deze chat mag gebruikt worden om modellen te trainen. Deel geen klantcode, wachtwoorden of vertrouwelijke info.</span>
    </div>
</div>
