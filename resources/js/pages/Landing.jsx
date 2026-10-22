import { useEffect } from 'react';
import { Link } from 'react-router-dom';

import '@fontsource/plus-jakarta-sans/latin-400.css';
import '@fontsource/plus-jakarta-sans/latin-500.css';
import '@fontsource/plus-jakarta-sans/latin-600.css';
import '@fontsource/plus-jakarta-sans/latin-700.css';
import '@fontsource/plus-jakarta-sans/latin-800.css';

/**
 * Public landing page at `/` (Desain A — "Formal & Tenang").
 *
 * Fully static: no API calls and no real inventory data — the asset card in the
 * hero is an illustrative example. Only guests see it; GuestRoute sends a
 * signed-in user straight to /dashboard, as `/` always did before this page.
 * Everything is same-origin (self-hosted font, inline SVG) so the strict CSP in
 * SecurityHeaders needs no change.
 */

const FEATURES = [
  {
    title: 'Label QR',
    text: 'Cetak label berisi kode aset dan QR. Pindai dengan HP untuk langsung membuka data barang.',
    icon: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <path d="M14 14h3v3h-3zM20 14v.01M14 20h.01M17 20h4v-3" />
      </>
    ),
  },
  {
    title: 'Foto Aset',
    text: 'Simpan hingga 3 foto per barang, langsung dari kamera HP. Foto bersifat privat.',
    icon: (
      <>
        <path d="M4 7h3l2-3h6l2 3h3v13H4z" />
        <circle cx="12" cy="13" r="4" />
      </>
    ),
  },
  {
    title: 'Sumber Aset',
    text: 'Kelompokkan aset berdasarkan BOSDA, BOSP, BOSTK, Yayasan, atau sumber lainnya.',
    icon: <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />,
  },
  {
    title: 'Laporan & Excel',
    text: 'Rekap per lokasi, ruangan, kondisi, dan sumber. Unduh ke Excel kapan saja.',
    icon: <path d="M4 20V10M10 20V4M16 20v-7M22 20H2" />,
  },
  {
    title: 'Import Excel',
    text: 'Masukkan ratusan aset sekaligus dari template. Data dicek dulu sebelum masuk.',
    icon: <path d="M12 3v12M7 10l5 5 5-5M4 21h16" />,
  },
  {
    title: 'Riwayat Perubahan',
    text: 'Setiap perpindahan dan perubahan tercatat, lengkap dengan siapa dan kapan.',
    icon: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </>
    ),
  },
];

const STEPS = [
  {
    title: 'Catat aset',
    text: 'Tambah satu per satu, sekaligus banyak, atau lewat import Excel. Kode aset dibuat otomatis.',
  },
  {
    title: 'Tempel label',
    text: 'Cetak label QR dalam ukuran kecil, sedang, atau besar, lalu tempel di barangnya.',
  },
  {
    title: 'Pindai & pantau',
    text: 'Pindai QR untuk melihat detail, foto, dan riwayat barang. Pantau semuanya di laporan.',
  },
];

const PAGE_TITLE = 'Inventaris Vidatra — Yayasan Vidatra';

function LineIcon({ children, className = '' }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      className={className}
    >
      {children}
    </svg>
  );
}

/** Decorative QR pattern for the example card — not a scannable code. */
function QrPattern({ className = '' }) {
  return (
    <svg role="img" aria-label="Contoh kode QR" viewBox="0 0 21 21" shapeRendering="crispEdges" className={className}>
      <rect width="21" height="21" fill="#FFFFFF" />
      <path
        fill="#111827"
        d="M0 0h7v7H0zM1 1v5h5V1zM2 2h3v3H2zM14 0h7v7h-7zM15 1v5h5V1zM16 2h3v3h-3zM0 14h7v7H0zM1 15v5h5v-5zM2 16h3v3H2zM8 0h1v2H8zM10 1h2v1h-2zM9 3h2v2H9zM12 4h1v3h-1zM8 6h2v2H8zM0 8h2v1H0zM3 8h3v1H3zM7 9h2v2H7zM10 8h3v1h-3zM14 8h2v2h-2zM17 9h3v1h-3zM1 10h2v2H1zM4 11h2v1H4zM9 12h3v1H9zM13 11h2v3h-2zM16 12h2v1h-2zM19 11h2v2h-2zM8 14h2v2H8zM11 15h2v1h-2zM15 15h3v2h-3zM19 15h2v1h-2zM8 18h3v1H8zM12 17h2v3h-2zM15 19h2v2h-2zM18 18h3v1h-3zM9 20h2v1H9z"
      />
    </svg>
  );
}

/** Wooden desk illustration used in place of an asset photo. */
function DeskIllustration() {
  return (
    <svg
      role="img"
      aria-label="Ilustrasi meja kayu"
      viewBox="0 0 440 200"
      preserveAspectRatio="xMidYMax slice"
      className="h-full w-full"
    >
      <rect x="0" y="0" width="440" height="150" fill="#EEF2F7" />
      <rect x="0" y="150" width="440" height="50" fill="#DCE3EC" />
      <rect x="300" y="34" width="84" height="64" rx="4" fill="#FFFFFF" stroke="#C7D0DC" strokeWidth="2" />
      <path d="M308 88l20-22 16 14 12-10 20 18z" fill="#C7D0DC" />
      <ellipse cx="220" cy="176" rx="150" ry="8" fill="#C9D2DE" />
      <rect x="92" y="96" width="14" height="78" rx="3" fill="#8A5A32" />
      <rect x="334" y="96" width="14" height="78" rx="3" fill="#8A5A32" />
      <rect x="118" y="96" width="10" height="70" rx="3" fill="#734A28" />
      <rect x="312" y="96" width="10" height="70" rx="3" fill="#734A28" />
      <rect x="230" y="96" width="92" height="40" rx="3" fill="#A06A3C" />
      <rect x="262" y="112" width="28" height="6" rx="3" fill="#6B4224" />
      <rect x="76" y="80" width="288" height="18" rx="4" fill="#B97A45" />
      <rect x="76" y="80" width="288" height="5" rx="2" fill="#D09460" />
      <rect x="130" y="64" width="60" height="16" rx="2" fill="#1E3A8A" />
      <rect x="136" y="56" width="52" height="8" rx="2" fill="#F59E0B" />
      <rect x="206" y="72" width="34" height="8" rx="2" fill="#94A3B8" />
    </svg>
  );
}

function ExampleAssetCard() {
  return (
    <div className="w-full max-w-[440px] overflow-hidden rounded-[20px] border border-gray-200 bg-surface shadow-[0_24px_48px_rgba(17,24,39,0.08)]">
      <div className="h-[200px] bg-[#EEF2F7]">
        <DeskIllustration />
      </div>
      <div className="flex flex-col gap-4 p-6">
        <div className="flex items-start justify-between gap-4">
          <div className="flex min-w-0 flex-col gap-1">
            <span className="font-mono text-sm text-gray-600">01.02.001.0003.2016</span>
            <span className="text-[22px] font-extrabold">MEJA</span>
            <span className="text-sm text-gray-600">Ruangan Pendidikan · YAYASAN</span>
          </div>
          <QrPattern className="h-[72px] w-[72px] shrink-0" />
        </div>
        <div className="flex flex-wrap gap-2">
          <span className="rounded-full bg-emerald-100 px-3 py-1.5 text-[13px] font-bold text-emerald-800">Kondisi: Baik</span>
          <span className="rounded-full bg-indigo-100 px-3 py-1.5 text-[13px] font-bold text-[#1E3A8A] dark:text-indigo-800">Sumber: Yayasan</span>
          <span className="rounded-full bg-gray-100 px-3 py-1.5 text-[13px] font-bold text-gray-700">3 foto</span>
        </div>
      </div>
    </div>
  );
}

const navLink =
  'hidden rounded-lg px-3.5 py-3 sm:inline-block text-[15px] font-semibold text-gray-700 hover:text-[#1E3A8A] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E3A8A] dark:hover:text-accent dark:focus-visible:outline-accent';
const primaryButton =
  'inline-flex items-center justify-center rounded-xl bg-[#1E3A8A] px-7 py-4 text-base font-bold text-white hover:bg-[#172554] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E3A8A] dark:bg-accent dark:text-accent-fg dark:hover:bg-accent-hover dark:focus-visible:outline-accent';

export default function Landing() {
  useEffect(() => {
    const previous = document.title;
    document.title = PAGE_TITLE;
    return () => {
      document.title = previous;
    };
  }, []);

  return (
    <div className="min-h-screen bg-[#F6F7F9] dark:bg-page font-['Plus_Jakarta_Sans',ui-sans-serif,system-ui,sans-serif] text-gray-900">
      <header className="border-b border-gray-200 bg-surface">
        <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-4 px-6 py-[18px]">
          <div className="flex items-center gap-3">
            <div className="flex h-10 w-10 items-center justify-center rounded-[10px] bg-[#1E3A8A] text-white">
              <LineIcon className="h-[22px] w-[22px]">
                <path d="M3 7l9-4 9 4-9 4-9-4z" />
                <path d="M3 7v10l9 4 9-4V7" />
                <path d="M12 11v10" />
              </LineIcon>
            </div>
            <div className="flex flex-col">
              <span className="text-lg font-extrabold tracking-tight">Inventaris Vidatra</span>
              <span className="text-[13px] text-gray-600">Yayasan Vidatra</span>
            </div>
          </div>
          <nav aria-label="Navigasi utama" className="flex flex-wrap items-center gap-2">
            <a href="#fitur" className={navLink}>
              Fitur
            </a>
            <a href="#cara" className={navLink}>
              Cara Kerja
            </a>
            <a href="#bantuan" className={navLink}>
              Bantuan
            </a>
            <Link
              to="/login"
              className="rounded-[10px] bg-[#1E3A8A] px-[22px] py-3 text-[15px] font-bold text-white hover:bg-[#172554] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E3A8A] dark:bg-accent dark:text-accent-fg dark:hover:bg-accent-hover dark:focus-visible:outline-accent"
            >
              Masuk
            </Link>
          </nav>
        </div>
      </header>

      <main>
        <section className="bg-surface">
          <div className="mx-auto flex max-w-[1200px] flex-wrap items-center gap-14 px-6 pt-16 pb-20 sm:pt-20 sm:pb-24">
            <div className="flex min-w-0 flex-[1_1_460px] flex-col gap-6">
              <span className="self-start rounded-full bg-indigo-50 px-3.5 py-2 text-sm font-bold text-[#1E3A8A] dark:text-indigo-800">
                Sistem Inventaris Yayasan Vidatra
              </span>
              <h1 className="text-4xl leading-[1.08] font-extrabold tracking-[-0.03em] sm:text-[54px]">
                Semua aset yayasan, tercatat rapi di satu tempat.
              </h1>
              <p className="max-w-[560px] text-lg leading-relaxed text-gray-600 sm:text-[19px]">
                Catat, cari, dan pantau aset setiap unit — lengkap dengan label QR, foto barang, sumber dana, dan laporan
                yang siap diunduh.
              </p>
              <div className="flex flex-wrap gap-3 pt-2">
                <Link to="/login" className={primaryButton}>
                  Masuk ke Aplikasi
                </Link>
                <a
                  href="#cara"
                  className="inline-flex items-center justify-center rounded-xl border border-gray-300 bg-surface px-7 py-4 text-base font-bold text-gray-900 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#1E3A8A] dark:focus-visible:outline-accent"
                >
                  Lihat Cara Kerja
                </a>
              </div>
            </div>
            <div className="flex min-w-0 flex-[1_1_380px] justify-center">
              <ExampleAssetCard />
            </div>
          </div>
        </section>

        <section id="fitur" className="mx-auto flex max-w-[1200px] scroll-mt-6 flex-col gap-12 px-6 pt-24 pb-10">
          <div className="flex max-w-[640px] flex-col gap-3">
            <h2 className="text-3xl font-extrabold tracking-tight sm:text-[38px]">Yang bisa dilakukan</h2>
            <p className="text-lg leading-relaxed text-gray-600">
              Dirancang untuk pengelola aset di yayasan dan setiap unit, tanpa perlu keahlian teknis.
            </p>
          </div>
          <ul className="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3">
            {FEATURES.map((f) => (
              <li key={f.title} className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-surface p-7">
                <LineIcon className="h-7 w-7 text-[#1E3A8A] dark:text-accent">{f.icon}</LineIcon>
                <h3 className="text-xl font-bold">{f.title}</h3>
                <p className="text-base leading-relaxed text-gray-600">{f.text}</p>
              </li>
            ))}
          </ul>
        </section>

        <section id="cara" className="mx-auto flex max-w-[1200px] scroll-mt-6 flex-col gap-10 px-6 pt-[72px] pb-24">
          <h2 className="text-3xl font-extrabold tracking-tight sm:text-[38px]">Cara kerjanya</h2>
          <ol className="grid grid-cols-1 gap-8 md:grid-cols-3">
            {STEPS.map((s, i) => (
              <li key={s.title} className="flex flex-col gap-3 border-t-[3px] border-[#1E3A8A] pt-6 dark:border-accent">
                <span className="text-[15px] font-extrabold text-[#1E3A8A] dark:text-accent">Langkah {i + 1}</span>
                <h3 className="text-[22px] font-bold">{s.title}</h3>
                <p className="text-base leading-relaxed text-gray-600">{s.text}</p>
              </li>
            ))}
          </ol>
        </section>

        <section id="bantuan" className="scroll-mt-6 bg-[#1E3A8A]">
          <div className="mx-auto flex max-w-[1200px] flex-wrap items-center justify-between gap-6 px-6 py-[72px]">
            <div className="flex min-w-0 flex-[1_1_420px] flex-col gap-2.5">
              <h2 className="text-[32px] font-extrabold text-white">Sudah punya akun?</h2>
              <p className="text-[17px] leading-relaxed text-[#E0E7FF]">
                Belum punya akun atau lupa kata sandi? Hubungi admin IT Yayasan.
              </p>
            </div>
            <Link
              to="/login"
              className="inline-flex items-center justify-center rounded-xl bg-white px-8 py-4 text-base font-bold text-[#1E3A8A] hover:bg-[#EEF2FF] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
              Masuk ke Aplikasi
            </Link>
          </div>
        </section>
      </main>

      <footer className="mx-auto flex max-w-[1200px] flex-wrap justify-between gap-3 px-6 pt-8 pb-[72px] text-sm text-gray-600">
        <span>© Yayasan Vidatra</span>
        <span>inventaris.vidatra.sch.id</span>
      </footer>
    </div>
  );
}
