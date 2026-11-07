# PABWE 2026 – Praktikum 7: Stock Barang (Laravel 13 & Livewire)

Aplikasi manajemen stok barang dengan arsitektur **Feature-First / Clean Architecture**:
Laravel 13, PHP 8.4+, Livewire, MySQL/MariaDB, PhpSpreadsheet, integrasi OpenAI, PHPUnit dengan **100% coverage `app/`**, dan pipeline Jenkins.

## Fitur

| Peran | Kemampuan |
|-------|-----------|
| Guest | Login / register. `/` mengarah ke login |
| Member | Mengelola **stok miliknya sendiri**: daftar (cari, filter status, pagination), CRUD lewat modal, adjust quantity (+/−) dari list & detail, detail + cover image + deskripsi Markdown (Toast UI), import/export Excel, generate item stok dengan AI (preview → pilih → simpan), profil |
| Admin | `/admin/users` (daftar user + jumlah stok) dan `/admin/users/{user}/stocks` (stok user, **read-only**) |

### Aturan status stok
Status selalu dihitung server-side oleh `StockStatus::fromQuantity($quantity, $minStock)`:

- `quantity <= 0` → `out_of_stock`
- `quantity <= min_stock` → `low_stock`
- selebihnya → `available`

Status di form create/edit hanya ditampilkan (baca-saja); nilai yang dikirim klien tidak pernah dipercaya, sehingga status tidak bisa tidak sinkron dengan quantity. Quantity tidak bisa menjadi negatif (`DomainException` di `StockService::adjustQuantity`, validasi `min:0` di form).

### Format Excel
Header (baris 1): `No | Name | SKU | Description | Qty | Unit | Price | Min Stock | Status | Created At`.
Import memakai nama header (urutan kolom bebas; `Quantity` juga diterima), kolom `Name` wajib, baris tidak valid dilaporkan (`Baris N: ...`) dan dilewati, SKU duplikat (di database/di file) ditolak, `Status` diabaikan dan dihitung ulang.

## Keamanan
- Role middleware `role`, ownership middleware `owns.stock`, dan `StockPolicy` (dicek ulang di setiap aksi Livewire via `Gate::authorize`).
- Admin hanya boleh `view`; `update`/`delete` hanya untuk pemilik.
- SKU unik **per user** (`unique(user_id, sku)`).
- Upload cover: hanya `jpg, jpeg, png, webp`, maks 5 MB, SVG ditolak; file lama dihapus saat diganti/dihapus.
- Mass assignment dibatasi dengan `#[Fillable]`; `user_id` selalu diambil dari user yang login.
- Rate limit `1000-per-second`, `FORCE_HTTPS` via `.env`.
- ID form login: `login-username`, `login-password`, `login-submit`.

## Struktur
Sesuai bagian 3.4 modul: `app/Features/{Admin,Ai,Auth,Profile,Stock}`, `app/Http/Middleware`, `resources/views/livewire/...`, `database/{migrations,factories,seeders}`, `config/openai.php`, `Jenkinsfile`.

## Menjalankan
```bash
cp .env.example .env        # lalu isi DB_* dan (opsional) OPENAI_*
composer install
php artisan key:generate
php artisan migrate --seed  # database: pabwe_p7_stock
php artisan storage:link
npm install && npm run build
php artisan serve
```
Akun seed: `admin / admin` (admin), `member / member` (member, dengan 3 contoh stok). **Ganti password ini di lingkungan nyata.**

`.env` tidak pernah di-commit. Isi `OPENAI_ENDPOINT`, `OPENAI_KEY`, `OPENAI_MODEL` hanya di `.env` / Jenkins Credentials.

## Pengujian
```bash
composer test               # phpunit --coverage-text (butuh pcov atau xdebug)
composer audit
```
Test AI memakai `Http::fake()` / `createStub()`, tanpa request API sungguhan.

## CI/CD
`Jenkinsfile`: Checkout → `composer install` + `composer audit` → build frontend → PHPUnit + `coverage.xml` → **gate 100%** (line/method/element) → Trivy → package (tanpa `.env`, `vendor/`, `node_modules/`, artefak test) → publish → deploy. Secret deploy (`DEPLOY_TOKEN`, dst.) berasal dari Jenkins, tidak ada yang di-hardcode.
