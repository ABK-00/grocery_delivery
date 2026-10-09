(function () {
    const THEME_KEY = 'gd-theme';
    const ACCENT_KEY = 'gd-accent-theme';

    const allowedThemes = ['light', 'dark'];
    const allowedAccents = ['sapphire', 'teal', 'coral', 'slate'];

    function preferredTheme() {
        const saved = localStorage.getItem(THEME_KEY);

        if (allowedThemes.includes(saved)) {
            return saved;
        }

        return window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function preferredAccent() {
        const saved = localStorage.getItem(ACCENT_KEY);

        return allowedAccents.includes(saved)
            ? saved
            : 'sapphire';
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

        localStorage.setItem(
            THEME_KEY,
            safeTheme
        );

        document
            .querySelectorAll('[data-theme-toggle]')
            .forEach(function (button) {
                const icon =
                    button.querySelector(
                        '.theme-icon, i'
                    );

                const label =
                    button.querySelector(
                        '.theme-label, [data-theme-label]'
                    );

                const state =
                    button.querySelector(
                        '.theme-state'
                    );

                const isDark =
                    safeTheme === 'dark';

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
                    isDark
                        ? 'true'
                        : 'false'
                );

                button.setAttribute(
                    'title',
                    isDark
                        ? 'Switch to light mode'
                        : 'Switch to dark mode'
                );
            });
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

        localStorage.setItem(
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

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            applyTheme(
                preferredTheme()
            );

            applyAccent(
                preferredAccent()
            );

            document
                .querySelectorAll('[data-theme-toggle]')
                .forEach(function (button) {

                    button.addEventListener(
                        'click',
                        function () {

                            const current =
                                document.documentElement
                                    .getAttribute(
                                        'data-theme'
                                    )
                                || 'light';

                            applyTheme(
                                current === 'dark'
                                ? 'light'
                                : 'dark'
                            );
                        }
                    );
                });

            document
                .querySelectorAll('[data-accent-option]')
                .forEach(function (button) {

                    button.addEventListener(
                        'click',
                        function () {

                            applyAccent(
                                button.dataset.accentOption
                            );
                        }
                    );
                });
        }
    );
})();
