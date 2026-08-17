-- SkyGate ATL — SQLite schema + transit seed (companion to MySQL schema.sql)
PRAGMA foreign_keys = OFF;

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

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  full_name TEXT NULL,
  role TEXT NOT NULL DEFAULT 'controller',
  position_title TEXT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS user_permissions (
  user_id INTEGER NOT NULL,
  section_key TEXT NOT NULL,
  PRIMARY KEY (user_id, section_key),
  CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS aircraft (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  model_code TEXT NOT NULL UNIQUE,
  manufacturer TEXT NULL,
  typical_crew INTEGER NOT NULL DEFAULT 4,
  seats_total INTEGER NOT NULL DEFAULT 0,
  max_fuel_kg INTEGER NOT NULL DEFAULT 0,
  image_url TEXT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS airports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  iata TEXT NOT NULL UNIQUE,
  icao TEXT NULL,
  name TEXT NOT NULL,
  city TEXT NOT NULL,
  country TEXT NOT NULL,
  continent TEXT NULL,
  lat REAL NULL,
  lon REAL NULL
);
CREATE TABLE IF NOT EXISTS runways (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'open',
  role TEXT NOT NULL DEFAULT 'both',
  updated_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS gates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  terminal TEXT NOT NULL,
  gate_number INTEGER NOT NULL,
  is_reserve INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'available',
  current_flight_id INTEGER NULL,
  occupied_since TEXT NULL
);
CREATE TABLE IF NOT EXISTS flights (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  flight_number TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'dep',
  origin TEXT NOT NULL,
  destination TEXT NOT NULL,
  aircraft_id INTEGER NULL,
  gate_id INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'Scheduled',
  scheduled_time TEXT NULL,
  estimated_time TEXT NULL,
  delay_minutes INTEGER NULL,
  delay_reason TEXT NULL,
  seats_total INTEGER NOT NULL DEFAULT 0,
  pax_accepted INTEGER NOT NULL DEFAULT 0,
  bags_count INTEGER NOT NULL DEFAULT 0,
  pilot_name TEXT NULL,
  copilot_name TEXT NULL,
  cabin_crew INTEGER NOT NULL DEFAULT 0,
  is_international INTEGER NOT NULL DEFAULT 0,
  is_manual INTEGER NOT NULL DEFAULT 0,
  phase_started_at TEXT NULL,
  is_tomorrow INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fl_ac FOREIGN KEY (aircraft_id) REFERENCES aircraft(id) ON DELETE SET NULL,
  CONSTRAINT fk_fl_gate FOREIGN KEY (gate_id) REFERENCES gates(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS cancelled_flights (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  flight_number TEXT NOT NULL,
  aircraft_code TEXT NULL,
  origin TEXT NULL,
  destination TEXT NULL,
  scheduled_time TEXT NULL,
  pax INTEGER NOT NULL DEFAULT 0,
  reason TEXT NULL,
  replacement_flight TEXT NULL
);
CREATE TABLE IF NOT EXISTS staff (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_code TEXT NOT NULL UNIQUE,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  role TEXT NOT NULL,
  department_id INTEGER NULL,
  shift TEXT NOT NULL DEFAULT 'morning',
  zone TEXT NULL,
  status TEXT NOT NULL DEFAULT 'off',
  is_active INTEGER NOT NULL DEFAULT 1,
  hired_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS baggage (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bag_id TEXT NOT NULL UNIQUE,
  flight_id INTEGER NULL,
  flight_number TEXT NULL,
  owner_name TEXT NULL,
  weight_kg REAL NULL,
  status TEXT NOT NULL DEFAULT 'in_system',
  belt_code TEXT NULL,
  updated_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS bhs_belts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  bags_on_belt INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'idle'
);
CREATE TABLE IF NOT EXISTS fuel_tanks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  fuel_type TEXT NOT NULL DEFAULT 'jet_a',
  capacity_gal INTEGER NOT NULL,
  level_pct INTEGER NOT NULL DEFAULT 0,
  low_threshold_pct INTEGER NOT NULL DEFAULT 20,
  updated_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS energy_monthly (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  year INTEGER NOT NULL,
  month INTEGER NOT NULL,
  mwh REAL NOT NULL DEFAULT 0,
  is_actual INTEGER NOT NULL DEFAULT 1,
  UNIQUE (year, month)
);
CREATE TABLE IF NOT EXISTS terminal_zones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  zone_code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  wait_minutes INTEGER NULL,
  density_pct INTEGER NULL,
  open_lanes INTEGER NULL,
  pax_inside INTEGER NULL,
  updated_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS transit_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'on',
  speed_mph INTEGER NULL,
  capacity_per_trip INTEGER NULL,
  avg_load_pct INTEGER NULL,
  route_label TEXT NULL
);
CREATE TABLE IF NOT EXISTS security_zones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'clear',
  updated_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS safety_alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  level TEXT NOT NULL DEFAULT 'warning',
  title TEXT NOT NULL,
  location TEXT NULL,
  category TEXT NOT NULL DEFAULT 'other',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TEXT NULL
);
CREATE TABLE IF NOT EXISTS cameras (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  cam_code TEXT NOT NULL UNIQUE,
  zone TEXT NULL,
  stream_url TEXT NULL,
  snapshot_url TEXT NULL,
  is_live INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'online'
);
CREATE TABLE IF NOT EXISTS arff_resources (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  unit_code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  water_capacity_gal INTEGER NULL,
  water_level_pct INTEGER NULL,
  status TEXT NOT NULL DEFAULT 'ready'
);
CREATE TABLE IF NOT EXISTS weather_hourly (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  observed_at TEXT NOT NULL UNIQUE,
  temp_c REAL NULL,
  condition_code TEXT NULL,
  condition_label TEXT NULL,
  wind_dir_deg INTEGER NULL,
  wind_kt INTEGER NULL,
  visibility_sm REAL NULL,
  ceiling_ft INTEGER NULL,
  impact_level TEXT NOT NULL DEFAULT 'none'
);
CREATE TABLE IF NOT EXISTS airport_kpis (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  op_date TEXT NOT NULL UNIQUE,
  ops_total INTEGER NOT NULL DEFAULT 0,
  takeoffs INTEGER NOT NULL DEFAULT 0,
  landings INTEGER NOT NULL DEFAULT 0,
  otp_pct REAL NULL,
  gates_used INTEGER NULL,
  active_alerts INTEGER NOT NULL DEFAULT 0,
  pax_today INTEGER NULL,
  security_status TEXT DEFAULT 'SECURE'
);
CREATE TABLE IF NOT EXISTS citizen_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  detail TEXT NULL,
  level TEXT NOT NULL DEFAULT 'info',
  location TEXT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL DEFAULT 'info',
  title TEXT NOT NULL,
  message TEXT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  is_critical INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS terminal_settings (
  terminal TEXT PRIMARY KEY,
  type TEXT NOT NULL DEFAULT 'domestic',
  continent TEXT NULL
);
CREATE TABLE IF NOT EXISTS system_state (
  id INTEGER PRIMARY KEY DEFAULT 1,
  sim_tick INTEGER NOT NULL DEFAULT 0,
  last_tick_at TEXT NULL,
  evacuation_active INTEGER NOT NULL DEFAULT 0,
  critical_mode INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS parking_lots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  lot_type TEXT NOT NULL DEFAULT 'other',
  covered INTEGER NOT NULL DEFAULT 0,
  capacity INTEGER NOT NULL DEFAULT 0,
  occupied INTEGER NOT NULL DEFAULT 0,
  rate_hourly REAL NULL,
  rate_daily REAL NULL,
  terminal_link TEXT NULL,
  notes TEXT NULL,
  status TEXT NOT NULL DEFAULT 'open'
);
CREATE TABLE IF NOT EXISTS transit_stations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  station_type TEXT NOT NULL DEFAULT 'other',
  location TEXT NULL,
  lines_served TEXT NULL,
  status TEXT NOT NULL DEFAULT 'open'
);
CREATE TABLE IF NOT EXISTS ground_fleet (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fleet_type TEXT NOT NULL,
  service_class TEXT NOT NULL DEFAULT 'standard',
  company TEXT NULL,
  model TEXT NULL,
  capacity_pax INTEGER NULL,
  unit_count INTEGER NOT NULL DEFAULT 0,
  on_site_now INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active',
  fare_note TEXT NULL,
  notes TEXT NULL
);
CREATE TABLE IF NOT EXISTS transit_fares (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  mode TEXT NOT NULL,
  service_class TEXT NULL,
  route_label TEXT NULL,
  fare_min REAL NULL,
  fare_max REAL NULL,
  fare_flat REAL NULL,
  currency TEXT NOT NULL DEFAULT 'USD',
  notes TEXT NULL
);
CREATE TABLE IF NOT EXISTS ground_vehicles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  fleet_type TEXT NOT NULL,
  service_class TEXT NOT NULL DEFAULT 'standard',
  vehicle_code TEXT NOT NULL,
  plate_number TEXT NOT NULL,
  manufacturer TEXT NULL,
  model TEXT NOT NULL,
  capacity_pax INTEGER NOT NULL DEFAULT 4,
  driver_name TEXT NULL,
  trips_today INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'available',
  company TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (vehicle_code)
);
CREATE TABLE IF NOT EXISTS parking_vehicles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  lot_id INTEGER NOT NULL,
  plate_number TEXT NOT NULL,
  owner_name TEXT NULL,
  manufacturer TEXT NULL,
  model TEXT NULL,
  status TEXT NOT NULL DEFAULT 'parked',
  duration_hours REAL NULL,
  duration_days REAL NULL,
  parked_at TEXT NULL,
  expected_leave TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pv_lot FOREIGN KEY (lot_id) REFERENCES parking_lots(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS transit_daily_stats (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  stat_date TEXT NOT NULL,
  mode TEXT NOT NULL,
  service_class TEXT NULL,
  trips_count INTEGER NOT NULL DEFAULT 0,
  UNIQUE (stat_date, mode, service_class)
);

CREATE INDEX IF NOT EXISTS "idx_airports_iata" ON airports (iata);
CREATE INDEX IF NOT EXISTS "idx_airports_city" ON airports (city);
CREATE INDEX IF NOT EXISTS "idx_airports_continent" ON airports (continent);
CREATE INDEX IF NOT EXISTS "idx_gates_terminal" ON gates (terminal);
CREATE INDEX IF NOT EXISTS "idx_gates_status" ON gates (status);
CREATE INDEX IF NOT EXISTS "idx_flights_flight_number" ON flights (flight_number);
CREATE INDEX IF NOT EXISTS "idx_flights_status" ON flights (status);
CREATE INDEX IF NOT EXISTS "idx_flights_type" ON flights (type);
CREATE INDEX IF NOT EXISTS "idx_flights_scheduled_time" ON flights (scheduled_time);
CREATE INDEX IF NOT EXISTS "idx_flights_is_tomorrow" ON flights (is_tomorrow);
CREATE INDEX IF NOT EXISTS "idx_flights_is_manual" ON flights (is_manual);
CREATE INDEX IF NOT EXISTS "idx_staff_role" ON staff (role);
CREATE INDEX IF NOT EXISTS "idx_staff_status" ON staff (status);
CREATE INDEX IF NOT EXISTS "idx_baggage_flight_id" ON baggage (flight_id);
CREATE INDEX IF NOT EXISTS "idx_baggage_flight_number" ON baggage (flight_number);
CREATE INDEX IF NOT EXISTS "idx_baggage_status" ON baggage (status);
CREATE INDEX IF NOT EXISTS "idx_safety_alerts_is_active" ON safety_alerts (is_active);
CREATE INDEX IF NOT EXISTS "idx_safety_alerts_level" ON safety_alerts (level);
CREATE INDEX IF NOT EXISTS "idx_ground_vehicles_fleet_type_service_class" ON ground_vehicles (fleet_type, service_class);
CREATE INDEX IF NOT EXISTS "idx_ground_vehicles_status" ON ground_vehicles (status);
CREATE INDEX IF NOT EXISTS "idx_parking_vehicles_lot_id" ON parking_vehicles (lot_id);
CREATE INDEX IF NOT EXISTS "idx_parking_vehicles_plate_number" ON parking_vehicles (plate_number);

INSERT INTO system_state (id, sim_tick) VALUES (1, 0);
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
(1,'GA1001','John Reed','Ford','F-150','parked',5.0,NULL,datetime('now', '-6 hours')),
(1,'GA1002','Helen Cho','Tesla','Model 3','entering',6.0,NULL,datetime('now', '-7 hours')),
(1,'GA1003','Carlos Diaz','BMW','X5','exiting',7.0,2.0,datetime('now', '-8 hours')),
(1,'GA1004','Nina Brooks','Chevrolet','Equinox','parked',8.0,NULL,datetime('now', '-9 hours')),
(1,'GA1005','Tom Hardy','Toyota','RAV4','parked',9.0,NULL,datetime('now', '-10 hours')),
(1,'GA1006','Zoe Park','Honda','CR-V','parked',10.0,2.0,datetime('now', '-11 hours')),
(1,'GA1007','Alex Morgan','Ford','F-150','entering',11.0,NULL,datetime('now', '-12 hours')),
(2,'GA2001','Helen Cho','Tesla','Model 3','entering',5.0,NULL,datetime('now', '-7 hours')),
(2,'GA2002','Carlos Diaz','BMW','X5','exiting',6.0,NULL,datetime('now', '-8 hours')),
(2,'GA2003','Nina Brooks','Chevrolet','Equinox','parked',7.0,2.0,datetime('now', '-9 hours')),
(2,'GA2004','Tom Hardy','Toyota','RAV4','parked',8.0,NULL,datetime('now', '-10 hours')),
(2,'GA2005','Zoe Park','Honda','CR-V','parked',9.0,NULL,datetime('now', '-11 hours')),
(2,'GA2006','Alex Morgan','Ford','F-150','entering',10.0,2.0,datetime('now', '-12 hours')),
(2,'GA2007','Priya Shah','Tesla','Model 3','exiting',11.0,NULL,datetime('now', '-13 hours')),
(3,'GA3001','Carlos Diaz','BMW','X5','exiting',5.0,NULL,datetime('now', '-8 hours')),
(3,'GA3002','Nina Brooks','Chevrolet','Equinox','parked',6.0,NULL,datetime('now', '-9 hours')),
(3,'GA3003','Tom Hardy','Toyota','RAV4','parked',7.0,2.0,datetime('now', '-10 hours')),
(3,'GA3004','Zoe Park','Honda','CR-V','parked',8.0,NULL,datetime('now', '-11 hours')),
(3,'GA3005','Alex Morgan','Ford','F-150','entering',9.0,NULL,datetime('now', '-12 hours')),
(3,'GA3006','Priya Shah','Tesla','Model 3','exiting',10.0,2.0,datetime('now', '-13 hours')),
(3,'GA3007','John Reed','BMW','X5','parked',11.0,NULL,datetime('now', '-14 hours')),
(4,'GA4001','Nina Brooks','Chevrolet','Equinox','parked',5.0,NULL,datetime('now', '-9 hours')),
(4,'GA4002','Tom Hardy','Toyota','RAV4','parked',6.0,NULL,datetime('now', '-10 hours')),
(4,'GA4003','Zoe Park','Honda','CR-V','parked',7.0,2.0,datetime('now', '-11 hours')),
(4,'GA4004','Alex Morgan','Ford','F-150','entering',8.0,NULL,datetime('now', '-12 hours')),
(4,'GA4005','Priya Shah','Tesla','Model 3','exiting',9.0,NULL,datetime('now', '-13 hours')),
(4,'GA4006','John Reed','BMW','X5','parked',10.0,2.0,datetime('now', '-14 hours')),
(4,'GA4007','Helen Cho','Chevrolet','Equinox','parked',11.0,NULL,datetime('now', '-15 hours')),
(5,'GA5001','Tom Hardy','Toyota','RAV4','parked',5.0,NULL,datetime('now', '-10 hours')),
(5,'GA5002','Zoe Park','Honda','CR-V','parked',6.0,NULL,datetime('now', '-11 hours')),
(5,'GA5003','Alex Morgan','Ford','F-150','entering',7.0,2.0,datetime('now', '-12 hours')),
(5,'GA5004','Priya Shah','Tesla','Model 3','exiting',8.0,NULL,datetime('now', '-13 hours')),
(5,'GA5005','John Reed','BMW','X5','parked',9.0,NULL,datetime('now', '-14 hours')),
(5,'GA5006','Helen Cho','Chevrolet','Equinox','parked',10.0,2.0,datetime('now', '-15 hours')),
(5,'GA5007','Carlos Diaz','Toyota','RAV4','parked',11.0,NULL,datetime('now', '-16 hours')),
(6,'GA6001','Zoe Park','Honda','CR-V','parked',5.0,NULL,datetime('now', '-11 hours')),
(6,'GA6002','Alex Morgan','Ford','F-150','entering',6.0,NULL,datetime('now', '-12 hours')),
(6,'GA6003','Priya Shah','Tesla','Model 3','exiting',7.0,2.0,datetime('now', '-13 hours')),
(6,'GA6004','John Reed','BMW','X5','parked',8.0,NULL,datetime('now', '-14 hours')),
(6,'GA6005','Helen Cho','Chevrolet','Equinox','parked',9.0,NULL,datetime('now', '-15 hours')),
(6,'GA6006','Carlos Diaz','Toyota','RAV4','parked',10.0,2.0,datetime('now', '-16 hours')),
(6,'GA6007','Nina Brooks','Honda','CR-V','entering',11.0,NULL,datetime('now', '-17 hours')),
(7,'GA7001','Alex Morgan','Ford','F-150','entering',5.0,NULL,datetime('now', '-12 hours')),
(7,'GA7002','Priya Shah','Tesla','Model 3','exiting',6.0,NULL,datetime('now', '-13 hours')),
(7,'GA7003','John Reed','BMW','X5','parked',7.0,2.0,datetime('now', '-14 hours')),
(7,'GA7004','Helen Cho','Chevrolet','Equinox','parked',8.0,NULL,datetime('now', '-15 hours')),
(7,'GA7005','Carlos Diaz','Toyota','RAV4','parked',9.0,NULL,datetime('now', '-16 hours')),
(7,'GA7006','Nina Brooks','Honda','CR-V','entering',10.0,2.0,datetime('now', '-17 hours')),
(7,'GA7007','Tom Hardy','Ford','F-150','exiting',11.0,NULL,datetime('now', '-18 hours')),
(8,'GA8001','Priya Shah','Tesla','Model 3','exiting',5.0,NULL,datetime('now', '-13 hours')),
(8,'GA8002','John Reed','BMW','X5','parked',6.0,NULL,datetime('now', '-14 hours')),
(8,'GA8003','Helen Cho','Chevrolet','Equinox','parked',7.0,2.0,datetime('now', '-15 hours')),
(8,'GA8004','Carlos Diaz','Toyota','RAV4','parked',8.0,NULL,datetime('now', '-16 hours')),
(8,'GA8005','Nina Brooks','Honda','CR-V','entering',9.0,NULL,datetime('now', '-17 hours')),
(8,'GA8006','Tom Hardy','Ford','F-150','exiting',10.0,2.0,datetime('now', '-18 hours')),
(8,'GA8007','Zoe Park','Tesla','Model 3','parked',11.0,NULL,datetime('now', '-19 hours')),
(9,'GA9001','John Reed','BMW','X5','parked',5.0,NULL,datetime('now', '-14 hours')),
(9,'GA9002','Helen Cho','Chevrolet','Equinox','parked',6.0,NULL,datetime('now', '-15 hours')),
(9,'GA9003','Carlos Diaz','Toyota','RAV4','parked',7.0,2.0,datetime('now', '-16 hours')),
(9,'GA9004','Nina Brooks','Honda','CR-V','entering',8.0,NULL,datetime('now', '-17 hours')),
(9,'GA9005','Tom Hardy','Ford','F-150','exiting',9.0,NULL,datetime('now', '-18 hours')),
(9,'GA9006','Zoe Park','Tesla','Model 3','parked',10.0,2.0,datetime('now', '-19 hours')),
(9,'GA9007','Alex Morgan','BMW','X5','parked',11.0,NULL,datetime('now', '-20 hours'));
INSERT INTO transit_daily_stats (stat_date, mode, service_class, trips_count) VALUES
(date('now'),'taxi','economy',420),
(date('now'),'taxi','standard',85),
(date('now'),'taxi','vip',38),
(date('now'),'van','economy',96),
(date('now'),'van','standard',34),
(date('now'),'van','executive',22),
(date('now'),'bus','airport_shuttle',180),
(date('now'),'bus','parking_shuttle',210),
(date('now'),'bus','marta',64),
(date('now'),'metro','marta_rail',3200),
(date('now'),'metro','plane_train',18500);

PRAGMA foreign_keys = ON;
