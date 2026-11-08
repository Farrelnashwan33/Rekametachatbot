# Rekametachat

> **Tagline:** "Your Intelligent AI Companion"

Rekametachat adalah platform asisten AI serbaguna berbasis web untuk percakapan kontekstual dua bahasa (Indonesia & Inggris), pendampingan akademik bertahap, pemrograman & debugging, penyusunan serta evaluasi CV ATS (termasuk ekspor PDF), analisis dokumen privat (`PDF`, `DOCX`, `TXT`, `CSV`), memori personal berbasis persetujuan pengguna, dan tata kelola operasional administrator yang menjaga privasi percakapan.

---

## 1. Arsitektur & Tech Stack

### Frontend (`/frontend`)
- **Framework:** SvelteKit (Svelte 5 Runes) + TypeScript
- **Styling:** Tailwind CSS v4 + CSS Custom Properties (`light-dark()` dengan Light Mode & Dark Mode Midnight Navy)
- **Deployment Adapter:** `@sveltejs/adapter-cloudflare` untuk Cloudflare Pages
- **Keamanan Markup:** Native Sanitizer API (`Element.prototype.setHTML`) dengan fallback `DOMPurify` + syntax highlighting `highlight.js`

### Backend (`/backend`)
- **Framework:** Laravel 13 RESTful API
- **Autentikasi:** Laravel Sanctum (Bearer Token API)
- **Arsitektur Layanan (`app/Services/`):**
  - `AI/AIProviderManager.php`: Orkestrator provider modular (`OpenAICompatibleProvider` & `AnthropicProvider`) dengan dukungan Server-Sent Events (SSE) streaming dan status jujur `503 AI_PROVIDER_NOT_CONFIGURED` ketika API key belum tersedia.
  - `AI/ContextManagerService.php` & `MemoryExtractorService.php`: Manajemen jendela konteks bertingkat, ringkasan percakapan bergulir, isolasi prompt injection (`<untrusted_reference_context>`), serta ekstraksi memori berbasis persetujuan.
  - `Documents/DocumentParserService.php` & `DocumentChunkerService.php`: Ekstraksi teks `PDF`, `DOCX`, `TXT`, `CSV`, pemotongan segmen, dan retrieval terarah.
  - `Cv/CvIntelligenceService.php` & `CvPdfExportService.php`: Evaluasi skor keterbacaan ATS deterministik, penyesuaian lowongan kerja tanpa mengarang fakta, pembuatan cover letter, serta ekspor PDF menggunakan Dompdf pada 3 template (`executive`, `ats_clean`, `modern_minimal`).
- **Queue:** Database queue (`ProcessDocumentChunksJob`) untuk pemrosesan dokumen berukuran besar.
- **Storage:** Mendukung penyimpanan lokal privat maupun **Cloudflare R2** (driver S3-compatible).

### Database (PostgreSQL via Laravel Migrations)
Seluruh skema dibuat otomatis melalui `php artisan migrate --seed` tanpa skrip SQL manual:
1. `users` (+ `password_reset_tokens`, `sessions`, `personal_access_tokens`)
2. `user_preferences`
3. `ai_provider_settings` (API key terenkripsi dengan Laravel `Crypt`)
4. `documents`
5. `document_chunks`
6. `conversations`
7. `messages`
8. `user_memories`
9. `usage_logs`
10. `cv_profiles`

---

## 2. Struktur Direktori Proyek

```text
Reka Meta Chatbot/
├── DESIGN.md                  # Spesifikasi identitas visual, kontras WCAG AA, dan keputusan arsitektur
├── README.md                  # Dokumentasi instalasi, konfigurasi, dan deployment
├── backend/                   # Aplikasi REST API Laravel + Sanctum + PostgreSQL
│   ├── app/
│   │   ├── Contracts/         # Kontrak AIProviderInterface
│   │   ├── Exceptions/        # AIProviderException terstandar
│   │   ├── Http/Controllers/  # Auth, Profile, Conversation, Chat, Memory, Document, CV, Admin
│   │   ├── Http/Middleware/   # EnsureAdmin & EnsureActiveUser
│   │   ├── Jobs/              # ProcessDocumentChunksJob
│   │   ├── Models/            # Eloquent models & relasi
│   │   └── Services/          # Modular AI, Context, Memory, Document, dan CV Services
│   ├── database/
│   │   ├── migrations/        # Seluruh migrasi otomatis PostgreSQL
│   │   └── seeders/           # Seeder akun Admin awal & daftar AI provider modular
│   ├── routes/api.php         # Seluruh endpoint RESTful & SSE
│   └── tests/Feature/         # Pengujian otomatis API & privasi Admin
├── frontend/                  # Aplikasi SvelteKit + TypeScript + Tailwind CSS
│   ├── src/
│   │   ├── lib/               # Client API, SSE parser, Markdown sanitizer, Theme & App State
│   │   └── routes/            # Halaman Auth, Smart Chat, Explore AI, Documents, CV Studio, Settings, Admin
│   ├── vite.config.ts         # Konfigurasi Vite + SvelteKit + Cloudflare Adapter
│   └── wrangler.toml          # Konfigurasi Cloudflare Pages
└── deploy/                    # Konfigurasi Nginx (SSE-ready) & Supervisor Queue Worker
```

---

## 3. Instalasi & Menjalankan di Lokal

### Prasyarat
- PHP `>= 8.3` beserta ekstensi `pdo_pgsql`, `mbstring`, `xml`, `zip`, `gd` / `dom`
- Composer `>= 2.7`
- Node.js `>= 20` & `npm`
- PostgreSQL `>= 14`

### Langkah 1: Siapkan Database PostgreSQL
Buat database kosong bernama `rekametachat` (dan opsional `rekametachat_test` untuk pengujian otomatis):

```bash
createdb rekametachat
createdb rekametachat_test
```

### Langkah 2: Konfigurasi & Jalankan Backend Laravel
```bash
cd backend
cp .env.example .env
composer install
php artisan key:generate
```

Sesuaikan koneksi PostgreSQL pada `backend/.env`:
```dotenv
APP_NAME=Rekametachat
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://localhost:5173
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rekametachat
DB_USERNAME=postgres
DB_PASSWORD=secret

REKAMETACHAT_ADMIN_EMAIL=admin@rekametachat.local
REKAMETACHAT_ADMIN_PASSWORD=GantiPasswordKuat123!
```

Jalankan migrasi tabel otomatis beserta seeder awal:
```bash
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Opsional, jalankan worker antrean di terminal terpisah untuk pemrosesan dokumen besar:
```bash
php artisan queue:work
```

### Langkah 3: Konfigurasi & Jalankan Frontend SvelteKit
```bash
cd frontend
cp .env.example .env
npm install
npm run dev -- --port 5173
```

Buka `http://localhost:5173` di browser.

---

## 4. Konfigurasi AI Provider

Rekametachat menerapkan prinsip transparansi penuh: **aplikasi tidak pernah memalsukan jawaban AI ketika API key belum dikonfigurasi**. Jika belum ada provider yang memiliki API key aktif, endpoint percakapan akan mengembalikan status `503 AI_PROVIDER_NOT_CONFIGURED` beserta arahan konfigurasi yang jelas.

Anda dapat mengatur API key melalui salah satu dari dua cara berikut:

### Cara A: Melalui Panel Operasional Admin (Direkomendasikan)
1. Masuk menggunakan akun Administrator yang dibuat oleh `DatabaseSeeder`.
2. Buka menu **Admin** (`/admin`) -> tab **Provider & Model AI**.
3. Masukkan API Key pada provider pilihan Anda (**OpenAI**, **Anthropic Claude**, **Google Gemini**, **OpenRouter**, atau tambahkan endpoint kustom OpenAI-compatible seperti Groq / vLLM).
4. Klik **Simpan**. Kunci disimpan secara terenkripsi menggunakan `APP_KEY` Laravel di tabel `ai_provider_settings` dan tidak pernah diekspos ke frontend.

### Cara B: Melalui Environment Variables (`backend/.env`)
```dotenv
AI_DEFAULT_PROVIDER=openai

OPENAI_API_KEY=sk-...
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_DEFAULT_MODEL=gpt-4o-mini

ANTHROPIC_API_KEY=
GEMINI_API_KEY=
OPENROUTER_API_KEY=
```

---

## 5. Pengujian Otomatis

### Backend Feature Tests (PHPUnit)
```bash
cd backend
php artisan test
```

### Frontend Typecheck & Production Build
```bash
cd frontend
npm run check
npm run build
```

---

## 6. Panduan Deployment Produksi (Cloudflare + Hosting PHP Laravel)

### A. Penyimpanan File Cloudflare R2
1. Buat bucket privat bernama `rekametachat-files` di dashboard Cloudflare R2.
2. Buat R2 API Token dengan izin *Object Read & Write*.
3. Isi variabel berikut di `backend/.env` server produksi:
   ```dotenv
   FILESYSTEM_DISK=r2
   DOCUMENT_STORAGE_DISK=r2
   R2_ACCESS_KEY_ID=your_r2_key_id
   R2_SECRET_ACCESS_KEY=your_r2_secret_key
   R2_BUCKET=rekametachat-files
   R2_ENDPOINT=https://<ACCOUNT_ID>.r2.cloudflarestorage.com
   ```
   Seluruh file tetap diunduh/diindeks melalui otorisasi backend sehingga dokumen pengguna tidak dapat diakses publik.

### B. Hosting Backend Laravel (`api.rekametachat.com`)
1. Deploy direktori `backend/` ke VPS atau PaaS yang mendukung PHP 8.3+, Nginx, Supervisor, dan PostgreSQL terkelola.
2. Gunakan konfigurasi Nginx pada `deploy/nginx-laravel.conf` (memastikan `fastcgi_buffering off;` aktif agar streaming jawaban SSE berjalan mulus melewati Cloudflare).
3. Gunakan konfigurasi Supervisor pada `deploy/supervisor-queue.conf` untuk menjaga worker antrean tetap berjalan.
4. Jalankan optimasi produksi:
   ```bash
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force --seed
   php artisan config:cache
   php artisan route:cache
   ```

### C. Deployment Frontend ke Cloudflare Pages (`app.rekametachat.com`)
1. Hubungkan repositori ke **Cloudflare Pages** dengan pengaturan:
   - **Root directory:** `frontend`
   - **Build command:** `npm run build`
   - **Build output directory:** `.svelte-kit/cloudflare`
2. Tambahkan Environment Variable pada Cloudflare Pages:
   - `PUBLIC_API_BASE_URL=https://api.rekametachat.com`
   - `PUBLIC_APP_NAME=Rekametachat`
3. Atau deploy langsung menggunakan Wrangler CLI:
   ```bash
   cd frontend
   npm run build
   npx wrangler pages deploy .svelte-kit/cloudflare --project-name rekametachat-web
   ```

### D. Cloudflare DNS, SSL & Keamanan
1. Arahkan `CNAME` domain utama (misal `app.rekametachat.com`) ke proyek Cloudflare Pages dan `A`/`CNAME` subdomain `api.rekametachat.com` ke IP server Laravel dengan status **Proxied (Orange Cloud)**.
2. Aktifkan mode SSL/TLS **Full (Strict)** menggunakan Origin Certificate Cloudflare di Nginx backend.
3. Aktifkan **Always Use HTTPS**, **WAF Managed Rules**, serta Rate Limiting tambahan pada path `/api/auth/*`.

