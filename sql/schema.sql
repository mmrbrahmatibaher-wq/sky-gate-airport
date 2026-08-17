-- ============================================================
-- SkyGate ATL — Complete schema + transit seed (MySQL)
-- Import in phpMyAdmin / mysql CLI
-- For SQLite use sql/schema_sqlite.sql or data/skygate_atl.sqlite
-- Switch driver in config/database.php: mysql | sqlite
-- Charset: utf8mb4  |  Engine: InnoDB
-- Admin login after seed.php: admin / admin123456
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

CREATE DATABASE IF NOT EXISTS skygate_atl
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE skygate_atl;

DROP TABLE IF EXISTS user_permissions;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS citizen_reports;
DROP TABLE IF EXISTS safety_alerts;
DROP TABLE IF EXISTS cameras;
DROP TABLE IF EXISTS arff_resources;
DROP TABLE IF EXISTS security_zones;
DROP TABLE IF EXISTS weather_hourly;
DROP TABLE IF EXISTS airport_kpis;
DROP TABLE IF EXISTS transit_fares;
DROP TABLE IF EXISTS ground_fleet;
DROP TABLE IF EXISTS transit_stations;
DROP TABLE IF EXISTS parking_vehicles;
DROP TABLE IF EXISTS ground_vehicles;
DROP TABLE IF EXISTS transit_daily_stats;
DROP TABLE IF EXISTS parking_lots;
DROP TABLE IF EXISTS transit_lines;
DROP TABLE IF EXISTS terminal_zones;
DROP TABLE IF EXISTS energy_monthly;
DROP TABLE IF EXISTS fuel_tanks;
DROP TABLE IF EXISTS bhs_belts;
DROP TABLE IF EXISTS baggage;
DROP TABLE IF EXISTS cancelled_flights;
DROP TABLE IF EXISTS flights;
DROP TABLE IF EXISTS gates;
DROP TABLE IF EXISTS runways;
DROP TABLE IF EXISTS aircraft;
DROP TABLE IF EXISTS airports;
DROP TABLE IF EXISTS staff;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS terminal_settings;
DROP TABLE IF EXISTS system_state;

-- ------------------------------------------------------------
CREATE TABLE users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(50)  NOT NULL UNIQUE,
  password        VARCHAR(255) NOT NULL COMMENT 'password_hash()',
  full_name       VARCHAR(120) NULL,
  role            ENUM('admin','supervisor','controller','gate_agent','ramp_agent','security','inspector','viewer')
                  NOT NULL DEFAULT 'controller',
  position_title  VARCHAR(120) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_permissions (
  user_id     INT UNSIGNED NOT NULL,
  section_key VARCHAR(40)  NOT NULL,
  PRIMARY KEY (user_id, section_key),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE departments (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code      VARCHAR(20)  NOT NULL UNIQUE,
  name      VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE aircraft (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  model_code    VARCHAR(20) NOT NULL UNIQUE,
  manufacturer  VARCHAR(40) NULL,
  typical_crew  TINYINT UNSIGNED NOT NULL DEFAULT 4,
  seats_total   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  max_fuel_kg   INT UNSIGNED NOT NULL DEFAULT 0,
  image_url     VARCHAR(500) NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE airports (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  iata        CHAR(3) NOT NULL UNIQUE,
  icao        CHAR(4) NULL,
  name        VARCHAR(120) NOT NULL,
  city        VARCHAR(80) NOT NULL,
  country     VARCHAR(80) NOT NULL,
  continent   VARCHAR(30) NULL COMMENT 'europe, asia, namerica, samerica, africa, oceania',
  lat         DECIMAL(9,6) NULL,
  lon         DECIMAL(9,6) NULL,
  KEY idx_iata (iata),
  KEY idx_city (city),
  KEY idx_continent (continent)
) ENGINE=InnoDB;


CREATE TABLE runways (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(16) NOT NULL UNIQUE,
  status     ENUM('open','closed','inspection') NOT NULL DEFAULT 'open',
  role       ENUM('both','takeoff','landing','closed') NOT NULL DEFAULT 'both',
  updated_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE gates (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(8) NOT NULL UNIQUE,
  terminal           CHAR(1) NOT NULL,
  gate_number        SMALLINT UNSIGNED NOT NULL,
  is_reserve         TINYINT(1) NOT NULL DEFAULT 0,
  status             ENUM('available','occupied','maintenance','closed') NOT NULL DEFAULT 'available',
  current_flight_id  INT UNSIGNED NULL,
  occupied_since     DATETIME NULL,
  KEY idx_gate_term (terminal),
  KEY idx_gate_status (status)
) ENGINE=InnoDB;

CREATE TABLE flights (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_number    VARCHAR(12) NOT NULL,
  type             ENUM('dep','arr') NOT NULL DEFAULT 'dep',
  origin           CHAR(3) NOT NULL,
  destination      CHAR(3) NOT NULL,
  aircraft_id      INT UNSIGNED NULL,
  gate_id          INT UNSIGNED NULL,
  status           VARCHAR(40) NOT NULL DEFAULT 'Scheduled',
  scheduled_time   DATETIME NULL,
  estimated_time   DATETIME NULL,
  delay_minutes    SMALLINT NULL,
  delay_reason     VARCHAR(120) NULL,
  seats_total      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pax_accepted     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bags_count       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pilot_name       VARCHAR(120) NULL,
  copilot_name     VARCHAR(120) NULL,
  cabin_crew       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  is_international TINYINT(1) NOT NULL DEFAULT 0,
  is_manual        TINYINT(1) NOT NULL DEFAULT 0,
  phase_started_at DATETIME NULL COMMENT 'when current status began',
  is_tomorrow      TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fn (flight_number),
  KEY idx_status (status),
  KEY idx_type (type),
  KEY idx_sched (scheduled_time),
  KEY idx_tomorrow (is_tomorrow),
  KEY idx_manual (is_manual),
  CONSTRAINT fk_fl_ac FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE SET NULL,
  CONSTRAINT fk_fl_gate FOREIGN KEY (gate_id) REFERENCES gates(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cancelled_flights (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  flight_number       VARCHAR(12) NOT NULL,
  aircraft_code       VARCHAR(10) NULL,
  origin              CHAR(3) NULL,
  destination         CHAR(3) NULL,
  scheduled_time      DATETIME NULL,
  pax                 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  reason              VARCHAR(120) NULL,
  replacement_flight  VARCHAR(12) NULL
) ENGINE=InnoDB;

CREATE TABLE staff (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code  VARCHAR(20) NOT NULL UNIQUE,
  first_name     VARCHAR(60) NOT NULL,
  last_name      VARCHAR(60) NOT NULL,
  role           VARCHAR(60) NOT NULL,
  department_id  INT UNSIGNED NULL,
  shift          ENUM('morning','afternoon','night') NOT NULL DEFAULT 'morning',
  zone           VARCHAR(60) NULL,
  status         ENUM('on_duty','break','off') NOT NULL DEFAULT 'off',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  hired_at       DATE NULL,
  KEY idx_staff_role (role),
  KEY idx_staff_status (status)
) ENGINE=InnoDB;

CREATE TABLE baggage (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bag_id         VARCHAR(20) NOT NULL UNIQUE,
  flight_id      INT UNSIGNED NULL,
  flight_number  VARCHAR(12) NULL,
  owner_name     VARCHAR(120) NULL,
  weight_kg      DECIMAL(5,1) NULL,
  status         ENUM('checked','in_system','in_transit','delivered','missing','found','damaged','wrong_location')
                 NOT NULL DEFAULT 'in_system',
  belt_code      VARCHAR(20) NULL,
  updated_at     DATETIME NULL,
  KEY idx_bag_flight (flight_id),
  KEY idx_bag_fn (flight_number),
  KEY idx_bag_status (status)
) ENGINE=InnoDB;

CREATE TABLE bhs_belts (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL UNIQUE,
  name          VARCHAR(80) NOT NULL,
  bags_on_belt  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('running','idle','fault') NOT NULL DEFAULT 'idle'
) ENGINE=InnoDB;

CREATE TABLE fuel_tanks (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name               VARCHAR(40) NOT NULL UNIQUE,
  fuel_type          ENUM('jet_a','saf') NOT NULL DEFAULT 'jet_a',
  capacity_gal       INT UNSIGNED NOT NULL,
  level_pct          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  low_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 20,
  updated_at         DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE energy_monthly (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year      SMALLINT NOT NULL,
  month     TINYINT NOT NULL,
  mwh       DECIMAL(10,2) NOT NULL DEFAULT 0,
  is_actual TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_ym (year, month)
) ENGINE=InnoDB;

CREATE TABLE terminal_zones (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  zone_code     VARCHAR(30) NOT NULL UNIQUE,
  name          VARCHAR(80) NOT NULL,
  wait_minutes  SMALLINT NULL,
  density_pct   TINYINT NULL,
  open_lanes    SMALLINT NULL,
  pax_inside    INT NULL,
  updated_at    DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE transit_lines (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20) NOT NULL UNIQUE,
  name               VARCHAR(80) NOT NULL,
  status             ENUM('on','off','maintenance') NOT NULL DEFAULT 'on',
  speed_mph          SMALLINT NULL,
  capacity_per_trip  SMALLINT NULL,
  avg_load_pct       TINYINT NULL,
  route_label        VARCHAR(120) NULL
) ENGINE=InnoDB;

CREATE TABLE security_zones (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code       VARCHAR(30) NOT NULL UNIQUE,
  name       VARCHAR(80) NOT NULL,
  status     ENUM('clear','watch','breach') NOT NULL DEFAULT 'clear',
  updated_at DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE safety_alerts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  level       ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  title       VARCHAR(160) NOT NULL,
  location    VARCHAR(120) NULL,
  category    ENUM('perimeter','bag','shooter','hijack','fire','bomb','hostage','gate_conflict','other')
              NOT NULL DEFAULT 'other',
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  KEY idx_sa_active (is_active),
  KEY idx_sa_level (level)
) ENGINE=InnoDB;

CREATE TABLE cameras (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cam_code      VARCHAR(20) NOT NULL UNIQUE,
  zone          VARCHAR(40) NULL,
  stream_url    VARCHAR(500) NULL,
  snapshot_url  VARCHAR(500) NULL,
  is_live       TINYINT(1) NOT NULL DEFAULT 1,
  status        ENUM('online','offline','fault') NOT NULL DEFAULT 'online'
) ENGINE=InnoDB;

CREATE TABLE arff_resources (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  unit_code           VARCHAR(30) NOT NULL UNIQUE,
  name                VARCHAR(80) NOT NULL,
  water_capacity_gal  INT NULL,
  water_level_pct     TINYINT NULL,
  status              ENUM('ready','deployed','maintenance') NOT NULL DEFAULT 'ready'
) ENGINE=InnoDB;

CREATE TABLE weather_hourly (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  observed_at      DATETIME NOT NULL UNIQUE,
  temp_c           DECIMAL(4,1) NULL,
  condition_code   VARCHAR(30) NULL,
  condition_label  VARCHAR(60) NULL,
  wind_dir_deg     SMALLINT NULL,
  wind_kt          SMALLINT NULL,
  visibility_sm    DECIMAL(4,1) NULL,
  ceiling_ft       INT NULL,
  impact_level     ENUM('none','low','medium','high') NOT NULL DEFAULT 'none'
) ENGINE=InnoDB;

CREATE TABLE airport_kpis (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  op_date          DATE NOT NULL UNIQUE,
  ops_total        INT NOT NULL DEFAULT 0,
  takeoffs         INT NOT NULL DEFAULT 0,
  landings         INT NOT NULL DEFAULT 0,
  otp_pct          DECIMAL(5,2) NULL,
  gates_used       SMALLINT NULL,
  active_alerts    SMALLINT NOT NULL DEFAULT 0,
  pax_today        INT NULL,
  security_status  VARCHAR(40) DEFAULT 'SECURE'
) ENGINE=InnoDB;

CREATE TABLE citizen_reports (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title       VARCHAR(160) NOT NULL,
  detail      TEXT NULL,
  level       ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  location    VARCHAR(120) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type       ENUM('info','success','warning','danger','primary') NOT NULL DEFAULT 'info',
  title      VARCHAR(160) NOT NULL,
  message    TEXT NULL,
  is_read    TINYINT(1) NOT NULL DEFAULT 0,
  is_critical TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE terminal_settings (
  terminal   CHAR(1) PRIMARY KEY,
  type       ENUM('domestic','international') NOT NULL DEFAULT 'domestic',
  continent  VARCHAR(30) NULL
) ENGINE=InnoDB;

CREATE TABLE system_state (
  id              TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  sim_tick        INT UNSIGNED NOT NULL DEFAULT 0,
  last_tick_at    DATETIME NULL,
  evacuation_active TINYINT(1) NOT NULL DEFAULT 0,
  critical_mode   TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO system_state (id, sim_tick) VALUES (1, 0);


CREATE TABLE parking_lots (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  lot_type      ENUM('daily_deck','hourly','park_ride','economy','select','oversized','other') NOT NULL DEFAULT 'other',
  covered       TINYINT(1) NOT NULL DEFAULT 0,
  capacity      INT UNSIGNED NOT NULL DEFAULT 0,
  occupied      INT UNSIGNED NOT NULL DEFAULT 0,
  rate_hourly   DECIMAL(6,2) NULL,
  rate_daily    DECIMAL(6,2) NULL,
  terminal_link VARCHAR(80) NULL,
  notes         VARCHAR(255) NULL,
  status        ENUM('open','full','closed','maintenance') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB;

CREATE TABLE transit_stations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(30) NOT NULL UNIQUE,
  name          VARCHAR(120) NOT NULL,
  station_type  ENUM('marta_rail','plane_train','bus','taxi_queue','van_queue','shuttle','other') NOT NULL DEFAULT 'other',
  location      VARCHAR(160) NULL,
  lines_served  VARCHAR(120) NULL,
  status        ENUM('open','closed','maintenance') NOT NULL DEFAULT 'open'
) ENGINE=InnoDB;

CREATE TABLE ground_fleet (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fleet_type    ENUM('taxi','van','bus','shuttle') NOT NULL,
  service_class ENUM('economy','standard','vip','executive','minivan','shared','regional','coach','airport_shuttle','parking_shuttle','marta') NOT NULL DEFAULT 'standard',
  company       VARCHAR(120) NULL,
  model         VARCHAR(80) NULL,
  capacity_pax  SMALLINT UNSIGNED NULL,
  unit_count    INT UNSIGNED NOT NULL DEFAULT 0,
  on_site_now   INT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('active','limited','offline') NOT NULL DEFAULT 'active',
  fare_note     VARCHAR(255) NULL,
  notes         VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE transit_fares (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mode          VARCHAR(40) NOT NULL,
  service_class VARCHAR(40) NULL,
  route_label   VARCHAR(120) NULL,
  fare_min      DECIMAL(8,2) NULL,
  fare_max      DECIMAL(8,2) NULL,
  fare_flat     DECIMAL(8,2) NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'USD',
  notes         VARCHAR(255) NULL
) ENGINE=InnoDB;




-- Individual ground vehicles (taxi / van / bus)
CREATE TABLE ground_vehicles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fleet_type    ENUM('taxi','van','bus') NOT NULL,
  service_class ENUM('economy','standard','vip','executive','minivan','shared','regional','coach','airport_shuttle','parking_shuttle','marta') NOT NULL DEFAULT 'standard',
  vehicle_code  VARCHAR(30) NOT NULL,
  plate_number  VARCHAR(20) NOT NULL,
  manufacturer  VARCHAR(60) NULL,
  model         VARCHAR(80) NOT NULL,
  capacity_pax  SMALLINT UNSIGNED NOT NULL DEFAULT 4,
  driver_name   VARCHAR(120) NULL,
  trips_today   INT UNSIGNED NOT NULL DEFAULT 0,
  status        ENUM('available','on_trip','offline','maintenance') NOT NULL DEFAULT 'available',
  company       VARCHAR(120) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gv_code (vehicle_code),
  KEY idx_gv_type (fleet_type, service_class),
  KEY idx_gv_status (status)
) ENGINE=InnoDB;

-- Vehicles currently in parking lots
CREATE TABLE parking_vehicles (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  lot_id          INT UNSIGNED NOT NULL,
  plate_number    VARCHAR(20) NOT NULL,
  owner_name      VARCHAR(120) NULL,
  manufacturer    VARCHAR(60) NULL,
  model           VARCHAR(80) NULL,
  status          ENUM('parked','entering','exiting') NOT NULL DEFAULT 'parked',
  duration_hours  DECIMAL(8,1) NULL,
  duration_days   DECIMAL(6,1) NULL,
  parked_at       DATETIME NULL,
  expected_leave  DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_pv_lot (lot_id),
  KEY idx_pv_plate (plate_number),
  CONSTRAINT fk_pv_lot FOREIGN KEY (lot_id) REFERENCES parking_lots(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Daily transit counters (since midnight Atlanta)
CREATE TABLE transit_daily_stats (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stat_date     DATE NOT NULL,
  mode          VARCHAR(30) NOT NULL,
  service_class VARCHAR(40) NULL,
  trips_count   INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_tds (stat_date, mode, service_class)
) ENGINE=InnoDB;

-- ============================================================
-- Transit seed data (from transit_only)
-- ============================================================

INSERT INTO transit_lines (code, name, status, speed_mph, capacity_per_trip, avg_load_pct, route_label) VALUES
('RED','MARTA Red Line','on',35,180,62,'North Springs ↔ Airport (ATL)'),
('GOLD','MARTA Gold Line','on',35,180,58,'Doraville ↔ Airport (ATL)'),
('PLANE','ATL Plane Train','on',25,250,70,'Domestic Terminal ↔ Concourses T–F');

INSERT INTO transit_stations (code, name, station_type, location, lines_served, status) VALUES
('MARTA-ATL','MARTA Airport Station','marta_rail','West side Domestic Terminal between North/South baggage claim','Red, Gold','open'),
('PT-DOM','Plane Train · Domestic','plane_train','Domestic Terminal (post-security)','Plane Train','open'),
('PT-T','Plane Train · Concourse T','plane_train','Concourse T','Plane Train','open'),
('PT-A','Plane Train · Concourse A','plane_train','Concourse A','Plane Train','open'),
('PT-B','Plane Train · Concourse B','plane_train','Concourse B','Plane Train','open'),
('PT-C','Plane Train · Concourse C','plane_train','Concourse C','Plane Train','open'),
('PT-D','Plane Train · Concourse D','plane_train','Concourse D','Plane Train','open'),
('PT-E','Plane Train · Concourse E','plane_train','Concourse E','Plane Train','open'),
('PT-F','Plane Train · Concourse F / International','plane_train','Maynard H. Jackson Jr. International Terminal','Plane Train','open'),
('GTC-DOM','Ground Transportation Center · Domestic','taxi_queue','Ground level Domestic Terminal','Taxi, Van, Shuttle','open'),
('GTC-INT','Ground Transportation Center · International','taxi_queue','International Terminal curb','Taxi, Van, Shuttle','open'),
('BUS-PARK-N','Parking Shuttle · North Economy','bus','North Economy Lots','Parking shuttle','open'),
('BUS-PARK-S','Parking Shuttle · South Economy','bus','South Economy / Park-Ride','Parking shuttle','open'),
('BUS-MARTA','MARTA Bus bays','bus','Adjacent to MARTA Airport Station','MARTA bus','open'),
('VAN-QUEUE','Shared-ride / Van queue','van_queue','Domestic Ground Transportation','Van','open');

INSERT INTO ground_fleet (fleet_type, service_class, company, model, capacity_pax, unit_count, on_site_now, status, fare_note, notes) VALUES
('taxi','economy','Yellow Cab / Checker / ATA','Toyota Camry',4,1100,220,'active','Flat $36 Downtown / $38 Midtown / $48 Buckhead','Authorized Standard Sedan · ~1,500–1,600 total licensed'),
('taxi','minivan','Yellow Cab / Checker / ATA','Toyota Sienna',6,280,55,'active','Meter + $2.50 entry + $1.50 airport fee','Accessible / Minivan · 5–6 pax'),
('van','shared','Atlanta Hotels Connection','Mercedes-Benz Sprinter / Ford Transit',14,250,40,'active','$16.50–$30 per person','Local Shared Ride · 200–300 units'),
('van','executive','Private Executive','Mercedes-Benz Sprinter',12,60,12,'active','$120–$250 whole van','Private VIP Sprinter'),
('van','regional','Groome Transportation','Ford Transit / E-Series Cutaway',20,175,30,'active','$39–$89 per person','Regional shuttle · 150–200 units'),
('bus','marta','MARTA','New Flyer Xcelsior / GILLIG',50,25,8,'active','$2.50 flat','City bus · 4–6 / hour · 20+ GTC platforms'),
('bus','coach','Greyhound / Megabus / SES','MCI J4500 / Prevost H3-45',55,18,4,'active','$15–$60 by distance','Intercity coach · 4–8 / hour'),
('bus','airport_shuttle','ATL Airport (owned)','New Flyer / Gillig Low Floor',50,25,12,'active','Free','Terminal Connector · Domestic↔International · 20–30 units'),
('bus','parking_shuttle','ATL Airport (owned)','Ford/GMC Cutaway',20,60,20,'active','Free','Parking shuttles · Economy / ATL West · 50–70 units');

INSERT INTO transit_fares (mode, service_class, route_label, fare_min, fare_max, fare_flat, currency, notes) VALUES
('taxi','economy','Downtown flat (Sedan)',36.00,36.00,36.00,'USD','Authorized partner flat rate'),
('taxi','economy','Midtown flat (Sedan)',38.00,38.00,38.00,'USD','Authorized partner flat rate'),
('taxi','economy','Buckhead flat (Sedan)',48.00,48.00,48.00,'USD','Authorized partner flat rate'),
('taxi','minivan','Accessible / Minivan',36.00,60.00,NULL,'USD','Meter + $2.50 entry + $1.50 airport fee + $2 extra pax'),
('van','shared','Local shared Downtown',16.50,16.50,16.50,'USD','Per person · Hotels Connection'),
('van','shared','Local shared Midtown',18.50,18.50,18.50,'USD','Per person'),
('van','shared','Local shared Buckhead',30.00,30.00,30.00,'USD','Per person'),
('van','executive','Private Executive Sprinter',120.00,250.00,NULL,'USD','Whole van VIP'),
('van','regional','Groome regional',39.00,89.00,NULL,'USD','Per person by destination'),
('bus','airport_shuttle','Terminal connector (owned)',0.00,0.00,0.00,'USD','Airport-owned free'),
('bus','parking_shuttle','Parking shuttle (owned)',0.00,0.00,0.00,'USD','Airport-owned free'),
('bus','marta','MARTA bus one-way',2.50,2.50,2.50,'USD','City transit'),
('bus','coach','Intercity coach',15.00,60.00,NULL,'USD','Greyhound / Megabus / SES'),
('marta','marta_rail','MARTA rail one-way',2.50,2.50,2.50,'USD','Breeze Card +$2 first issue'),
('plane_train','plane_train','Plane Train airside',0.00,0.00,0.00,'USD','Airport-owned free'),
('skytrain','skytrain','ATL SkyTrain landside',0.00,0.00,0.00,'USD','Airport-owned free');

INSERT INTO parking_lots (code, name, lot_type, covered, capacity, occupied, rate_hourly, rate_daily, terminal_link, notes, status) VALUES
('DAILY_N','Daily North Deck','daily_deck',1,6200,4800,3.00,32.00,'Domestic Terminal North','4-level covered','open'),
('DAILY_S','Daily South Deck','daily_deck',1,6200,5100,3.00,32.00,'Domestic Terminal South','4-level covered','open'),
('ATL_WEST','ATL West Deck','daily_deck',1,5700,4200,2.50,28.00,'SkyTrain to Domestic','Modern deck + SkyTrain','open'),
('INTL_HRLY','International Hourly Deck','hourly',1,1100,620,4.00,NULL,'International Terminal','Short-term','open'),
('INTL_PR','International Park-Ride','park_ride',1,2400,1800,NULL,18.00,'International Terminal','Park and ride','open'),
('ECON_N','North Economy','economy',0,1500,980,NULL,14.00,'Shuttle to Domestic','Surface economy','open'),
('DOM_PR','Domestic Park-Ride','park_ride',0,4800,3600,NULL,16.00,'Shuttle to Domestic','Surface park-ride','open'),
('ATL_SEL','ATL Select','select',1,1500,900,NULL,22.00,'Domestic','Covered + oversized mix','open'),
('ECON_S','South Economy','economy',0,1100,720,NULL,12.00,'Shuttle to Domestic','Surface south','open');

INSERT INTO ground_vehicles (fleet_type, service_class, vehicle_code, plate_number, manufacturer, model, capacity_pax, driver_name, trips_today, status, company) VALUES
('taxi','economy','TX0001','ATL1001','Toyota','Camry',4,'Maria Garcia',3,'available','Atlanta Airport Cab'),
('taxi','economy','TX0002','ATL1002','Toyota','Camry',4,'Robert Chen',4,'available','Atlanta Airport Cab'),
('taxi','economy','TX0003','ATL1003','Toyota','Camry',4,'Sarah Patel',5,'available','Atlanta Airport Cab'),
('taxi','economy','TX0004','ATL1004','Toyota','Camry',4,'Michael Brown',6,'available','Atlanta Airport Cab'),
('taxi','economy','TX0005','ATL1005','Toyota','Camry',4,'Emily Davis',7,'available','Atlanta Airport Cab'),
('taxi','economy','TX0006','ATL1006','Toyota','Camry',4,'David Kim',8,'available','Atlanta Airport Cab'),
('taxi','economy','TX0007','ATL1007','Toyota','Camry',4,'Jessica Lopez',9,'available','Atlanta Airport Cab'),
('taxi','economy','TX0008','ATL1008','Toyota','Camry',4,'William Taylor',10,'available','Atlanta Airport Cab'),
('taxi','economy','TX0009','ATL1009','Toyota','Camry',4,'Ashley Martinez',11,'on_trip','Atlanta Airport Cab'),
('taxi','economy','TX0010','ATL1010','Toyota','Camry',4,'Chris Nguyen',12,'available','Atlanta Airport Cab'),
('taxi','economy','TX0011','ATL1011','Toyota','Camry',4,'Amanda Lee',13,'available','Atlanta Airport Cab'),
('taxi','economy','TX0012','ATL1012','Toyota','Camry',4,'Daniel Brooks',14,'available','Atlanta Airport Cab'),
('taxi','economy','TX0013','ATL1013','Toyota','Camry',4,'Melissa Torres',15,'available','Atlanta Airport Cab'),
('taxi','economy','TX0014','ATL1014','Toyota','Camry',4,'Matthew Wright',16,'available','Atlanta Airport Cab'),
('taxi','economy','TX0015','ATL1015','Toyota','Camry',4,'James Wilson',2,'available','Atlanta Airport Cab'),
('taxi','economy','TX0016','ATL1016','Toyota','Camry',4,'Maria Garcia',3,'available','Atlanta Airport Cab'),
('taxi','economy','TX0017','ATL1017','Toyota','Camry',4,'Robert Chen',4,'available','Atlanta Airport Cab'),
('taxi','economy','TX0018','ATL1018','Toyota','Camry',4,'Sarah Patel',5,'on_trip','Atlanta Airport Cab'),
('taxi','economy','TX0019','ATL1019','Toyota','Camry',4,'Michael Brown',6,'available','Atlanta Airport Cab'),
('taxi','economy','TX0020','ATL1020','Toyota','Camry',4,'Emily Davis',7,'available','Atlanta Airport Cab'),
('taxi','economy','TX0021','ATL1021','Toyota','Camry',4,'David Kim',8,'available','Atlanta Airport Cab'),
('taxi','economy','TX0022','ATL1022','Toyota','Camry',4,'Jessica Lopez',9,'available','Atlanta Airport Cab'),
('taxi','economy','TX0023','ATL1023','Toyota','Camry',4,'William Taylor',10,'available','Atlanta Airport Cab'),
('taxi','economy','TX0024','ATL1024','Toyota','Camry',4,'Ashley Martinez',11,'available','Atlanta Airport Cab'),
('taxi','economy','TX0025','ATL1025','Toyota','Camry',4,'Chris Nguyen',12,'available','Atlanta Airport Cab'),
('taxi','economy','TX0026','ATL1026','Toyota','Camry',4,'Amanda Lee',13,'available','Atlanta Airport Cab'),
('taxi','economy','TX0027','ATL1027','Toyota','Camry',4,'Daniel Brooks',14,'on_trip','Atlanta Airport Cab'),
('taxi','economy','TX0028','ATL1028','Toyota','Camry',4,'Melissa Torres',15,'available','Atlanta Airport Cab'),
('taxi','economy','TX0029','ATL1029','Toyota','Camry',4,'Matthew Wright',16,'available','Atlanta Airport Cab'),
('taxi','economy','TX0030','ATL1030','Toyota','Camry',4,'James Wilson',2,'available','Atlanta Airport Cab'),
('taxi','economy','TX0031','ATL1031','Toyota','Camry',4,'Maria Garcia',3,'available','Atlanta Airport Cab'),
('taxi','economy','TX0032','ATL1032','Toyota','Camry',4,'Robert Chen',4,'available','Atlanta Airport Cab'),
('taxi','economy','TX0033','ATL1033','Toyota','Camry',4,'Sarah Patel',5,'available','Atlanta Airport Cab'),
('taxi','economy','TX0034','ATL1034','Toyota','Camry',4,'Michael Brown',6,'available','Atlanta Airport Cab'),
('taxi','economy','TX0035','ATL1035','Toyota','Camry',4,'Emily Davis',7,'available','Atlanta Airport Cab'),
('taxi','economy','TX0036','ATL1036','Toyota','Camry',4,'David Kim',8,'on_trip','Atlanta Airport Cab'),
('taxi','economy','TX0037','ATL1037','Toyota','Camry',4,'Jessica Lopez',9,'available','Atlanta Airport Cab'),
('taxi','economy','TX0038','ATL1038','Toyota','Camry',4,'William Taylor',10,'available','Atlanta Airport Cab'),
('taxi','economy','TX0039','ATL1039','Toyota','Camry',4,'Ashley Martinez',11,'available','Atlanta Airport Cab'),
('taxi','economy','TX0040','ATL1040','Toyota','Camry',4,'Chris Nguyen',12,'available','Atlanta Airport Cab'),
('taxi','economy','TX0041','ATL1041','Honda','Accord',4,'Amanda Lee',6,'available','Atlanta Airport Cab'),
('taxi','economy','TX0042','ATL1042','Honda','Accord',4,'Daniel Brooks',7,'available','Atlanta Airport Cab'),
('taxi','economy','TX0043','ATL1043','Honda','Accord',4,'Melissa Torres',8,'available','Atlanta Airport Cab'),
('taxi','economy','TX0044','ATL1044','Honda','Accord',4,'Matthew Wright',9,'available','Atlanta Airport Cab'),
('taxi','economy','TX0045','ATL1045','Honda','Accord',4,'James Wilson',10,'available','Atlanta Airport Cab'),
('taxi','economy','TX0046','ATL1046','Honda','Accord',4,'Maria Garcia',11,'available','Atlanta Airport Cab'),
('taxi','economy','TX0047','ATL1047','Honda','Accord',4,'Robert Chen',12,'available','Atlanta Airport Cab'),
('taxi','economy','TX0048','ATL1048','Honda','Accord',4,'Sarah Patel',1,'available','Atlanta Airport Cab'),
('taxi','economy','TX0049','ATL1049','Honda','Accord',4,'Michael Brown',2,'available','Atlanta Airport Cab'),
('taxi','economy','TX0050','ATL1050','Honda','Accord',4,'Emily Davis',3,'available','Atlanta Airport Cab'),
('taxi','economy','TX0051','ATL1051','Honda','Accord',4,'David Kim',4,'available','Atlanta Airport Cab'),
('taxi','economy','TX0052','ATL1052','Honda','Accord',4,'Jessica Lopez',5,'available','Atlanta Airport Cab'),
('taxi','economy','TX0053','ATL1053','Honda','Accord',4,'William Taylor',6,'available','Atlanta Airport Cab'),
('taxi','economy','TX0054','ATL1054','Honda','Accord',4,'Ashley Martinez',7,'available','Atlanta Airport Cab'),
('taxi','economy','TX0055','ATL1055','Honda','Accord',4,'Chris Nguyen',8,'available','Atlanta Airport Cab'),
('taxi','economy','TX0056','ATL1056','Honda','Accord',4,'Amanda Lee',9,'available','Atlanta Airport Cab'),
('taxi','economy','TX0057','ATL1057','Honda','Accord',4,'Daniel Brooks',10,'available','Atlanta Airport Cab'),
('taxi','economy','TX0058','ATL1058','Honda','Accord',4,'Melissa Torres',11,'available','Atlanta Airport Cab'),
('taxi','economy','TX0059','ATL1059','Honda','Accord',4,'Matthew Wright',12,'available','Atlanta Airport Cab'),
('taxi','economy','TX0060','ATL1060','Honda','Accord',4,'James Wilson',1,'available','Atlanta Airport Cab'),
('taxi','standard','TX0061','ATL1061','Toyota','Camry Hybrid',4,'Maria Garcia',8,'available','Atlanta Airport Cab'),
('taxi','standard','TX0062','ATL1062','Toyota','Camry Hybrid',4,'Robert Chen',9,'available','Atlanta Airport Cab'),
('taxi','standard','TX0063','ATL1063','Toyota','Camry Hybrid',4,'Sarah Patel',10,'available','Atlanta Airport Cab'),
('taxi','standard','TX0064','ATL1064','Toyota','Camry Hybrid',4,'Michael Brown',3,'available','Atlanta Airport Cab'),
('taxi','standard','TX0065','ATL1065','Toyota','Camry Hybrid',4,'Emily Davis',4,'available','Atlanta Airport Cab'),
('taxi','standard','TX0066','ATL1066','Toyota','Camry Hybrid',4,'David Kim',5,'available','Atlanta Airport Cab'),
('taxi','standard','TX0067','ATL1067','Toyota','Camry Hybrid',4,'Jessica Lopez',6,'available','Atlanta Airport Cab'),
('taxi','standard','TX0068','ATL1068','Toyota','Camry Hybrid',4,'William Taylor',7,'available','Atlanta Airport Cab'),
('taxi','standard','TX0069','ATL1069','Toyota','Camry Hybrid',4,'Ashley Martinez',8,'available','Atlanta Airport Cab'),
('taxi','standard','TX0070','ATL1070','Toyota','Camry Hybrid',4,'Chris Nguyen',9,'available','Atlanta Airport Cab'),
('taxi','vip','VIP0071','BLK2071','Cadillac','Escalade',7,'Amanda Lee',6,'available','Black Car ATL'),
('taxi','vip','VIP0072','BLK2072','Lincoln','Town Car',4,'Daniel Brooks',1,'available','Black Car ATL'),
('taxi','vip','VIP0073','BLK2073','Mercedes-Benz','S-Class',4,'Melissa Torres',2,'available','Black Car ATL'),
('taxi','vip','VIP0074','BLK2074','Chevrolet','Suburban',7,'Matthew Wright',3,'available','Black Car ATL'),
('taxi','vip','VIP0075','BLK2075','Cadillac','Escalade',7,'James Wilson',4,'on_trip','Black Car ATL'),
('taxi','vip','VIP0076','BLK2076','Lincoln','Town Car',4,'Maria Garcia',5,'available','Black Car ATL'),
('taxi','vip','VIP0077','BLK2077','Mercedes-Benz','S-Class',4,'Robert Chen',6,'available','Black Car ATL'),
('taxi','vip','VIP0078','BLK2078','Chevrolet','Suburban',7,'Sarah Patel',1,'available','Black Car ATL'),
('taxi','vip','VIP0079','BLK2079','Cadillac','Escalade',7,'Michael Brown',2,'available','Black Car ATL'),
('taxi','vip','VIP0080','BLK2080','Lincoln','Town Car',4,'Emily Davis',3,'on_trip','Black Car ATL'),
('van','economy','VN0001','VAN3001','Ford','Transit',9,'Maria Garcia',3,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0002','VAN3002','Ford','Transit',9,'Robert Chen',4,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0003','VAN3003','Ford','Transit',9,'Sarah Patel',5,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0004','VAN3004','Ford','Transit',9,'Michael Brown',6,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0005','VAN3005','Ford','Transit',9,'Emily Davis',7,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0006','VAN3006','Ford','Transit',9,'David Kim',8,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0007','VAN3007','Ford','Transit',9,'Jessica Lopez',9,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0008','VAN3008','Ford','Transit',9,'William Taylor',10,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0009','VAN3009','Ford','Transit',9,'Ashley Martinez',11,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0010','VAN3010','Ford','Transit',9,'Chris Nguyen',2,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0011','VAN3011','Ford','Transit',9,'Amanda Lee',3,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0012','VAN3012','Ford','Transit',9,'Daniel Brooks',4,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0013','VAN3013','Ford','Transit',9,'Melissa Torres',5,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0014','VAN3014','Ford','Transit',9,'Matthew Wright',6,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0015','VAN3015','Ford','Transit',9,'James Wilson',7,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0016','VAN3016','Ford','Transit',9,'Maria Garcia',8,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0017','VAN3017','Ford','Transit',9,'Robert Chen',9,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0018','VAN3018','Ford','Transit',9,'Sarah Patel',10,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0019','VAN3019','Ford','Transit',9,'Michael Brown',11,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0020','VAN3020','Ford','Transit',9,'Emily Davis',2,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0021','VAN3021','Ford','Transit',9,'David Kim',3,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0022','VAN3022','Ford','Transit',9,'Jessica Lopez',4,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0023','VAN3023','Ford','Transit',9,'William Taylor',5,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0024','VAN3024','Ford','Transit',9,'Ashley Martinez',6,'available','Atlanta Airport Cab / GWT'),
('van','economy','VN0025','VAN3025','Ford','Transit',9,'Chris Nguyen',7,'available','Atlanta Airport Cab / GWT'),
('van','standard','VN0026','VAN3026','Ford','Transit Passenger',8,'Amanda Lee',6,'available','GWT'),
('van','standard','VN0027','VAN3027','Ford','Transit Passenger',8,'Daniel Brooks',7,'available','GWT'),
('van','standard','VN0028','VAN3028','Ford','Transit Passenger',8,'Melissa Torres',1,'available','GWT'),
('van','standard','VN0029','VAN3029','Ford','Transit Passenger',8,'Matthew Wright',2,'available','GWT'),
('van','standard','VN0030','VAN3030','Ford','Transit Passenger',8,'James Wilson',3,'available','GWT'),
('van','standard','VN0031','VAN3031','Ford','Transit Passenger',8,'Maria Garcia',4,'available','GWT'),
('van','standard','VN0032','VAN3032','Ford','Transit Passenger',8,'Robert Chen',5,'available','GWT'),
('van','standard','VN0033','VAN3033','Ford','Transit Passenger',8,'Sarah Patel',6,'available','GWT'),
('van','standard','VN0034','VAN3034','Ford','Transit Passenger',8,'Michael Brown',7,'available','GWT'),
('van','standard','VN0035','VAN3035','Ford','Transit Passenger',8,'Emily Davis',1,'available','GWT'),
('van','executive','VN0036','SPR4036','Mercedes-Benz','Sprinter',12,'David Kim',2,'available','GWT Executive'),
('van','executive','VN0037','SPR4037','Mercedes-Benz','Sprinter',12,'Jessica Lopez',3,'available','GWT Executive'),
('van','executive','VN0038','SPR4038','Mercedes-Benz','Sprinter',12,'William Taylor',4,'available','GWT Executive'),
('van','executive','VN0039','SPR4039','Mercedes-Benz','Sprinter',12,'Ashley Martinez',5,'available','GWT Executive'),
('van','executive','VN0040','SPR4040','Mercedes-Benz','Sprinter',12,'Chris Nguyen',1,'available','GWT Executive'),
('van','executive','VN0041','SPR4041','Mercedes-Benz','Sprinter',12,'Amanda Lee',2,'available','GWT Executive'),
('van','executive','VN0042','SPR4042','Mercedes-Benz','Sprinter',12,'Daniel Brooks',3,'available','GWT Executive'),
('van','executive','VN0043','SPR4043','Mercedes-Benz','Sprinter',12,'Melissa Torres',4,'available','GWT Executive'),
('van','executive','VN0044','SPR4044','Mercedes-Benz','Sprinter',12,'Matthew Wright',5,'available','GWT Executive'),
('van','executive','VN0045','SPR4045','Mercedes-Benz','Sprinter',12,'James Wilson',1,'available','GWT Executive'),
('bus','airport_shuttle','BS0001','SHT5001','New Flyer','Low Floor',45,'Maria Garcia',5,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0002','SHT5002','New Flyer','Low Floor',45,'Robert Chen',6,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0003','SHT5003','New Flyer','Low Floor',45,'Sarah Patel',7,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0004','SHT5004','New Flyer','Low Floor',45,'Michael Brown',8,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0005','SHT5005','New Flyer','Low Floor',45,'Emily Davis',9,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0006','SHT5006','New Flyer','Low Floor',45,'David Kim',10,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0007','SHT5007','New Flyer','Low Floor',45,'Jessica Lopez',11,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0008','SHT5008','New Flyer','Low Floor',45,'William Taylor',4,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0009','SHT5009','New Flyer','Low Floor',45,'Ashley Martinez',5,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0010','SHT5010','New Flyer','Low Floor',45,'Chris Nguyen',6,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0011','SHT5011','New Flyer','Low Floor',45,'Amanda Lee',7,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0012','SHT5012','New Flyer','Low Floor',45,'Daniel Brooks',8,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0013','SHT5013','New Flyer','Low Floor',45,'Melissa Torres',9,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0014','SHT5014','New Flyer','Low Floor',45,'Matthew Wright',10,'available','ATL Airport Shuttle'),
('bus','airport_shuttle','BS0015','SHT5015','New Flyer','Low Floor',45,'James Wilson',11,'available','ATL Airport Shuttle'),
('bus','parking_shuttle','BS0016','PRK5016','Ford','E-Series Shuttle',20,'Maria Garcia',11,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0017','PRK5017','Ford','E-Series Shuttle',20,'Robert Chen',12,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0018','PRK5018','Ford','E-Series Shuttle',20,'Sarah Patel',13,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0019','PRK5019','Ford','E-Series Shuttle',20,'Michael Brown',14,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0020','PRK5020','Ford','E-Series Shuttle',20,'Emily Davis',5,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0021','PRK5021','Ford','E-Series Shuttle',20,'David Kim',6,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0022','PRK5022','Ford','E-Series Shuttle',20,'Jessica Lopez',7,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0023','PRK5023','Ford','E-Series Shuttle',20,'William Taylor',8,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0024','PRK5024','Ford','E-Series Shuttle',20,'Ashley Martinez',9,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0025','PRK5025','Ford','E-Series Shuttle',20,'Chris Nguyen',10,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0026','PRK5026','Ford','E-Series Shuttle',20,'Amanda Lee',11,'available','ATL Parking Shuttle'),
('bus','parking_shuttle','BS0027','PRK5027','Ford','E-Series Shuttle',20,'Daniel Brooks',12,'available','ATL Parking Shuttle'),
('bus','marta','BS0028','MRT5028','Gillig','Low Floor',45,'Melissa Torres',7,'available','MARTA'),
('bus','marta','BS0029','MRT5029','Gillig','Low Floor',45,'Matthew Wright',8,'available','MARTA'),
('bus','marta','BS0030','MRT5030','Gillig','Low Floor',45,'James Wilson',3,'available','MARTA'),
('bus','marta','BS0031','MRT5031','Gillig','Low Floor',45,'Maria Garcia',4,'available','MARTA'),
('bus','marta','BS0032','MRT5032','Gillig','Low Floor',45,'Robert Chen',5,'available','MARTA'),
('bus','marta','BS0033','MRT5033','Gillig','Low Floor',45,'Sarah Patel',6,'available','MARTA'),
('bus','marta','BS0034','MRT5034','Gillig','Low Floor',45,'Michael Brown',7,'available','MARTA'),
('bus','marta','BS0035','MRT5035','Gillig','Low Floor',45,'Emily Davis',8,'available','MARTA');

INSERT INTO parking_vehicles (lot_id, plate_number, owner_name, manufacturer, model, status, duration_hours, duration_days, parked_at) VALUES
(1,'GA1001','John Reed','Ford','F-150','parked',5.0,NULL,NOW() - INTERVAL 6.0 HOUR),
(1,'GA1002','Helen Cho','Tesla','Model 3','entering',6.0,NULL,NOW() - INTERVAL 7.0 HOUR),
(1,'GA1003','Carlos Diaz','BMW','X5','exiting',7.0,2.0,NOW() - INTERVAL 8.0 HOUR),
(1,'GA1004','Nina Brooks','Chevrolet','Equinox','parked',8.0,NULL,NOW() - INTERVAL 9.0 HOUR),
(1,'GA1005','Tom Hardy','Toyota','RAV4','parked',9.0,NULL,NOW() - INTERVAL 10.0 HOUR),
(1,'GA1006','Zoe Park','Honda','CR-V','parked',10.0,2.0,NOW() - INTERVAL 11.0 HOUR),
(1,'GA1007','Alex Morgan','Ford','F-150','entering',11.0,NULL,NOW() - INTERVAL 12.0 HOUR),
(2,'GA2001','Helen Cho','Tesla','Model 3','entering',5.0,NULL,NOW() - INTERVAL 7.0 HOUR),
(2,'GA2002','Carlos Diaz','BMW','X5','exiting',6.0,NULL,NOW() - INTERVAL 8.0 HOUR),
(2,'GA2003','Nina Brooks','Chevrolet','Equinox','parked',7.0,2.0,NOW() - INTERVAL 9.0 HOUR),
(2,'GA2004','Tom Hardy','Toyota','RAV4','parked',8.0,NULL,NOW() - INTERVAL 10.0 HOUR),
(2,'GA2005','Zoe Park','Honda','CR-V','parked',9.0,NULL,NOW() - INTERVAL 11.0 HOUR),
(2,'GA2006','Alex Morgan','Ford','F-150','entering',10.0,2.0,NOW() - INTERVAL 12.0 HOUR),
(2,'GA2007','Priya Shah','Tesla','Model 3','exiting',11.0,NULL,NOW() - INTERVAL 13.0 HOUR),
(3,'GA3001','Carlos Diaz','BMW','X5','exiting',5.0,NULL,NOW() - INTERVAL 8.0 HOUR),
(3,'GA3002','Nina Brooks','Chevrolet','Equinox','parked',6.0,NULL,NOW() - INTERVAL 9.0 HOUR),
(3,'GA3003','Tom Hardy','Toyota','RAV4','parked',7.0,2.0,NOW() - INTERVAL 10.0 HOUR),
(3,'GA3004','Zoe Park','Honda','CR-V','parked',8.0,NULL,NOW() - INTERVAL 11.0 HOUR),
(3,'GA3005','Alex Morgan','Ford','F-150','entering',9.0,NULL,NOW() - INTERVAL 12.0 HOUR),
(3,'GA3006','Priya Shah','Tesla','Model 3','exiting',10.0,2.0,NOW() - INTERVAL 13.0 HOUR),
(3,'GA3007','John Reed','BMW','X5','parked',11.0,NULL,NOW() - INTERVAL 14.0 HOUR),
(4,'GA4001','Nina Brooks','Chevrolet','Equinox','parked',5.0,NULL,NOW() - INTERVAL 9.0 HOUR),
(4,'GA4002','Tom Hardy','Toyota','RAV4','parked',6.0,NULL,NOW() - INTERVAL 10.0 HOUR),
(4,'GA4003','Zoe Park','Honda','CR-V','parked',7.0,2.0,NOW() - INTERVAL 11.0 HOUR),
(4,'GA4004','Alex Morgan','Ford','F-150','entering',8.0,NULL,NOW() - INTERVAL 12.0 HOUR),
(4,'GA4005','Priya Shah','Tesla','Model 3','exiting',9.0,NULL,NOW() - INTERVAL 13.0 HOUR),
(4,'GA4006','John Reed','BMW','X5','parked',10.0,2.0,NOW() - INTERVAL 14.0 HOUR),
(4,'GA4007','Helen Cho','Chevrolet','Equinox','parked',11.0,NULL,NOW() - INTERVAL 15.0 HOUR),
(5,'GA5001','Tom Hardy','Toyota','RAV4','parked',5.0,NULL,NOW() - INTERVAL 10.0 HOUR),
(5,'GA5002','Zoe Park','Honda','CR-V','parked',6.0,NULL,NOW() - INTERVAL 11.0 HOUR),
(5,'GA5003','Alex Morgan','Ford','F-150','entering',7.0,2.0,NOW() - INTERVAL 12.0 HOUR),
(5,'GA5004','Priya Shah','Tesla','Model 3','exiting',8.0,NULL,NOW() - INTERVAL 13.0 HOUR),
(5,'GA5005','John Reed','BMW','X5','parked',9.0,NULL,NOW() - INTERVAL 14.0 HOUR),
(5,'GA5006','Helen Cho','Chevrolet','Equinox','parked',10.0,2.0,NOW() - INTERVAL 15.0 HOUR),
(5,'GA5007','Carlos Diaz','Toyota','RAV4','parked',11.0,NULL,NOW() - INTERVAL 16.0 HOUR),
(6,'GA6001','Zoe Park','Honda','CR-V','parked',5.0,NULL,NOW() - INTERVAL 11.0 HOUR),
(6,'GA6002','Alex Morgan','Ford','F-150','entering',6.0,NULL,NOW() - INTERVAL 12.0 HOUR),
(6,'GA6003','Priya Shah','Tesla','Model 3','exiting',7.0,2.0,NOW() - INTERVAL 13.0 HOUR),
(6,'GA6004','John Reed','BMW','X5','parked',8.0,NULL,NOW() - INTERVAL 14.0 HOUR),
(6,'GA6005','Helen Cho','Chevrolet','Equinox','parked',9.0,NULL,NOW() - INTERVAL 15.0 HOUR),
(6,'GA6006','Carlos Diaz','Toyota','RAV4','parked',10.0,2.0,NOW() - INTERVAL 16.0 HOUR),
(6,'GA6007','Nina Brooks','Honda','CR-V','entering',11.0,NULL,NOW() - INTERVAL 17.0 HOUR),
(7,'GA7001','Alex Morgan','Ford','F-150','entering',5.0,NULL,NOW() - INTERVAL 12.0 HOUR),
(7,'GA7002','Priya Shah','Tesla','Model 3','exiting',6.0,NULL,NOW() - INTERVAL 13.0 HOUR),
(7,'GA7003','John Reed','BMW','X5','parked',7.0,2.0,NOW() - INTERVAL 14.0 HOUR),
(7,'GA7004','Helen Cho','Chevrolet','Equinox','parked',8.0,NULL,NOW() - INTERVAL 15.0 HOUR),
(7,'GA7005','Carlos Diaz','Toyota','RAV4','parked',9.0,NULL,NOW() - INTERVAL 16.0 HOUR),
(7,'GA7006','Nina Brooks','Honda','CR-V','entering',10.0,2.0,NOW() - INTERVAL 17.0 HOUR),
(7,'GA7007','Tom Hardy','Ford','F-150','exiting',11.0,NULL,NOW() - INTERVAL 18.0 HOUR),
(8,'GA8001','Priya Shah','Tesla','Model 3','exiting',5.0,NULL,NOW() - INTERVAL 13.0 HOUR),
(8,'GA8002','John Reed','BMW','X5','parked',6.0,NULL,NOW() - INTERVAL 14.0 HOUR),
(8,'GA8003','Helen Cho','Chevrolet','Equinox','parked',7.0,2.0,NOW() - INTERVAL 15.0 HOUR),
(8,'GA8004','Carlos Diaz','Toyota','RAV4','parked',8.0,NULL,NOW() - INTERVAL 16.0 HOUR),
(8,'GA8005','Nina Brooks','Honda','CR-V','entering',9.0,NULL,NOW() - INTERVAL 17.0 HOUR),
(8,'GA8006','Tom Hardy','Ford','F-150','exiting',10.0,2.0,NOW() - INTERVAL 18.0 HOUR),
(8,'GA8007','Zoe Park','Tesla','Model 3','parked',11.0,NULL,NOW() - INTERVAL 19.0 HOUR),
(9,'GA9001','John Reed','BMW','X5','parked',5.0,NULL,NOW() - INTERVAL 14.0 HOUR),
(9,'GA9002','Helen Cho','Chevrolet','Equinox','parked',6.0,NULL,NOW() - INTERVAL 15.0 HOUR),
(9,'GA9003','Carlos Diaz','Toyota','RAV4','parked',7.0,2.0,NOW() - INTERVAL 16.0 HOUR),
(9,'GA9004','Nina Brooks','Honda','CR-V','entering',8.0,NULL,NOW() - INTERVAL 17.0 HOUR),
(9,'GA9005','Tom Hardy','Ford','F-150','exiting',9.0,NULL,NOW() - INTERVAL 18.0 HOUR),
(9,'GA9006','Zoe Park','Tesla','Model 3','parked',10.0,2.0,NOW() - INTERVAL 19.0 HOUR),
(9,'GA9007','Alex Morgan','BMW','X5','parked',11.0,NULL,NOW() - INTERVAL 20.0 HOUR);

-- Daily stats for today (adjust date if needed)
INSERT INTO transit_daily_stats (stat_date, mode, service_class, trips_count) VALUES
(CURDATE(),'taxi','economy',420),
(CURDATE(),'taxi','standard',85),
(CURDATE(),'taxi','vip',38),
(CURDATE(),'van','economy',96),
(CURDATE(),'van','standard',34),
(CURDATE(),'van','executive',22),
(CURDATE(),'bus','airport_shuttle',180),
(CURDATE(),'bus','parking_shuttle',210),
(CURDATE(),'bus','marta',64),
(CURDATE(),'metro','marta_rail',3200),
(CURDATE(),'metro','plane_train',18500);

SET FOREIGN_KEY_CHECKS = 1;

-- Import complete. Then open setup.php / seed/seed.php for full ops data if needed.
