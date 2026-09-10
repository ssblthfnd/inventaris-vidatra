import { Link } from 'react-router-dom';

/**
 * The Inventaris result table. Six columns chosen so a first-time user can recognise
 * an asset without a spreadsheet's worth of columns. The asset code is a link to the
 * detail page (Tahap 5.8.2); `listSearch` is carried in navigation state so "Kembali"
 * restores the exact filtered list.
 */

const CONDITION = {
  baik: { label: 'Baik', dot: 'bg-emerald-500' },
  kurang_baik: { label: 'Kurang Baik', dot: 'bg-amber-500' },
  rusak_berat: { label: 'Rusak Berat', dot: 'bg-red-500' },
};

function ConditionCell({ value }) {
  const meta = CONDITION[value] ?? { label: 'Tidak diketahui', dot: 'bg-gray-300' };
  return (
    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
      <span className={`h-2 w-2 rounded-full ${meta.dot}`} aria-hidden="true" />
      <span className="text-gray-700">{meta.label}</span>
    </span>
  );
}

function StatusCell({ writtenOff }) {
  if (writtenOff) {
    return (
      <span className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-200">
        Written-off
      </span>
    );
  }
  return <span className="text-gray-500">Aktif</span>;
}

function SkeletonRows({ rows = 8 }) {
  return Array.from({ length: rows }).map((_, i) => (
    <tr key={i} className="border-t border-gray-100">
      {Array.from({ length: 6 }).map((__, j) => (
        <td key={j} className="px-4 py-3">
          <span className="block h-3 w-full max-w-[8rem] animate-pulse rounded bg-gray-100" />
        </td>
      ))}
    </tr>
  ));
}

export default function InventoryTable({ assets, loading, refreshing, listSearch = '' }) {
  return (
    <div className="overflow-hidden rounded-xl border border-gray-200 bg-white">
      <div className="overflow-x-auto">
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr className="text-left text-xs font-medium uppercase tracking-wide text-gray-400">
              <th className="px-4 py-3">Kode Aset</th>
              <th className="px-4 py-3">Aset</th>
              <th className="px-4 py-3">Kategori</th>
              <th className="px-4 py-3">Ruangan</th>
              <th className="px-4 py-3">Kondisi</th>
              <th className="px-4 py-3">Status</th>
            </tr>
          </thead>
          <tbody
            className={refreshing ? 'opacity-60 transition-opacity' : 'transition-opacity'}
          >
            {loading ? (
              <SkeletonRows />
            ) : (
              assets.map((asset) => (
                <tr key={asset.id} className="border-t border-gray-100 hover:bg-gray-50/60">
                  <td className="px-4 py-3">
                    <Link
                      to={`/inventory/${asset.id}`}
                      state={{ from: listSearch }}
                      aria-label={`Lihat detail aset ${asset.asset_code}`}
                      className="rounded font-mono text-[13px] font-medium text-gray-900 underline-offset-2 hover:text-gray-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-gray-900"
                    >
                      {asset.asset_code}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    <span className="block font-medium text-gray-900">
                      {asset.subcategory?.name ?? '—'}
                    </span>
                    {asset.brand_model && (
                      <span className="block text-xs text-gray-500">{asset.brand_model}</span>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-700">{asset.category?.name ?? '—'}</td>
                  <td className="px-4 py-3">
                    {asset.room ? (
                      <>
                        <span className="block text-gray-700">{asset.room.name}</span>
                        <span className="block text-xs text-gray-400">
                          {asset.location?.name}
                        </span>
                      </>
                    ) : (
                      <span className="text-gray-400">Belum dipetakan</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <ConditionCell value={asset.condition} />
                  </td>
                  <td className="px-4 py-3">
                    <StatusCell writtenOff={asset.is_written_off} />
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
