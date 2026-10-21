/**
 * Light/dark theme state. The initial `data-theme` on <html> is set by
 * `public/theme-init.js` before React renders (same key and fallback as here);
 * this module only reads and changes it afterwards.
 *
 * A stored choice wins; with no stored choice the page follows the system
 * `prefers-color-scheme`, including when it changes while the page is open.
 */

export const THEME_STORAGE_KEY = 'inventaris-theme';

const SYSTEM_DARK = '(prefers-color-scheme: dark)';

const isTheme = (value) => value === 'light' || value === 'dark';

export function currentTheme() {
  return document.documentElement.dataset.theme === 'dark' ? 'dark' : 'light';
}

/** The user's saved choice, or null when none (or storage is unavailable). */
export function storedTheme() {
  try {
    const value = window.localStorage.getItem(THEME_STORAGE_KEY);
    return isTheme(value) ? value : null;
  } catch {
    return null;
  }
}

export function systemTheme() {
  return window.matchMedia?.(SYSTEM_DARK).matches ? 'dark' : 'light';
}

export function applyTheme(theme) {
  document.documentElement.dataset.theme = theme;
}

/** Apply and remember an explicit choice (remembering is best-effort). */
export function saveTheme(theme) {
  applyTheme(theme);
  try {
    window.localStorage.setItem(THEME_STORAGE_KEY, theme);
  } catch {
    // storage unavailable: the choice lasts for this page only
  }
}

/**
 * Keep the theme in sync with things that happen outside this tab's toggle: the
 * system setting changing (only while nothing is stored) and another tab saving a
 * choice. `onChange(theme)` is called after the theme has been applied.
 *
 * @returns {() => void} unsubscribe
 */
export function watchTheme(onChange) {
  const media = window.matchMedia?.(SYSTEM_DARK);
  const onSystem = () => {
    if (storedTheme() !== null) return;
    applyTheme(systemTheme());
    onChange(currentTheme());
  };
  const onStorage = (event) => {
    if (event.key !== THEME_STORAGE_KEY) return;
    applyTheme(isTheme(event.newValue) ? event.newValue : systemTheme());
    onChange(currentTheme());
  };

  media?.addEventListener('change', onSystem);
  window.addEventListener('storage', onStorage);

  return () => {
    media?.removeEventListener('change', onSystem);
    window.removeEventListener('storage', onStorage);
  };
}
