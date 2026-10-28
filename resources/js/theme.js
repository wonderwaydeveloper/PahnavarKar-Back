import '../css/app.css';

const storageKey = 'pahnavarkar-theme';
const validPreferences = ['light', 'dark', 'system'];

export function getThemePreference() {
    const preference = window.localStorage.getItem(storageKey);

    return validPreferences.includes(preference) ? preference : 'system';
}

function resolveTheme(preference) {
    if (preference !== 'system') {
        return preference;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(preference) {
    const root = document.documentElement;
    root.dataset.themePreference = preference;
    const theme = resolveTheme(preference);
    root.dataset.theme = theme;
    document.querySelector('meta[name="theme-color"]')?.setAttribute(
        'content',
        theme === 'dark' ? '#041E28' : '#EFF8FA',
    );
}

export function setThemePreference(preference) {
    if (!validPreferences.includes(preference)) {
        throw new Error(`Unsupported theme preference: ${preference}`);
    }

    applyTheme(preference);
    window.localStorage.setItem(storageKey, preference);
    syncSwitchers(preference);
}

function syncSwitchers(preference = document.documentElement.dataset.themePreference) {
    document.querySelectorAll('[data-theme-switcher]').forEach((switcher) => {
        switcher.value = preference;
    });
}

function initializeTheme() {
    const root = document.documentElement;
    const applyPreference = (preference) => {
        const validPreference = validPreferences.includes(preference) ? preference : 'system';
        applyTheme(validPreference);
    };

    applyPreference(getThemePreference());

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (root.dataset.themePreference === 'system') {
            applyTheme('system');
        }
    });

    window.addEventListener('storage', (event) => {
        if (event.key === storageKey) {
            applyPreference(event.newValue);
            document.querySelectorAll('[data-theme-switcher]').forEach((switcher) => {
                switcher.value = root.dataset.themePreference;
            });
        }
    });

    const syncSwitchers = () => {
        document.querySelectorAll('[data-theme-switcher]').forEach((switcher) => {
            switcher.value = root.dataset.themePreference;
        });
    };

    document.addEventListener('DOMContentLoaded', syncSwitchers);
    document.addEventListener('change', (event) => {
        if (event.target instanceof HTMLSelectElement && event.target.matches('[data-theme-switcher]')) {
            setThemePreference(event.target.value);
        }
    });
    syncSwitchers();
}

function initializeMobileNavigation() {
    const toggle = document.querySelector('[data-mobile-menu-toggle]');
    const menuId = toggle?.getAttribute('aria-controls');
    const menu = menuId ? document.getElementById(menuId) : null;

    if (!(toggle instanceof HTMLButtonElement) || !menu) {
        return;
    }

    const openIcon = toggle.querySelector('[data-menu-open-icon]');
    const closeIcon = toggle.querySelector('[data-menu-close-icon]');
    const desktopNavigation = window.matchMedia('(min-width: 768px)');

    const setMenuOpen = (isOpen, restoreFocus = false) => {
        menu.hidden = !isOpen;
        menu.classList.toggle('hidden', !isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'بستن منو' : 'باز کردن منو');
        openIcon?.classList.toggle('hidden', isOpen);
        closeIcon?.classList.toggle('hidden', !isOpen);

        if (restoreFocus) {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        setMenuOpen(toggle.getAttribute('aria-expanded') !== 'true');
    });

    menu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => setMenuOpen(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setMenuOpen(false, true);
        }
    });

    document.addEventListener('click', (event) => {
        if (
            toggle.getAttribute('aria-expanded') === 'true'
            && event.target instanceof Node
            && !toggle.contains(event.target)
            && !menu.contains(event.target)
        ) {
            setMenuOpen(false);
        }
    });

    const closeForDesktop = () => {
        if (desktopNavigation.matches) {
            setMenuOpen(false);
        }
    };

    desktopNavigation.addEventListener('change', closeForDesktop);
    window.addEventListener('resize', closeForDesktop);
}

initializeTheme();
initializeMobileNavigation();
