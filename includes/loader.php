<?php /* Global page loader - include at the top of <body> */ ?>

<!-- =====================================================
     GLOBAL PAGE LOADER
     ===================================================== -->
<div id="pageLoader" style="
    position: fixed;
    inset: 0;
    background: #111827;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    transition: opacity 0.4s ease, visibility 0.4s ease;
">
    <div style="
        width: 52px;
        height: 52px;
        border: 4px solid rgba(34,197,94,0.2);
        border-top-color: #22c55e;
        border-radius: 50%;
        animation: loaderSpin 0.75s linear infinite;
        margin-bottom: 18px;
    "></div>
    <div style="color:#22c55e; font-size:15px; font-weight:600; letter-spacing:1px;">
        GroceryDelivery
    </div>
    <div style="color:#6b7280; font-size:12px; margin-top:5px;">Loading&hellip;</div>
</div>

<style>
@keyframes loaderSpin {
    to { transform: rotate(360deg); }
}
#pageLoader.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}
</style>

<script>
(function () {
    /* Hide loader once all page assets are fully loaded */
    function hideLoader() {
        var el = document.getElementById('pageLoader');
        if (el) { el.classList.add('hidden'); }
    }

    if (document.readyState === 'complete') {
        hideLoader();
    } else {
        window.addEventListener('load', hideLoader);
    }

    /* Re-show loader on any internal navigation link click */
    document.addEventListener('click', function (e) {
        var target = e.target.closest('a[href]');
        if (!target) { return; }
        var href = target.getAttribute('href');
        /* Skip anchors, external links, javascript: and non-http(s) */
        if (!href || href.startsWith('#') || href.startsWith('javascript') ||
            href.startsWith('http') || href.startsWith('//') || href.startsWith('mailto')) {
            return;
        }
        var el = document.getElementById('pageLoader');
        if (el) {
            el.classList.remove('hidden');
            /* Fallback: force hide after 5 seconds to avoid stuck loader */
            setTimeout(function () { el.classList.add('hidden'); }, 5000);
        }
    });

    /* Show loader on form submit */
    document.addEventListener('submit', function () {
        var el = document.getElementById('pageLoader');
        if (el) {
            el.classList.remove('hidden');
            setTimeout(function () { el.classList.add('hidden'); }, 8000);
        }
    });
})();
</script>
