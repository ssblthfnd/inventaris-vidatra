import { useEffect, useState } from 'react';

import { currentTheme, saveTheme, watchTheme } from '../lib/theme';

function SunIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
    </svg>
  );
}

/**
 * Small light/dark switch fixed to the bottom-right corner of every page
 * (docs/mockups/README.md). Mounted once in app.jsx. The moon invites switching
 * to dark, the sun to light; the label appears on hover/focus. z-40 keeps it
 * below modals and the photo viewer (z-50), so it never covers their buttons.
 */
export default function ThemeToggle() {
  const [theme, setTheme] = useState(currentTheme);

  useEffect(() => watchTheme(setTheme), []);

  const dark = theme === 'dark';
  const label = dark ? 'Ganti ke mode terang' : 'Ganti ke mode gelap';

  const toggle = () => {
    const next = dark ? 'light' : 'dark';
    saveTheme(next);
    setTheme(next);
  };

  return (
    <div className="group fixed right-4 bottom-4 z-40 flex items-center gap-2 print:hidden">
      <span
        aria-hidden="true"
        className="pointer-events-none translate-x-1.5 rounded-full bg-accent px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-accent-fg opacity-0 transition duration-150 group-focus-within:translate-x-0 group-focus-within:opacity-100 group-hover:translate-x-0 group-hover:opacity-100 motion-reduce:transition-none"
      >
        {label}
      </span>
      <button
        type="button"
        onClick={toggle}
        aria-label={label}
        className="flex h-10 w-10 cursor-pointer items-center justify-center rounded-full border border-border bg-surface text-ink opacity-72 shadow-[0_4px_12px_rgba(0,0,0,0.15)] transition-opacity duration-150 group-hover:opacity-100 focus-visible:opacity-100 focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 focus-visible:outline-none motion-reduce:transition-none"
      >
        {dark ? <SunIcon /> : <MoonIcon />}
      </button>
    </div>
  );
}
