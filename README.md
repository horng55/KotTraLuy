# KotTraLuy

KotTraLuy is a business management app for inventory, sales, purchases, and SME financing preparation.

## Projects

- Backend: Laravel API for business data, inventory logic, and reporting
- Frontend: React + Vite app for the user interface

## Quick Start

Install PHP 8.2+, Composer, Node.js, and Git first. Then, from the project root:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
# Edit .env and set DB_CONNECTION=sqlite before continuing.
New-Item database\database.sqlite -ItemType File -Force
php artisan migrate

cd ..\frontend
npm install
```

Start both services by double-clicking `start_all.bat`, or run them manually:

```powershell
# Terminal 1
cd backend
php -S 127.0.0.1:8000 -t public public\router.php

# Terminal 2
cd frontend
npm run dev
```

Open the frontend at `http://localhost:5173`. The SQLite database is stored at `backend/database/database.sqlite`; it is intentionally ignored by Git, so each new computer must create it and run the migrations. The `.env.example` file is configured for MySQL by default, so set `DB_CONNECTION=sqlite` for the local SQLite setup above.

## Repository Layout

```text
KotTraLuy/
├── backend/
├── frontend/
├── start_all.bat
├── README.md
└── ...
```

## Notes

The frontend is configured to proxy `/api` requests to the local Laravel backend on port 8000.
