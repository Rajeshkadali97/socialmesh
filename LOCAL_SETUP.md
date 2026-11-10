# SocialMesh — Local Development Setup

## 1. Prerequisites

- **PHP**: 8.5+ with extensions: `sqlite3`, `pdo_sqlite`, `gd`, `curl`, `mbstring`, `xml`, `zip`, `bcmath`, `intl`
- **Composer**: 2.8+
- **Bun**: 1.3+ (project uses `bun`, `oxlint`, and `oxfmt` — do not use `npm` or `pnpm`)
- **SQLite**: 3.35+

## 2. Installation Steps

```bash
# 1. Clone the repository and switch to the working branch
git clone https://github.com/Rajeshkadali97/socialmesh.git
cd socialmesh
git checkout antigravity/complete-development

# 2. Install PHP dependencies
composer install

# 3. Copy environment configuration and generate keys
cp .env.example .env
php artisan key:generate
php artisan passport:keys --force

# 4. Prepare SQLite database and run migrations
mkdir -p database/sqlite
touch database/sqlite/database.sqlite
php artisan migrate

# 5. Seed the default owner user and 13 agency client workspaces
php artisan db:seed

# 6. Install JavaScript dependencies and generate Wayfinder route types
bun install
php artisan wayfinder:generate --with-form

# 7. Create the public storage symlink
php artisan storage:link
```

## 3. Running the Application Locally

Start the full local development stack (Laravel server, queue listener, Pail logs, and Vite HMR):

```bash
composer run dev
```

Or run frontend asset compilation once:

```bash
bun run build
```

### Default Local Credentials (after `php artisan db:seed`)

- **Email**: `test@example.com`
- **Password**: `password`
- **Role**: Instance Owner + Owner of 13 seeded Ebin Tech client workspaces (`Ebin Tech`, `RK Enterprises`, `Aurora Aesthetics`, `Spice Route Bistro`, `Verde Living & Interiors`, `Nexus Fitness Club`, `Kerala Craft Collective`, `Skyline Realty Group`, `Lumina Dental Studio`, `CloudNine Coffee Roasters`, `Veloce Detailing & Motors`, `Zenith Academy`, `Harbor Boutique Stays`).

## 4. Quality & Verification Commands

```bash
# Run full CI gate (Oxlint, Oxfmt, TypeScript, Pint, Larastan, Pest)
composer ci:check

# Run Pest backend tests
./vendor/bin/pest --compact

# Run Vitest frontend unit tests
bun run test -- --run

# Run static analysis and linting individually
composer types:check
composer lint:check
bun run types:check
bun run lint:check
bun run format:check
```
