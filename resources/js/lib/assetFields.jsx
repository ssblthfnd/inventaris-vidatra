import { Link } from 'react-router-dom';

/**
 * Shared building blocks for the asset forms (Tahap 5.8.3 single create/edit,
 * Tahap 5.8.4 batch create). Presentational primitives + the constants that must
 * stay identical across every asset form. No business logic lives here.
 */

export const MAX_YEAR = new Date().getFullYear() + 1;

export const CONDITION_CHOICES = [
  { value: '', label: 'Belum diisi' },
  { value: 'baik', label: 'Baik' },
  { value: 'kurang_baik', label: 'Kurang Baik' },
  { value: 'rusak_berat', label: 'Rusak Berat' },
];

/** Client-side max lengths — mirror the backend `StoreAssetRequest` rules. */
export const MAX_LEN = {
  brand_model: 150,
  serial_no: 150,
  material: 80,
  detail_type: 100,
  capacity_note: 100,
  funding_source: 80,
};

export const trimOrNull = (v) => {
  const s = (v ?? '').trim();
  return s === '' ? null : s;
};

export const controlClass = (error) =>
  [
    'w-full rounded-lg border px-3 py-2 text-sm outline-none transition-colors',
    'focus:ring-1',
    error
      ? 'border-red-400 focus:border-red-500 focus:ring-red-500'
      : 'border-gray-300 focus:border-gray-900 focus:ring-gray-900',
    'disabled:bg-gray-50 disabled:text-gray-500',
  ].join(' ');

export function Field({ label, htmlFor, required, error, hint, children }) {
  return (
    <div>
      <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium text-gray-700">
        {label}
        {required && <span className="text-red-600"> *</span>}
      </label>
      {children}
      {hint && !error && <p className="mt-1 text-xs text-gray-400">{hint}</p>}
      {error && (
        <p className="mt-1 text-xs text-red-600" role="alert">
          {error}
        </p>
      )}
    </div>
  );
}

export function TextInput({ id, value, onChange, error, disabled, ...rest }) {
  return (
    <input
      id={id}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
      className={controlClass(error)}
      {...rest}
    />
  );
}

export function SelectInput({ id, value, onChange, error, disabled, children }) {
  return (
    <select
      id={id}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
      className={controlClass(error)}
    >
      {children}
    </select>
  );
}

export function Section({ title, description, children }) {
  return (
    <section className="rounded-xl border border-gray-200 bg-white p-4 sm:p-5">
      <h2 className="text-sm font-semibold text-gray-800">{title}</h2>
      {description && <p className="mt-0.5 text-xs text-gray-500">{description}</p>}
      <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</div>
    </section>
  );
}

export const FullWidth = ({ children }) => <div className="sm:col-span-2">{children}</div>;

export function FormSkeleton() {
  return (
    <div className="mx-auto max-w-3xl space-y-6" aria-hidden="true">
      <div className="h-4 w-40 animate-pulse rounded bg-gray-100" />
      <div className="h-7 w-56 animate-pulse rounded bg-gray-100" />
      {[0, 1, 2].map((i) => (
        <div key={i} className="rounded-xl border border-gray-200 bg-white p-5">
          <div className="mb-4 h-4 w-32 animate-pulse rounded bg-gray-100" />
          <div className="grid gap-4 sm:grid-cols-2">
            {[0, 1, 2, 3].map((j) => (
              <div key={j} className="h-9 animate-pulse rounded bg-gray-100" />
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

export function CenteredState({ title, message, backTo, backLabel, action }) {
  return (
    <div className="mx-auto max-w-2xl">
      <div className="rounded-xl border border-gray-200 bg-white px-6 py-14 text-center">
        <h1 className="text-base font-semibold text-gray-900">{title}</h1>
        <p className="mx-auto mt-1.5 max-w-sm text-sm text-gray-500">{message}</p>
        {action}
        {backTo && (
          <Link
            to={backTo}
            className="mt-4 inline-block rounded-md border border-gray-300 px-3.5 py-2 text-sm font-medium text-gray-700 hover:border-gray-400"
          >
            {backLabel}
          </Link>
        )}
      </div>
    </div>
  );
}
