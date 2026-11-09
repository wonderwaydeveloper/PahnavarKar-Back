<script>
    (() => {
        const key = 'pahnavarkar-theme';
        const saved = window.localStorage.getItem(key);
        const preference = ['light', 'dark', 'system'].includes(saved) ? saved : 'system';
        const isDark = preference === 'dark'
            || (preference === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);

        document.documentElement.dataset.themePreference = preference;
        document.documentElement.dataset.theme = isDark ? 'dark' : 'light';
        document.querySelector('meta[name="theme-color"]')?.setAttribute(
            'content',
            isDark ? '#191C1D' : '#FBFCFD',
        );
    })();
</script>
