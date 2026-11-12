<script>
    window.appTheme = (() => {
        const allowedThemes = ['light', 'dark', 'system'];
        const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
        let preference = 'system';

        try {
            const savedTheme = localStorage.getItem('app-theme');
            if (allowedThemes.includes(savedTheme)) {
                preference = savedTheme;
            }
        } catch {}

        const applyTheme = () => {
            const isDark = preference === 'dark' || (preference === 'system' && systemTheme.matches);
            document.documentElement.classList.toggle('dark', isDark);
            document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
        };

        applyTheme();
        systemTheme.addEventListener('change', applyTheme);

        return {
            get preference() {
                return preference;
            },
            set(value) {
                if (!allowedThemes.includes(value)) {
                    return;
                }

                preference = value;
                try {
                    localStorage.setItem('app-theme', value);
                } catch {}

                applyTheme();
                window.dispatchEvent(new CustomEvent('app-theme-changed', { detail: value }));
            },
        };
    })();
</script>
