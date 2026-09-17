<!-- SYSTEM LOADER -->
<div id="systemLoader" class="system-loader">
    <div class="loader-content">
        <div class="loader-icon-wrap">
            <i class="bi bi-cart3"></i>
            <div class="loader-spinner"></div>
        </div>
        <div class="loader-brand">Grocery<span>Delivery</span></div>
        <div class="loader-subtext">Loading, please wait...</div>
    </div>
</div>

<style>
.system-loader {
    position: fixed;
    inset: 0;
    z-index: 99999;
    background: #f4f7f6;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 1;
    visibility: visible;
    transition: opacity 0.35s ease, visibility 0.35s ease;
}

.system-loader.hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

.loader-content {
    text-align: center;
}

.loader-icon-wrap {
    position: relative;
    width: 64px;
    height: 64px;
    margin: 0 auto 16px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.loader-icon-wrap i {
    font-size: 26px;
    color: #22c55e;
    z-index: 2;
}

.loader-spinner {
    position: absolute;
    inset: 0;
    border: 3px solid rgba(34, 197, 94, 0.15);
    border-top-color: #22c55e;
    border-radius: 50%;
    animation: systemLoaderSpin 0.75s linear infinite;
}

@keyframes systemLoaderSpin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.loader-brand {
    font-size: 20px;
    font-weight: 800;
    color: #111827;
    letter-spacing: -0.5px;
}

.loader-brand span {
    color: #22c55e;
}

.loader-subtext {
    font-size: 13px;
    color: #6b7280;
    margin-top: 4px;
    font-weight: 500;
}
</style>

<script>
(function() {
    function hideLoader() {
        const loader = document.getElementById("systemLoader");
        if (loader) {
            setTimeout(function() {
                loader.classList.add("hidden");
            }, 150);
        }
    }

    if (document.readyState === "complete") {
        hideLoader();
    } else {
        window.addEventListener("load", hideLoader);
    }

    // Safety timeout in case load event was already fired
    setTimeout(hideLoader, 2000);
})();
</script>
