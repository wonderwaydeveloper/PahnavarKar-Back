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
        theme === 'dark' ? '#191C1D' : '#FBFCFD',
    );
}

export function setThemePreference(preference) {
    if (!validPreferences.includes(preference)) {
        throw new Error(`Unsupported theme preference: ${preference}`);
    }

    applyTheme(preference);
    window.localStorage.setItem(storageKey, preference);
    syncThemeSwitcherControls(preference);
}

function syncThemeSwitcherControls(preference = document.documentElement.dataset.themePreference) {
    document.querySelectorAll('[data-theme-switcher]').forEach((switcher) => {
        switcher.checked = switcher.value === preference;
    });

    document.querySelectorAll('.theme-switcher-control').forEach((switcher) => {
        const selectedRadio = switcher.querySelector('[data-theme-switcher]:checked');
        const selectedOption = selectedRadio?.nextElementSibling?.querySelector('[data-theme-option-label]');
        const label = switcher.querySelector('[data-theme-label]');
        const icon = switcher.querySelector('[data-theme-current-icon]');

        if (selectedOption && label) {
            label.textContent = selectedOption.textContent;
        }

        if (selectedRadio instanceof HTMLInputElement && icon instanceof HTMLElement) {
            icon.className = `fa-solid ${selectedRadio.dataset.themeIcon} ${selectedRadio.dataset.themeIconColor}`;
        }
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
            syncThemeSwitcherControls(root.dataset.themePreference);
        }
    });

    const syncSwitchers = () => {
        syncThemeSwitcherControls(root.dataset.themePreference);
    };

    document.addEventListener('DOMContentLoaded', syncSwitchers);
    document.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.matches('input[type="radio"][data-theme-switcher]')) {
            setThemePreference(event.target.value);
        }
    });
    document.addEventListener('click', (event) => {
        const option = event.target instanceof Element
            ? event.target.closest('.theme-switcher-option')
            : null;
        const switcher = option?.closest('.theme-switcher-control');

        if (event.detail > 0 && switcher instanceof HTMLDetailsElement && switcher.open) {
            switcher.open = false;
            switcher.querySelector('summary')?.focus();
        }

        document.querySelectorAll('.theme-switcher-control[open]').forEach((switcher) => {
            if (event.target instanceof Node && !switcher.contains(event.target)) {
                switcher.open = false;
            }
        });
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        document.querySelectorAll('.theme-switcher-control[open]').forEach((switcher) => {
            switcher.open = false;
            switcher.querySelector('summary')?.focus();
        });
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

    const menuIcon = toggle.querySelector('[data-menu-icon]');
    const desktopNavigation = window.matchMedia('(min-width: 768px)');

    const setMenuOpen = (isOpen, restoreFocus = false) => {
        menu.hidden = !isOpen;
        menu.classList.toggle('hidden', !isOpen);
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'بستن منو' : 'باز کردن منو');
        menuIcon?.classList.toggle('fa-bars', !isOpen);
        menuIcon?.classList.toggle('fa-xmark', isOpen);

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

function initializePublicSectionNavigation() {
    const links = Array.from(document.querySelectorAll('[data-public-nav-section]'));

    if (links.length === 0) {
        return;
    }

    const syncCurrentSection = () => {
        const currentUrl = new URL(window.location.href);

        links.forEach((link) => {
            const targetUrl = new URL(link.dataset.publicNavTarget || link.href, currentUrl);
            const section = link.dataset.publicNavSection;
            const dropdown = link.closest('[data-public-nav-group]');
            const isHome = section === 'home'
                && currentUrl.pathname === targetUrl.pathname
                && (currentUrl.hash === '' || currentUrl.hash === '#home');
            const isCurrentGroup = dropdown
                && Array.from(dropdown.querySelectorAll('a[href]')).some((childLink) => {
                    const childUrl = new URL(childLink.href, currentUrl);

                    return currentUrl.pathname === childUrl.pathname
                        && (childUrl.hash === '' || currentUrl.hash === childUrl.hash);
                });
            const isCurrent = (currentUrl.pathname === targetUrl.pathname
                && (isHome || currentUrl.hash === targetUrl.hash))
                || isCurrentGroup;
            const activeClasses = link.dataset.activeClasses.split(' ');
            const inactiveClasses = link.dataset.inactiveClasses.split(' ');

            activeClasses.forEach((className) => link.classList.toggle(className, isCurrent));
            inactiveClasses.forEach((className) => link.classList.toggle(className, !isCurrent));

            if (isCurrent) {
                link.setAttribute('aria-current', 'location');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    syncCurrentSection();
    window.addEventListener('hashchange', syncCurrentSection);
    window.addEventListener('popstate', syncCurrentSection);
}

function initializeNavigationDropdowns() {
    const dropdowns = Array.from(document.querySelectorAll('[data-nav-dropdown]'));

    if (dropdowns.length === 0) {
        return;
    }

    document.addEventListener('click', (event) => {
        dropdowns.forEach((dropdown) => {
            if (dropdown.open && event.target instanceof Node && !dropdown.contains(event.target)) {
                dropdown.open = false;
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        dropdowns.forEach((dropdown) => {
            if (dropdown.open) {
                dropdown.open = false;
                dropdown.querySelector('summary')?.focus();
            }
        });
    });
}

initializeTheme();
initializeMobileNavigation();
initializePublicSectionNavigation();
initializeNavigationDropdowns();
