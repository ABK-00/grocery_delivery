(function () {
    'use strict';

    const THEME_KEY = 'gd-theme';
    const ACCENT_KEY = 'gd-accent-theme';

    const allowedThemes = ['light', 'dark'];
    const allowedAccents = ['sapphire', 'teal', 'coral', 'slate'];

    function storageGet(key) {
        try {
            return localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function storageSet(key, value) {
        try {
            localStorage.setItem(key, value);
        } catch (error) {
            // Ignore storage failures; current-page theming still works.
        }
    }

    function getTheme() {
        const saved = storageGet(THEME_KEY);

        if (allowedThemes.includes(saved)) {
            return saved;
        }

        return window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function getAccent() {
        const saved = storageGet(ACCENT_KEY);

        return allowedAccents.includes(saved)
            ? saved
            : 'sapphire';
    }

    function refreshThemeControls(theme) {
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

        document.documentElement.setAttribute(
            'data-theme',
            safeTheme
        );

        if (document.body) {
            document.body.setAttribute(
                'data-theme',
                safeTheme
            );
        }

        storageSet(
            THEME_KEY,
            safeTheme
        );

        refreshThemeControls(
            safeTheme
        );
    }

    function toggleTheme() {
        const current =
            document.documentElement.getAttribute(
                'data-theme'
            )
            || getTheme();

        applyTheme(
            current === 'dark'
                ? 'light'
                : 'dark'
        );
    }

    function applyAccent(accent) {
        const safeAccent =
            allowedAccents.includes(accent)
            ? accent
            : 'sapphire';

        document.documentElement.setAttribute(
            'data-accent-theme',
            safeAccent
        );

        if (document.body) {
            document.body.setAttribute(
                'data-accent-theme',
                safeAccent
            );
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

    function bindControls() {
        document
            .querySelectorAll('[data-theme-toggle]')
            .forEach(function (button) {

                if (button.dataset.themeBound === '1') {
                    return;
                }

                button.dataset.themeBound = '1';

                button.addEventListener(
                    'click',
                    function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        toggleTheme();
                    }
                );
            });

        document
            .querySelectorAll('[data-accent-option]')
            .forEach(function (button) {

                if (button.dataset.accentBound === '1') {
                    return;
                }

                button.dataset.accentBound = '1';

                button.addEventListener(
                    'click',
                    function (event) {
                        event.preventDefault();

                        applyAccent(
                            button.dataset.accentOption
                        );
                    }
                );
            });
    }

    function initialize() {
        applyTheme(
            getTheme()
        );

        applyAccent(
            getAccent()
        );

        bindControls();
    }

    window.gdApplyTheme = applyTheme;
    window.gdToggleTheme = toggleTheme;
    window.gdApplyAccent = applyAccent;

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initialize,
            { once: true }
        );
    } else {
        initialize();
    }
})();
