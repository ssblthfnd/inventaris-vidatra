# Laravel React Starter

Starter project untuk membangun aplikasi web menggunakan Laravel, React, Vite, dan Tailwind CSS.

Starter ini digunakan sebagai fondasi awal untuk berbagai aplikasi web, seperti:

- Sistem informasi
- Dashboard internal
- Inventory
- Absensi
- Perpustakaan
- Administrasi
- Website sekolah
- Aplikasi CRUD

## Tech Stack

| Technology | Version |
|---|---|
| Laravel | 13 |
| PHP | 8.3+ |
| React | 19 |
| Vite | 8 |
| Tailwind CSS | 4 |
| React Router | Installed version |
| MySQL | 8.x / compatible |
| Node.js | 22+ |
| Composer | 2.x |
| Git | Latest stable |

## Prerequisites

Pastikan software berikut sudah terinstall:

- PHP 8.3+
- Composer 2.x
- Node.js 22+
- npm 10+
- MySQL 8.x atau compatible
- Git

## Clone Repository

Clone repository starter ke komputer lokal.

### 1. Clone repository

Buka terminal, lalu jalankan:

```bash
git clone https://github.com/ssblthfnd/laravel-react-starter.git
```

### 2. Masuk ke folder project

```bash
cd laravel-react-starter
```

### 3. Install dependency Laravel

```bash
composer install
```

Command ini akan menginstall seluruh dependency PHP yang dibutuhkan oleh Laravel berdasarkan file `composer.json`.

### 4. Install dependency React

```bash
npm install
```

Command ini akan menginstall seluruh dependency JavaScript yang dibutuhkan oleh React, Vite, Tailwind CSS, dan React Router berdasarkan file `package.json`.

Setelah proses selesai, project siap untuk dikonfigurasi.

### Alur Setup

```text
GitHub
   ↓
git clone
   ↓
Folder project
   ↓
composer install
   ↓
Dependency Laravel/PHP
   ↓
npm install
   ↓
Dependency React/JavaScript
```

> **Catatan:** Database, file `.env`, migration, dan menjalankan aplikasi akan dijelaskan pada langkah berikutnya.

## Configure Environment

Laravel menggunakan file `.env` untuk menyimpan konfigurasi environment seperti database, application key, dan pengaturan lainnya.

### 1. Copy `.env.example`

Setelah repository berhasil di-clone, buat file `.env` dari template yang sudah disediakan:

```bash
cp .env.example .env
```

> **Windows:** Jika command di atas tidak bekerja, file `.env.example` dapat disalin secara manual menjadi `.env`.

### 2. Generate Application Key

Jalankan:

```bash
php artisan key:generate
```

Command ini akan membuat `APP_KEY` baru di dalam file `.env`.

Application key digunakan Laravel untuk kebutuhan enkripsi aplikasi.

### 3. Configure Database

Buka file `.env`, kemudian sesuaikan konfigurasi database:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nama_database
DB_USERNAME=root
DB_PASSWORD=
```

Sesuaikan nilai berikut dengan konfigurasi MySQL pada komputer masing-masing:

- `DB_DATABASE` → nama database yang digunakan aplikasi
- `DB_USERNAME` → username MySQL
- `DB_PASSWORD` → password MySQL

Contoh konfigurasi untuk development lokal:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel_starter
DB_USERNAME=root
DB_PASSWORD=
```

> **Catatan:** Jangan memasukkan file `.env` ke repository Git. File `.env` dapat berisi informasi sensitif dan konfigurasi yang hanya berlaku untuk environment tertentu.

## Database Setup

Sebelum menjalankan migration, pastikan database MySQL sudah dibuat.

### 1. Create Database

Buat database baru melalui MySQL, phpMyAdmin, atau database management tool lainnya.

Contoh nama database:

```text
laravel_starter
```

Nama database harus sama dengan nilai `DB_DATABASE` di file `.env`.

Contoh:

```env
DB_DATABASE=laravel_starter
```

### 2. Run Migration

Setelah database dibuat dan konfigurasi `.env` sudah benar, jalankan:

```bash
php artisan migrate
```

Migration akan membuat tabel-tabel database yang dibutuhkan oleh aplikasi berdasarkan file migration yang tersedia di:

```text
database/migrations/
```

Jika migration berhasil, Laravel akan menampilkan daftar migration yang telah dijalankan.

### 3. Reset Database

Jika ingin menghapus seluruh tabel dan menjalankan kembali semua migration dari awal, gunakan:

```bash
php artisan migrate:fresh
```

> **Warning:** `migrate:fresh` akan menghapus seluruh tabel dalam database. Jangan gunakan command ini pada database production kecuali memang memahami konsekuensinya.

### Database Workflow

```text
Create Database
       ↓
Configure .env
       ↓
php artisan migrate
       ↓
Database Tables
```
## Running the Application

Setelah dependency, environment, dan database selesai dikonfigurasi, aplikasi dapat dijalankan dalam mode development.

### 1. Start Laravel Development Server

Buka terminal pertama, lalu jalankan:

```bash
php artisan serve
```

Secara default, Laravel akan berjalan pada:

```text
http://127.0.0.1:8000
```

Biarkan terminal ini tetap berjalan selama development.

### 2. Start Vite Development Server

Buka terminal kedua pada folder project yang sama, lalu jalankan:

```bash
npm run dev
```

Vite akan menjalankan development server dan menangani asset frontend seperti:

- React
- Tailwind CSS
- JavaScript
- CSS

Biarkan terminal ini tetap berjalan selama development.

### 3. Open the Application

Buka browser dan akses:

```text
http://127.0.0.1:8000
```

Jika konfigurasi berhasil, aplikasi Laravel dan React akan berjalan melalui development environment.

### Development Workflow

Saat development, dua terminal biasanya perlu tetap aktif:

```text
Terminal 1
php artisan serve
        ↓
Laravel Backend
        ↓
http://127.0.0.1:8000


Terminal 2
npm run dev
        ↓
Vite Development Server
        ↓
React + Tailwind CSS
```

> **Catatan:** `php artisan serve` menjalankan aplikasi Laravel, sedangkan `npm run dev` menjalankan Vite untuk development frontend. Keduanya memiliki fungsi yang berbeda dan biasanya dijalankan secara bersamaan.

## Project Structure

Berikut adalah struktur folder utama pada starter:

```text
laravel-react-starter/
├── app/
│   ├── Http/
│   ├── Models/
│   └── Providers/
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│
├── public/
│
├── resources/
│   ├── css/
│   │   └── app.css
│   │
│   ├── js/
│   │   ├── components/
│   │   ├── layouts/
│   │   │   └── MainLayout.jsx
│   │   ├── pages/
│   │   │   ├── Dashboard.jsx
│   │   │   └── Users.jsx
│   │   └── app.jsx
│   │
│   └── views/
│       └── welcome.blade.php
│
├── routes/
│   └── web.php
│
├── .env
├── .env.example
├── artisan
├── composer.json
├── package.json
├── vite.config.js
└── README.md
```

### Important Directories

| Directory | Description |
|---|---|
| `app/` | Backend application code Laravel |
| `app/Http/` | Controllers, middleware, dan request handling |
| `app/Models/` | Model untuk berinteraksi dengan database |
| `database/migrations/` | Definisi struktur tabel database |
| `database/seeders/` | Data awal atau dummy data |
| `public/` | File yang dapat diakses secara langsung oleh browser |
| `resources/css/` | File CSS dan konfigurasi styling |
| `resources/js/` | Source code React |
| `resources/js/components/` | Komponen React yang reusable |
| `resources/js/layouts/` | Layout utama aplikasi |
| `resources/js/pages/` | Halaman-halaman React |
| `resources/views/` | Blade views Laravel |
| `routes/` | Definisi route Laravel |
| `.env` | Konfigurasi environment lokal |
| `.env.example` | Template konfigurasi environment |
| `composer.json` | Dependency dan konfigurasi PHP |
| `package.json` | Dependency dan konfigurasi JavaScript |
| `vite.config.js` | Konfigurasi Vite |

### Frontend Structure

Source code React berada di:

```text
resources/js/
```

Strukturnya menggunakan pembagian sederhana:

```text
resources/js/
├── components/
├── layouts/
├── pages/
└── app.jsx
```

**`components/`**

Digunakan untuk komponen UI yang dapat digunakan kembali.

Contoh:

```text
Button.jsx
Modal.jsx
Table.jsx
Navbar.jsx
```

**`layouts/`**

Digunakan untuk layout yang digunakan oleh beberapa halaman.

Contoh:

```text
MainLayout.jsx
```

**`pages/`**

Digunakan untuk halaman aplikasi.

Contoh:

```text
Dashboard.jsx
Users.jsx
```

**`app.jsx`**

Merupakan entry point utama React. File ini menangani inisialisasi React, routing, dan rendering aplikasi.

> **Catatan:** Struktur ini sengaja dibuat sederhana. Ketika aplikasi berkembang, folder dan arsitektur dapat dikembangkan sesuai kebutuhan tanpa harus mengubah fondasi starter secara keseluruhan.

## React Pages & Routing

Starter ini menggunakan React Router untuk menangani navigasi antar halaman.

Routing React didefinisikan di:

```text
resources/js/app.jsx
```

### 1. Create a New Page

Untuk membuat halaman baru, buat file `.jsx` di:

```text
resources/js/pages/
```

Contoh membuat halaman `Products`:

```text
resources/js/pages/Products.jsx
```

Isi file:

```jsx
export default function Products() {
    return (
        <div>
            <h1 className="text-2xl font-semibold">Products</h1>
            <p className="mt-2 text-gray-600">
                Product management page.
            </p>
        </div>
    );
}
```

### 2. Import the Page

Buka:

```text
resources/js/app.jsx
```

Tambahkan import:

```jsx
import Products from './pages/Products';
```

### 3. Add the Route

Di dalam `<Routes>`, tambahkan:

```jsx
<Route path="/products" element={<Products />} />
```

Contoh:

```jsx
<Routes>
    <Route path="/dashboard" element={<Dashboard />} />
    <Route path="/users" element={<Users />} />
    <Route path="/products" element={<Products />} />
    <Route path="/" element={<Navigate to="/dashboard" replace />} />
</Routes>
```

Halaman tersebut sekarang dapat diakses melalui:

```text
/products
```

Dengan Laravel development server aktif, buka:

```text
http://127.0.0.1:8000/products
```

### 4. Add Navigation

Jika halaman perlu ditampilkan pada sidebar, tambahkan `Link` pada:

```text
resources/js/layouts/MainLayout.jsx
```

Contoh:

```jsx
<Link
    to="/products"
    className="block px-4 py-2 mt-1 rounded hover:bg-gray-100"
>
    Products
</Link>
```

### Page Creation Workflow

```text
Create Page
    ↓
resources/js/pages/Products.jsx
    ↓
Import Page
    ↓
resources/js/app.jsx
    ↓
Add Route
    ↓
Add Navigation
    ↓
/products
```

> **Catatan:** Gunakan `Link` dari React Router untuk navigasi antar halaman React. Hindari menggunakan `<a href="">` untuk navigasi internal karena dapat menyebabkan browser melakukan full page reload.

## Laravel Artisan Commands

Laravel menyediakan Artisan CLI untuk menjalankan berbagai perintah development, seperti membuat controller, model, migration, menjalankan server, dan mengelola database.

### Run Development Server

```bash
php artisan serve
```

Menjalankan Laravel development server.

### Clear Application Cache

```bash
php artisan optimize:clear
```

Membersihkan berbagai cache Laravel seperti configuration, route, view, dan application cache.

### Create Controller

```bash
php artisan make:controller ProductController
```

Membuat controller baru di:

```text
app/Http/Controllers/
```

Untuk membuat resource controller:

```bash
php artisan make:controller ProductController --resource
```

Resource controller menyediakan method standar untuk operasi CRUD:

```text
index()
create()
store()
show()
edit()
update()
destroy()
```

### Create Model

```bash
php artisan make:model Product
```

Membuat model baru di:

```text
app/Models/
```

Untuk membuat model sekaligus migration:

```bash
php artisan make:model Product -m
```

### Create Migration

```bash
php artisan make:migration create_products_table
```

Membuat file migration baru di:

```text
database/migrations/
```

### Run Migration

```bash
php artisan migrate
```

Menjalankan migration yang belum dijalankan.

### Rollback Migration

```bash
php artisan migrate:rollback
```

Membatalkan batch migration terakhir.

### Reset and Re-run Migration

```bash
php artisan migrate:fresh
```

Menghapus seluruh tabel dan menjalankan seluruh migration dari awal.

> **Warning:** Command ini akan menghapus data dalam database. Jangan digunakan pada production tanpa memahami konsekuensinya.

### Create Seeder

```bash
php artisan make:seeder ProductSeeder
```

Membuat seeder baru di:

```text
database/seeders/
```

### Run Database Seeder

```bash
php artisan db:seed
```

Menjalankan database seeder.

### List Available Routes

```bash
php artisan route:list
```

Menampilkan seluruh route Laravel yang terdaftar.

### List Available Artisan Commands

```bash
php artisan list
```

Menampilkan seluruh command Artisan yang tersedia.

### Common Artisan Workflow

Contoh ketika membuat fitur baru:

```text
Create Model
    ↓
php artisan make:model Product -m
    ↓
Create Migration
    ↓
Edit Migration
    ↓
php artisan migrate
    ↓
Create Controller
    ↓
php artisan make:controller ProductController --resource
    ↓
Implement Application Logic
```

> **Tip:** Gunakan `php artisan list` atau `php artisan help <command>` jika ingin melihat opsi lengkap dari sebuah command.

## NPM Commands

Project ini menggunakan npm untuk mengelola dependency dan menjalankan proses development frontend.

### Install Dependencies

```bash
npm install
```

Menginstall seluruh dependency yang tercantum di `package.json`.

Command ini biasanya dijalankan setelah:

- pertama kali clone repository
- dependency berubah
- `package.json` diperbarui

### Start Vite Development Server

```bash
npm run dev
```

Menjalankan Vite development server.

Vite akan menangani proses development untuk:

- React
- JavaScript
- CSS
- Tailwind CSS

Biarkan command ini tetap berjalan selama development.

### Build for Production

```bash
npm run build
```

Membuat production build dari asset frontend.

Hasil build akan berada di:

```text
public/build/
```

Build ini digunakan ketika aplikasi akan dipersiapkan untuk production.

### Check Installed Dependencies

```bash
npm list --depth=0
```

Menampilkan dependency utama yang terinstall pada project.

### Update Dependencies

Untuk memperbarui dependency berdasarkan konfigurasi `package.json`, gunakan:

```bash
npm update
```

> **Catatan:** Hindari menjalankan update dependency secara sembarangan pada project production. Perubahan versi package dapat menyebabkan perubahan behavior atau compatibility issue.

### Common NPM Workflow

Saat pertama kali menjalankan project:

```text
Clone Repository
       ↓
npm install
       ↓
npm run dev
       ↓
Development
```

Saat menyiapkan frontend untuk production:

```text
Development
       ↓
npm run build
       ↓
public/build/
       ↓
Production
```

## Git Workflow

Project ini menggunakan Git untuk version control dan GitHub sebagai remote repository.

### Check Git Status

Sebelum melakukan perubahan atau commit, cek status repository:

```bash
git status
```

Command ini menunjukkan:

- file yang berubah
- file baru
- file yang belum di-track
- branch yang sedang digunakan

### Create a New Branch

Untuk mengembangkan fitur baru, buat branch:

```bash
git checkout -b feature/nama-fitur
```

Contoh:

```bash
git checkout -b feature/inventory
```

Gunakan nama branch yang menggambarkan pekerjaan yang sedang dilakukan.

Contoh:

```text
feature/inventory
feature/attendance
feature/user-management
fix/login-error
```

### Add Changes

Setelah selesai melakukan perubahan:

```bash
git add .
```

Command ini memasukkan seluruh perubahan ke staging area.

Untuk melihat kembali perubahan yang akan di-commit:

```bash
git status
```

### Commit Changes

Buat commit dengan message yang menjelaskan perubahan:

```bash
git commit -m "feat: add inventory module"
```

Contoh commit message:

```text
feat: add user management
fix: resolve login validation
refactor: simplify dashboard component
docs: update README
style: improve sidebar layout
```

### Push Branch

Kirim branch ke GitHub:

```bash
git push -u origin feature/nama-fitur
```

Contoh:

```bash
git push -u origin feature/inventory
```

### Pull Latest Changes

Sebelum mulai bekerja, ambil perubahan terbaru dari remote repository:

```bash
git pull
```

### Switch Branch

Untuk berpindah branch:

```bash
git checkout main
```

atau:

```bash
git checkout feature/inventory
```

### View Commit History

Untuk melihat riwayat commit:

```bash
git log --oneline
```

### Recommended Development Workflow

Gunakan workflow berikut ketika mengembangkan fitur:

```text
Update local repository
        ↓
git pull
        ↓
Create feature branch
        ↓
git checkout -b feature/nama-fitur
        ↓
Develop
        ↓
Test
        ↓
git status
        ↓
git add .
        ↓
git commit -m "feat: ..."
        ↓
git push
        ↓
Create Pull Request
        ↓
Code Review
        ↓
Merge to main
```

### Simple Workflow for Personal Development

Jika repository digunakan sendiri tanpa Pull Request, workflow dapat dibuat lebih sederhana:

```text
git pull
   ↓
Develop
   ↓
Test
   ↓
git add .
   ↓
git commit -m "feat: ..."
   ↓
git push
```

> **Tip:** Hindari melakukan commit langsung ke `main` ketika mengembangkan fitur besar. Gunakan feature branch agar perubahan dapat diuji dan ditinjau terlebih dahulu.

## Create a New Project from This Starter

Repository ini dapat digunakan sebagai fondasi untuk membuat berbagai aplikasi baru.

Contoh project:

```text
laravel-react-starter
        ↓
        ├── inventory-ypvdp
        ├── attendance-ypvdp
        ├── library-ypvdp
        └── school-information-system
```

### 1. Clone the Starter

Clone repository starter:

```bash
git clone https://github.com/ssblthfnd/laravel-react-starter.git nama-project
```

Contoh:

```bash
git clone https://github.com/ssblthfnd/laravel-react-starter.git inventory-ypvdp
```

### 2. Enter the Project Directory

```bash
cd inventory-ypvdp
```

### 3. Remove the Starter Git History

Project baru tidak boleh tetap terhubung dengan repository starter.

Hapus folder `.git`:

```bash
rmdir /s /q .git
```

> **Windows:** Command di atas digunakan pada Command Prompt atau terminal yang mendukung command tersebut. Jika menggunakan PowerShell, gunakan command yang sesuai untuk menghapus folder `.git`.

### 4. Initialize a New Git Repository

Buat repository Git baru:

```bash
git init
```

Set branch utama menjadi `main`:

```bash
git branch -M main
```

### 5. Create a New GitHub Repository

Buat repository baru di GitHub dengan nama project yang sesuai.

Contoh:

```text
inventory-ypvdp
```

Jangan gunakan repository starter sebagai remote project baru.

### 6. Connect the New Repository

Tambahkan repository GitHub baru sebagai remote:

```bash
git remote add origin https://github.com/USERNAME/REPOSITORY.git
```

Contoh:

```bash
git remote add origin https://github.com/USERNAME/inventory-ypvdp.git
```

### 7. Create the First Commit

Tambahkan seluruh file:

```bash
git add .
```

Buat commit pertama:

```bash
git commit -m "chore: initialize project from starter"
```

### 8. Push to GitHub

```bash
git push -u origin main
```

Project baru sekarang memiliki repository GitHub sendiri.

### Complete Workflow

```text
laravel-react-starter
        ↓
git clone
        ↓
nama-project
        ↓
Remove .git
        ↓
git init
        ↓
Create new GitHub repository
        ↓
git remote add origin
        ↓
git add .
        ↓
git commit
        ↓
git push
        ↓
New Project Repository
```

> **Important:** Setelah `.git` dari starter dihapus dan repository baru dibuat, project tersebut sudah berdiri sendiri. Perubahan pada project baru tidak akan mengubah repository `laravel-react-starter`.

