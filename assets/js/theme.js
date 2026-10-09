(function () {
    const STORAGE_KEY = 'gd-theme';

    function preferredTheme() {
        const saved = localStorage.getItem(STORAGE_KEY);

        if (saved === 'light' || saved === 'dark') {
            return saved;
        }

        return window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches
            ? 'dark'
            : 'light';
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute(
            'data-theme',
            theme
        );

        localStorage.setItem(
            STORAGE_KEY,
            theme
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
                    theme === 'dark';

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
            });
    }

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            applyTheme(
                preferredTheme()
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
        }
    );
})();
