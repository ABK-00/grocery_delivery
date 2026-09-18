<script id="gd-theme-bootstrap">(function(){var t=localStorage.getItem('gd-theme')||'light';document.documentElement.setAttribute('data-theme',t)})();</script>
<!-- =========================================================
     GROCERYDELIVERY GLOBAL LOADER
========================================================= -->

<div id="globalLoader" class="gd-loader">

    <div class="gd-loader-content">

        <div class="gd-loader-logo">
            <i class="bi bi-basket2-fill"></i>
        </div>

        <div class="gd-loader-brand">
            Grocery<span>Delivery</span>
        </div>

        <div class="gd-loader-animation">
            <span></span>
            <span></span>
            <span></span>
        </div>

        <div class="gd-loader-text">
            Loading...
        </div>

    </div>

</div>

<style>
/* =========================================================
   GLOBAL LOADER
========================================================= */

.gd-loader {
    position: fixed;
    inset: 0;
    width: 100%;
    height: 100%;
    background: #071827;
    z-index: 999999;

    display: flex;
    align-items: center;
    justify-content: center;

    opacity: 1;
    visibility: visible;

    transition:
        opacity .35s ease,
        visibility .35s ease;
}


/* HIDDEN STATE */

.gd-loader.gd-loader-hidden {
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}


/* CONTENT */

.gd-loader-content {
    text-align: center;
    color: #ffffff;
}


/* LOGO */

.gd-loader-logo {
    width: 82px;
    height: 82px;

    margin: 0 auto 18px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 22px;

    background: #198754;

    font-size: 38px;
    color: #ffffff;

    box-shadow:
        0 15px 40px rgba(25, 135, 84, .25);

    animation: gdLogoPulse 1.5s ease-in-out infinite;
}


/* BRAND */

.gd-loader-brand {
    font-size: 27px;
    font-weight: 700;
    letter-spacing: -.5px;
    margin-bottom: 24px;
}

.gd-loader-brand span {
    color: #20c997;
}


/* DOT CONTAINER */

.gd-loader-animation {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
}


/* DOTS */

.gd-loader-animation span {
    width: 9px;
    height: 9px;

    display: block;

    border-radius: 50%;

    background: #20c997;

    animation: gdLoaderBounce 1.2s infinite ease-in-out;
}

.gd-loader-animation span:nth-child(1) {
    animation-delay: 0s;
}

.gd-loader-animation span:nth-child(2) {
    animation-delay: .15s;
}

.gd-loader-animation span:nth-child(3) {
    animation-delay: .30s;
}


/* TEXT */

.gd-loader-text {
    margin-top: 15px;

    color: rgba(255, 255, 255, .60);

    font-size: 13px;

    letter-spacing: .7px;
}


/* LOGO ANIMATION */

@keyframes gdLogoPulse {

    0%,
    100% {
        transform: scale(1);
        box-shadow:
            0 15px 40px rgba(25, 135, 84, .22);
    }

    50% {
        transform: scale(1.07);
        box-shadow:
            0 18px 50px rgba(25, 135, 84, .40);
    }
}


/* DOT ANIMATION */

@keyframes gdLoaderBounce {

    0%,
    80%,
    100% {
        transform: scale(.6);
        opacity: .4;
    }

    40% {
        transform: scale(1);
        opacity: 1;
    }
}


/* REDUCED MOTION */

@media (prefers-reduced-motion: reduce) {

    .gd-loader-logo,
    .gd-loader-animation span {
        animation: none;
    }

}
</style>


<script>
(function () {

    const loader = document.getElementById('globalLoader');

    if (!loader) {
        return;
    }


    /* =========================================================
       SHOW LOADER
    ========================================================= */

    window.showGlobalLoader = function () {

        loader.classList.remove('gd-loader-hidden');

    };


    /* =========================================================
       HIDE LOADER
    ========================================================= */

    window.hideGlobalLoader = function () {

        loader.classList.add('gd-loader-hidden');

    };


    /* =========================================================
       PAGE FINISHED LOADING
    ========================================================= */

    window.addEventListener('load', function () {

        /*
         * Small delay prevents the loader from flashing too
         * quickly on very fast pages.
         */

        setTimeout(function () {

            hideGlobalLoader();

        }, 1000);

    });


    /* =========================================================
       BROWSER BACK/FORWARD CACHE
    ========================================================= */

    window.addEventListener('pageshow', function () {

        /*
         * Without this, the loader can remain visible when
         * returning to a cached page using the browser Back
         * button.
         */

        hideGlobalLoader();

    });


    /* =========================================================
       INTERNAL LINK NAVIGATION
    ========================================================= */

    document.addEventListener('click', function (event) {

        const link = event.target.closest('a');

        if (!link) {
            return;
        }


        /*
         * Links explicitly marked to ignore the loader.
         */

        if (link.hasAttribute('data-no-loader')) {
            return;
        }


        const href = link.getAttribute('href');

        if (!href) {
            return;
        }


        /*
         * Ignore page anchors.
         */

        if (href.startsWith('#')) {
            return;
        }


        /*
         * Ignore JavaScript links.
         */

        if (href.toLowerCase().startsWith('javascript:')) {
            return;
        }


        /*
         * Ignore telephone links.
         */

        if (href.toLowerCase().startsWith('tel:')) {
            return;
        }


        /*
         * Ignore email links.
         */

        if (href.toLowerCase().startsWith('mailto:')) {
            return;
        }


        /*
         * Ignore links opening another tab/window.
         */

        if (link.target === '_blank') {
            return;
        }


        /*
         * Ignore download links.
         */

        if (link.hasAttribute('download')) {
            return;
        }


        /*
         * Ignore Bootstrap controls such as modal buttons.
         */

        if (
            link.hasAttribute('data-bs-toggle') ||
            link.hasAttribute('data-bs-dismiss')
        ) {
            return;
        }


        /*
         * Only show for same-origin navigation.
         */

        try {

            const destination = new URL(
                link.href,
                window.location.href
            );

            if (destination.origin !== window.location.origin) {
                return;
            }

        } catch (error) {

            return;

        }


        showGlobalLoader();

    });


    /* =========================================================
       FORM SUBMISSION
    ========================================================= */

    document.addEventListener('submit', function (event) {

        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }


        /*
         * Forms can opt out:
         *
         * <form data-no-loader>
         */

        if (form.hasAttribute('data-no-loader')) {
            return;
        }


        /*
         * Do not show loader when browser validation fails.
         */

        if (!form.checkValidity()) {
            return;
        }


        showGlobalLoader();

    });


    /* =========================================================
       SAFETY FALLBACK
    ========================================================= */

    /*
     * If an unexpected JavaScript/network problem occurs,
     * don't leave the user staring at the loader forever.
     */

    setTimeout(function () {

        hideGlobalLoader();

    }, 10000);

})();


    /* THEME TOGGLE */
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('gd-theme', theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(function(btn){
            var dark = theme === 'dark';
            var icon = btn.querySelector('.theme-icon');
            var label = btn.querySelector('.theme-label');
            var state = btn.querySelector('.theme-state');
            if(icon){ icon.className = dark ? 'bi bi-sun-fill theme-icon' : 'bi bi-moon-stars-fill theme-icon'; }
            if(label){ label.textContent = dark ? 'Light Theme' : 'Dark Theme'; }
            if(state){ state.textContent = dark ? 'On' : 'Off'; }
        });
    }
    document.addEventListener('DOMContentLoaded', function(){
        applyTheme(localStorage.getItem('gd-theme') || 'light');
        document.querySelectorAll('[data-theme-toggle]').forEach(function(btn){
            btn.addEventListener('click', function(){
                applyTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            });
        });
    });
</script>