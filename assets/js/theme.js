(function () {
    'use strict';

    const THEME_KEY = 'gd-theme';
    const ACCENT_KEY = 'gd-accent-theme';

    const allowedThemes = ['light', 'dark'];
    const allowedAccents = ['sapphire', 'teal', 'coral', 'slate'];

    function storageGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            // Theme still works for the current page even if storage is blocked.
        }
    }

    function preferredTheme() {
        const saved = storageGet(THEME_KEY);

        if (allowedThemes.includes(saved)) {
            return saved;
        }

        return window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function preferredAccent() {
        const saved = storageGet(ACCENT_KEY);

        return allowedAccents.includes(saved)
            ? saved
            : 'sapphire';
    }

    function updateThemeControls(theme) {
        const isDark = theme === 'dark';

        document
            .querySelectorAll('[data-theme-toggle]')
            .forEach(function (button) {
                const icon =
                    button.querySelector('.theme-icon, i');

                const label =
                    button.querySelector(
                        '.theme-label, [data-theme-label]'
                    );

                const state =
                    button.querySelector('.theme-state');

                if (icon) {
                    icon.className =
                        isDark
                        ? 'bi bi-sun-fill theme-icon'
                        : 'bi bi-moon-stars-fill theme-icon';
                }

                if (label) {
                    label.textContent =
                        isDark
                        ? 'Light Theme'
                        : 'Dark Theme';
                }

                if (state) {
                    state.textContent =
                        isDark
                        ? 'On'
                        : 'Off';
                }

                button.setAttribute(
                    'aria-pressed',
                    isDark ? 'true' : 'false'
                );

                button.setAttribute(
                    'title',
                    isDark
                        ? 'Switch to light mode'
                        : 'Switch to dark mode'
                );
            });
    }

    function applyTheme(theme) {
        const safeTheme =
            allowedThemes.includes(theme)
            ? theme
            : 'light';

        document.documentElement.dataset.theme =
            safeTheme;

        if (document.body) {
            document.body.dataset.theme =
                safeTheme;
        }

        storageSet(
            THEME_KEY,
            safeTheme
        );

        updateThemeControls(
            safeTheme
        );
    }

    function applyAccent(accent) {
        const safeAccent =
            allowedAccents.includes(accent)
            ? accent
            : 'sapphire';

        document.documentElement.dataset.accentTheme =
            safeAccent;

        if (document.body) {
            document.body.dataset.accentTheme =
                safeAccent;
        }

        storageSet(
            ACCENT_KEY,
            safeAccent
        );

        document
            .querySelectorAll('[data-accent-option]')
            .forEach(function (button) {
                const selected =
                    button.dataset.accentOption
                    === safeAccent;

                button.classList.toggle(
                    'active',
                    selected
                );

                button.setAttribute(
                    'aria-pressed',
                    selected
                        ? 'true'
                        : 'false'
                );
            });

        document
            .querySelectorAll('[data-accent-label]')
            .forEach(function (element) {
                const labels = {
                    sapphire: 'Sapphire',
                    teal: 'Teal',
                    coral: 'Coral',
                    slate: 'Slate Grey'
                };

                element.textContent =
                    labels[safeAccent]
                    || 'Sapphire';
            });
    }

    function initializeThemeSystem() {
        applyTheme(
            preferredTheme()
        );

        applyAccent(
            preferredAccent()
        );
    }

    /*
     * Event delegation makes the controls work even when
     * a page renders buttons after this script is loaded.
     */
    document.addEventListener(
        'click',
        function (event) {
            const themeButton =
                event.target.closest(
                    '[data-theme-toggle]'
                );

            if (themeButton) {
                event.preventDefault();

                const current =
                    document.documentElement
                        .dataset.theme
                    || preferredTheme();

                applyTheme(
                    current === 'dark'
                        ? 'light'
                        : 'dark'
                );

                return;
            }

            const accentButton =
                event.target.closest(
                    '[data-accent-option]'
                );

            if (accentButton) {
                event.preventDefault();

                applyAccent(
                    accentButton.dataset.accentOption
                );
            }
        }
    );

    /*
     * Apply once immediately for pages that load this
     * script near the end of the document, then refresh
     * controls after DOM parsing is complete.
     */
    initializeThemeSystem();

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeThemeSystem,
            { once: true }
        );
    }
})();
