# Sky Gate Airport — Operations Dashboard

**Hartsfield–Jackson Atlanta International Airport (ATL / KATL)**  
A full-featured Airport Operations Management System built as a modern single-page dashboard.

[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8%2B-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite&logoColor=white)](https://www.sqlite.org/)

---

## Overview

**Sky Gate Airport** is a comprehensive operations dashboard designed for one of the busiest airports in the world.  
It gives operators real-time visibility and control over:

- Flight operations & gate management
- Live air traffic radar (local + global)
- Complete transit & ground transportation system
- Large-scale staff roster
- Baggage, fuel, safety, weather, and KPIs

The system is built with a clean **PHP** backend and a modern, responsive frontend (Vanilla JavaScript + Leaflet maps).  
It supports **both MySQL and SQLite** out of the box and runs easily on **Laragon** (Windows) or any standard PHP environment.

---

## Features

### Flight Operations
- Full flight list (active, scheduled, delayed, cancelled)
- Add / edit flights with airport autocomplete
- Continent-based gate filtering
- Aircraft assignment and passenger/bag counts
- Delay tracking and cancellation handling

### Gates & Turnaround
- Visual gate map by terminal (T, A, B, C, D, E, F)
- Real-time occupancy status
- Reserve / emergency gates
- Turnaround and occupancy timers

### Live Radar (Flightradar24)
- **Local Radar** — aircraft around Atlanta (KATL)
- **Global Traffic** — wider continental view
- Interactive Leaflet map + live aircraft list
- All API calls are proxied server-side (token never exposed to the browser)

### Transit & Ground Transportation
**Airport-owned services**
- Plane Train
- SkyTrain
- Terminal shuttles
- Parking shuttles

**Partner services**
- Taxi (Sedan / Minivan)
- Shared Van & VIP Van
- Groome Transportation
- MARTA / Coach

Includes lines, stations, fleet, individual vehicles, fares, parking lots, and daily statistics.

### Staff Management
- Designed to handle a large workforce (~64,000 records)
- Role, department, shift, zone, and status
- Section-based permissions
- Efficient pagination and filtering

### Supporting Modules
- Baggage tracking + BHS belts
- Fuel tanks and energy consumption
- Safety alerts, security zones, cameras, ARFF resources
- Weather (hourly observations)
- Airport KPIs and system state (simulation tick, evacuation mode, etc.)

---

## Database Support (MySQL + SQLite)

This project works with **both** database engines:

| Driver   | Use case                              | How to enable                          |
|----------|---------------------------------------|----------------------------------------|
| **SQLite** (default) | Zero setup, portable, single file    | `'driver' => 'sqlite'` in config      |
| **MySQL**            | Production / Laragon / multi-user    | `'driver' => 'mysql'` + import schema |

### SQLite
- Pre-built database is included: `data/skygate_atl.sqlite`
- No server configuration needed — just open the project
- Ideal for quick demo, development, or offline use

### MySQL
- Import `sql/schema.sql` into phpMyAdmin (or any MySQL client)
- Change `driver` to `mysql` in `config/database.php`
- Better for concurrent access and larger production deployments

You can switch between them at any time by editing one line in the config file.

---

## Flightradar24 API & Credits

This project uses the official **Flightradar24 API** for live aircraft positions (Local Radar + Global Traffic).

### Important notes about credits

You can choose different Flightradar24 API plans depending on your needs and budget.

The current setup was configured with a **30,000 credit** subscription.  
To prevent the credits from running out too quickly, the radar data is set to refresh **every 8 minutes**.

You are free to:

- Purchase higher-credit plans according to your expected usage
- Change the refresh interval to whatever you need  
  (e.g. every 1 second, every 5 seconds, every 30 seconds, every 1 minute, etc.)

The refresh interval and the number of aircraft returned per request (`local_limit` / `global_limit`) can be adjusted in `config/fr24.php`.

> **Tip:** Lower refresh intervals and higher aircraft limits consume credits much faster.  
> Choose a balance that matches your plan.

All Flightradar24 requests are handled **server-side** through `api/radar.php`.  
The API token is never exposed to the browser.

---

## Tech Stack

| Layer              | Technology                              |
|--------------------|-----------------------------------------|
| Backend            | PHP 8+ (pure, no heavy framework)      |
| Database           | MySQL **or** SQLite (switchable)       |
| Frontend           | Vanilla JavaScript + modern CSS        |
| Maps               | Leaflet.js                             |
| Live Radar         | Flightradar24 API (server-side proxy)  |
| Weather            | Open-Meteo                             |
| Authentication     | Session-based + CSRF protection        |
| Security           | Strong CSP, X-Frame-Options, SameSite cookies, etc. |

---

## Database Structure

The system uses **33 tables**. Below is a logical grouping:

### Core & Authentication
| Table | Description |
|-------|-------------|
| `users` | System users (admin + demo accounts) |
| `user_permissions` | Section-level access control per user |
| `departments` | Airport departments |
| `system_state` | Simulation tick, evacuation flag, critical mode |
| `notifications` | Internal notifications |

### Flight Operations
| Table | Description |
|-------|-------------|
| `flights` | Active and scheduled flights |
| `cancelled_flights` | Cancelled flights history |
| `gates` | All gates with occupancy status |
| `runways` | Runway status |
| `aircraft` | Aircraft models and specifications |
| `airports` | Worldwide airports (IATA/ICAO, continent, coordinates) |
| `terminal_settings` | Terminal type and continent mapping |

### Staff
| Table | Description |
|-------|-------------|
| `staff` | Large staff roster (employee code, role, shift, zone, status) |

### Baggage & Fuel
| Table | Description |
|-------|-------------|
| `baggage` | Individual bags linked to flights |
| `bhs_belts` | Baggage Handling System belts |
| `fuel_tanks` | Fuel storage tanks and levels |
| `energy_monthly` | Monthly energy consumption |

### Transit & Parking
| Table | Description |
|-------|-------------|
| `transit_lines` | Plane Train, SkyTrain, MARTA lines, etc. |
| `transit_stations` | Stations and stops |
| `ground_fleet` | Fleet summary by type/company |
| `ground_vehicles` | Individual vehicles |
| `transit_fares` | Fare tables |
| `transit_daily_stats` | Daily trip statistics |
| `parking_lots` | Parking facilities |
| `parking_vehicles` | Vehicles currently parked |

### Safety & Security
| Table | Description |
|-------|-------------|
| `safety_alerts` | Active and resolved safety alerts |
| `security_zones` | Security zone status |
| `cameras` | CCTV cameras |
| `arff_resources` | Aircraft Rescue & Fire Fighting units |
| `citizen_reports` | Public/citizen reports |

### Operations & Analytics
| Table | Description |
|-------|-------------|
| `terminal_zones` | Terminal zone density and wait times |
| `weather_hourly` | Hourly weather observations |
| `airport_kpis` | Daily operational KPIs |

---

## Login Credentials (after seeding)

| Role | Username | Password |
|------|----------|----------|
| **Admin** | `admin` | `admin123456` |
| Demo users | (various) | `admin123456` |

> Demo users are created only when you run **Full Seed**.

---

## Installation

### 1. Requirements
- PHP 8.0+ (with PDO SQLite or PDO MySQL extension)
- Optional: MySQL 5.7+ / MariaDB 10.3+ (if you prefer MySQL)

### 2. Project files
Copy the project into your web root, for example:
```
C:\laragon\www\sky-gate-airport
```

### 3. Configuration
```bash
cp config/database.example.php config/database.php
cp config/fr24.example.php     config/fr24.php
```

- Edit `config/database.php` and choose `sqlite` or `mysql`.
- Put your **real Flightradar24 token** only inside `config/fr24.php`.

### 4. Database

**Option A – SQLite (recommended for quick start)**  
The file `data/skygate_atl.sqlite` is already included.  
Just open the project — no extra steps needed.

**Option B – MySQL**
```sql
CREATE DATABASE skygate_atl CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Then import `sql/schema.sql` and set `'driver' => 'mysql'` in the config.

### 5. Seed the database (optional)
1. Open `setup.php` in the browser.
2. Open `seed/seed.php`:
   - **Initial Seed** → Creates admin + empty structure
   - **Full Seed** → Creates realistic demo data (flights, staff, bags, transit, etc.)

### 6. Login
Open the project URL and sign in with:
- Username: `admin`
- Password: `admin123456`

---

## Configuration Files (Examples Only)

Real credential files are **never** committed to the repository.

| File | Purpose |
|------|----------|
| `config/database.example.php` | Database connection template (MySQL + SQLite) |
| `config/fr24.example.php` | Flightradar24 token template |

After copying them to the non-example versions, fill in your own values.

---

## Project Structure

```
sky-gate-airport/
├── api/                        # Backend endpoints
│   ├── auth.php                # Login / logout / session
│   ├── data.php                # Main data provider
│   ├── actions.php             # Create / update / delete
│   ├── radar.php               # Flightradar24 proxy
│   ├── weather.php             # Weather data
│   └── tick.php                # Simulation tick
├── assets/
│   ├── css/app.css             # Dashboard styles
│   ├── js/app.js               # Frontend logic
│   ├── img/                    # Logos
│   └── maps/                   # Rail maps (SVG)
├── config/
│   ├── database.example.php    # MySQL + SQLite template
│   └── fr24.example.php        # FR24 token template
├── data/
│   └── skygate_atl.sqlite      # Pre-built SQLite database
├── includes/
│   ├── bootstrap.php           # Security headers + DB (dual driver)
│   └── simulate.php            # Simulation engine
├── seed/
│   └── seed.php                # Initial & Full seeder
├── sql/
│   ├── schema.sql              # MySQL schema
│   └── schema_sqlite.sql       # SQLite schema
├── index.php                   # Main SPA dashboard
├── setup.php                   # First-time setup helper
├── .gitignore
└── README.md
```

---

## Security

- `config/fr24.php` and `config/database.php` are listed in `.gitignore` and will never be pushed.
- All Flightradar24 requests go through the server-side proxy (`api/radar.php`).
- Strong security headers are applied by default (CSP, X-Frame-Options, Referrer-Policy, SameSite cookies, etc.).
- Session cookies are `HttpOnly` + `SameSite=Strict`.
- CSRF protection is implemented for sensitive actions.

---

## Important Notes

- This project is intended as a **demonstration / operational dashboard**.
- Full Seed generates a large volume of realistic data (especially the staff table).
- Flightradar24 API credits are consumed per aircraft returned. Limits can be adjusted in `config/fr24.php`.
- The system supports a simulation tick that can advance operational state.
- SQLite is perfect for demos and single-user use; switch to MySQL for multi-user or production environments.

---

## License

This project is provided for demonstration and educational purposes related to airport operations systems.
