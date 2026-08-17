<?php
/**
 * ATL Airport database seeder.
 *
 * Initial seed: admin, terminals, runways, airports, aircraft models, empty gates, empty KPI.
 * Full seed: realistic demo records for every table + linked flights/bags/weather/etc.
 */
declare(strict_types=1);
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/simulate.php';
header('Content-Type: text/html; charset=utf-8');

const SEED_TABLES = [
    'user_permissions',
    'notifications',
    'citizen_reports',
    'safety_alerts',
    'cameras',
    'arff_resources',
    'security_zones',
    'weather_hourly',
    'airport_kpis',
    'transit_fares',
    'ground_fleet',
    'transit_stations',
    'parking_lots',
    'ground_vehicles',
    'parking_vehicles',
    'transit_daily_stats',
    'transit_lines',
    'terminal_zones',
    'energy_monthly',
    'fuel_tanks',
    'bhs_belts',
    'baggage',
    'cancelled_flights',
    'flights',
    'gates',
    'runways',
    'aircraft',
    'airports',
    'staff',
    'departments',
    'users',
    'terminal_settings',
    'system_state',
];

function insert_row(PDO $pdo, string $table, array $data): int
{
    $columns = array_keys($data);
    $quoted = array_map(static fn(string $column): string => qi($column), $columns);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        qi($table),
        implode(',', $quoted),
        $placeholders
    );
    $pdo->prepare($sql)->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}


function batch_insert(PDO $pdo, string $table, array $columns, array $rows, int $chunkSize = 250): void
{
    if (!$rows) {
        return;
    }
    $quoted = array_map(static fn(string $col): string => qi($col), $columns);
    $colSql = implode(',', $quoted);
    $placeOne = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
    foreach (array_chunk($rows, $chunkSize) as $chunk) {
        $places = implode(',', array_fill(0, count($chunk), $placeOne));
        $sql = 'INSERT INTO ' . qi($table) . " ({$colSql}) VALUES {$places}";
        $vals = [];
        foreach ($chunk as $row) {
            foreach ($row as $v) {
                $vals[] = $v;
            }
        }
        $pdo->prepare($sql)->execute($vals);
    }
}


/** Quote identifier for current driver (MySQL backticks / SQLite double-quotes). */
function qi(string $name): string
{
    if (is_sqlite()) {
        return '"' . str_replace('"', '""', $name) . '"';
    }
    return '`' . str_replace('`', '``', $name) . '`';
}

function reset_database(PDO $pdo, array &$log): void
{
    // MySQL: SET FOREIGN_KEY_CHECKS / TRUNCATE
    // SQLite: PRAGMA foreign_keys / DELETE (+ reset AUTOINCREMENT sequences)
    if (is_sqlite()) {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            foreach (SEED_TABLES as $table) {
                try {
                    $pdo->exec('DELETE FROM ' . qi($table));
                } catch (Throwable $e) {
                    // ignore missing tables during upgrades
                }
            }
            try {
                $pdo->exec('DELETE FROM sqlite_sequence');
            } catch (Throwable $e) {
                // sqlite_sequence may not exist yet
            }
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (SEED_TABLES as $table) {
                try {
                    $pdo->exec('TRUNCATE TABLE ' . qi($table));
                } catch (Throwable $e) {
                    // ignore missing tables during upgrades
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }
    $log[] = 'Cleared all schema tables.';
}

function seed_system_state(PDO $pdo): void
{
    insert_row($pdo, 'system_state', [
        'id' => 1,
        'sim_tick' => 0,
        'last_tick_at' => date('Y-m-d H:i:s'),
        'evacuation_active' => 0,
        'critical_mode' => 0,
    ]);
}

function seed_admin(PDO $pdo): int
{
    $userId = insert_row($pdo, 'users', [
        'username' => 'admin',
        'password' => password_hash('admin123456', PASSWORD_DEFAULT),
        'full_name' => 'ATL Airport Administrator',
        'role' => 'admin',
        'position_title' => 'Airport Operations Administrator',
        'is_active' => 1,
    ]);

    foreach (ALL_SECTIONS as $section) {
        insert_row($pdo, 'user_permissions', [
            'user_id' => $userId,
            'section_key' => $section,
        ]);
    }

    return $userId;
}

function seed_terminal_settings(PDO $pdo): void
{
    $settings = [
        ['T', 'domestic', null],
        ['A', 'domestic', null],
        ['B', 'domestic', null],
        ['C', 'international', 'europe'],
        ['D', 'international', 'namerica'],
        ['E', 'international', 'asia'],
        ['F', 'international', 'samerica'],
    ];
    foreach ($settings as [$terminal, $type, $continent]) {
        insert_row($pdo, 'terminal_settings', [
            'terminal' => $terminal,
            'type' => $type,
            'continent' => $continent,
        ]);
    }
}

function seed_runways(PDO $pdo): void
{
    $runways = [
        ['08L/26R', 'open', 'both'],
        ['08R/26L', 'open', 'landing'],
        ['09L/27R', 'open', 'takeoff'],
        ['09R/27L', 'inspection', 'both'],
        ['10/28', 'closed', 'closed'],
    ];
    foreach ($runways as [$code, $status, $role]) {
        insert_row($pdo, 'runways', [
            'code' => $code,
            'status' => $status,
            'role' => $role,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

function seed_aircraft(PDO $pdo): array
{
    // model, manufacturer, crew, seats, max_fuel_kg, image_url
    $acData = [
        ['A220-300', 'Airbus', 4, 140, 18900, 'https://images.unsplash.com/photo-1540962351504-03099e0a754b?auto=format&fit=crop&w=1200&q=80'],
        ['A319', 'Airbus', 5, 144, 19000, 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=80'],
        ['A320', 'Airbus', 6, 180, 24200, 'https://images.unsplash.com/photo-1464037866556-6812c9d1c72e?auto=format&fit=crop&w=1200&q=80'],
        ['A320neo', 'Airbus', 6, 186, 24200, 'https://images.unsplash.com/photo-1464037866556-6812c9d1c72e?auto=format&fit=crop&w=1200&q=80'],
        ['A321', 'Airbus', 7, 220, 30000, 'https://images.unsplash.com/photo-1540962351504-03099e0a754b?auto=format&fit=crop&w=1200&q=80'],
        ['A321neo', 'Airbus', 7, 232, 30000, 'https://images.unsplash.com/photo-1540962351504-03099e0a754b?auto=format&fit=crop&w=1200&q=80'],
        ['A330-200', 'Airbus', 10, 250, 78000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A330-300', 'Airbus', 10, 300, 78000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A330-900', 'Airbus', 10, 300, 78000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A340-600', 'Airbus', 12, 350, 110000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A350-900', 'Airbus', 12, 325, 110000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A350-1000', 'Airbus', 12, 366, 125000, 'https://images.unsplash.com/photo-1529074963764-98f45c47344b?auto=format&fit=crop&w=1200&q=80'],
        ['A380', 'Airbus', 16, 525, 254000, 'https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&w=1200&q=80'],
        ['B737-700', 'Boeing', 5, 143, 19000, 'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=1200&q=80'],
        ['B737-800', 'Boeing', 6, 189, 20800, 'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=1200&q=80'],
        ['B737-900', 'Boeing', 6, 215, 20900, 'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=1200&q=80'],
        ['B737 MAX 8', 'Boeing', 6, 189, 20800, 'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=1200&q=80'],
        ['B737 MAX 9', 'Boeing', 6, 215, 20900, 'https://images.unsplash.com/photo-1569154941061-e231b4725ef1?auto=format&fit=crop&w=1200&q=80'],
        ['B747-400', 'Boeing', 14, 416, 148000, 'https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&w=1200&q=80'],
        ['B747-8', 'Boeing', 14, 410, 180000, 'https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&w=1200&q=80'],
        ['B757-200', 'Boeing', 8, 200, 43000, 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80'],
        ['B757-300', 'Boeing', 8, 243, 43000, 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80'],
        ['B767-300', 'Boeing', 10, 250, 73000, 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80'],
        ['B767-400', 'Boeing', 10, 285, 73000, 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=1200&q=80'],
        ['B777-200', 'Boeing', 12, 314, 135000, 'https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&w=1200&q=80'],
        ['B777-300ER', 'Boeing', 12, 396, 145000, 'https://images.unsplash.com/photo-1474302770737-173ee21bab63?auto=format&fit=crop&w=1200&q=80'],
        ['B787-8', 'Boeing', 10, 242, 100000, 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80'],
        ['B787-9', 'Boeing', 10, 290, 110000, 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80'],
        ['B787-10', 'Boeing', 10, 330, 120000, 'https://images.unsplash.com/photo-1556388158-158ea5ccacbd?auto=format&fit=crop&w=1200&q=80'],
        ['CRJ900', 'Bombardier', 4, 76, 9000, 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=80'],
        ['E175', 'Embraer', 4, 76, 9400, 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=80'],
        ['E190', 'Embraer', 4, 100, 13000, 'https://images.unsplash.com/photo-1436491865332-7a61a109cc05?auto=format&fit=crop&w=1200&q=80'],
    ];
    $ids = [];
    foreach ($acData as $a) {
        $ids[$a[0]] = insert_row($pdo, 'aircraft', [
            'model_code' => $a[0],
            'manufacturer' => $a[1],
            'typical_crew' => $a[2],
            'seats_total' => $a[3],
            'max_fuel_kg' => $a[4],
            'image_url' => $a[5],
            'is_active' => 1,
        ]);
    }
    return $ids;
}

function seed_airports(PDO $pdo): void
{
    $airports = [
        // North America
        ['ATL','KATL','Hartsfield-Jackson Atlanta International','Atlanta','United States','namerica',33.6407,-84.4277],
        ['JFK','KJFK','John F. Kennedy International','New York','United States','namerica',40.6413,-73.7781],
        ['LAX','KLAX','Los Angeles International','Los Angeles','United States','namerica',33.9416,-118.4085],
        ['ORD','KORD','Chicago O\'Hare International','Chicago','United States','namerica',41.9742,-87.9073],
        ['DFW','KDFW','Dallas/Fort Worth International','Dallas','United States','namerica',32.8998,-97.0403],
        ['MIA','KMIA','Miami International','Miami','United States','namerica',25.7959,-80.2870],
        ['DEN','KDEN','Denver International','Denver','United States','namerica',39.8561,-104.6737],
        ['SEA','KSEA','Seattle-Tacoma International','Seattle','United States','namerica',47.4502,-122.3088],
        ['SFO','KSFO','San Francisco International','San Francisco','United States','namerica',37.6213,-122.3790],
        ['BOS','KBOS','Boston Logan International','Boston','United States','namerica',42.3656,-71.0096],
        ['EWR','KEWR','Newark Liberty International','Newark','United States','namerica',40.6895,-74.1745],
        ['CLT','KCLT','Charlotte Douglas International','Charlotte','United States','namerica',35.2140,-80.9431],
        ['IAH','KIAH','George Bush Intercontinental','Houston','United States','namerica',29.9902,-95.3368],
        ['LAS','KLAS','Harry Reid International','Las Vegas','United States','namerica',36.0840,-115.1537],
        ['MCO','KMCO','Orlando International','Orlando','United States','namerica',28.4312,-81.3081],
        ['PHL','KPHL','Philadelphia International','Philadelphia','United States','namerica',39.8729,-75.2371],
        ['BWI','KBWI','Baltimore/Washington International','Baltimore','United States','namerica',39.1754,-76.6683],
        ['DTW','KDTW','Detroit Metropolitan','Detroit','United States','namerica',42.2162,-83.3554],
        ['MSP','KMSP','Minneapolis–Saint Paul International','Minneapolis','United States','namerica',44.8848,-93.2223],
        ['PHX','KPHX','Phoenix Sky Harbor','Phoenix','United States','namerica',33.4373,-112.0078],
        ['YYZ','CYYZ','Toronto Pearson International','Toronto','Canada','namerica',43.6777,-79.6248],
        ['YVR','CYVR','Vancouver International','Vancouver','Canada','namerica',49.1967,-123.1815],
        ['YUL','CYUL','Montréal–Trudeau','Montreal','Canada','namerica',45.4706,-73.7408],
        ['MEX','MMMX','Mexico City International','Mexico City','Mexico','namerica',19.4363,-99.0721],
        ['CUN','MMUN','Cancún International','Cancún','Mexico','namerica',21.0365,-86.8771],
        // Europe
        ['FRA','EDDF','Frankfurt Airport','Frankfurt','Germany','europe',50.0379,8.5622],
        ['MUC','EDDM','Munich Airport','Munich','Germany','europe',48.3538,11.7861],
        ['CDG','LFPG','Paris Charles de Gaulle','Paris','France','europe',49.0097,2.5479],
        ['LHR','EGLL','London Heathrow','London','United Kingdom','europe',51.4700,-0.4543],
        ['LGW','EGKK','London Gatwick','London','United Kingdom','europe',51.1537,-0.1821],
        ['AMS','EHAM','Amsterdam Schiphol','Amsterdam','Netherlands','europe',52.3105,4.7683],
        ['MAD','LEMD','Madrid-Barajas','Madrid','Spain','europe',40.4983,-3.5676],
        ['BCN','LEBL','Barcelona-El Prat','Barcelona','Spain','europe',41.2971,2.0785],
        ['FCO','LIRF','Rome Fiumicino','Rome','Italy','europe',41.8003,12.2389],
        ['MXP','LIMC','Milan Malpensa','Milan','Italy','europe',45.6306,8.7281],
        ['ZRH','LSZH','Zurich Airport','Zurich','Switzerland','europe',47.4582,8.5555],
        ['VIE','LOWW','Vienna International','Vienna','Austria','europe',48.1103,16.5697],
        ['CPH','EKCH','Copenhagen Airport','Copenhagen','Denmark','europe',55.6180,12.6560],
        ['ARN','ESSA','Stockholm Arlanda','Stockholm','Sweden','europe',59.6519,17.9186],
        ['OSL','ENGM','Oslo Gardermoen','Oslo','Norway','europe',60.1975,11.1004],
        ['DUB','EIDW','Dublin Airport','Dublin','Ireland','europe',53.4264,-6.2499],
        ['LIS','LPPT','Lisbon Humberto Delgado','Lisbon','Portugal','europe',38.7756,-9.1354],
        ['ATH','LGAV','Athens International','Athens','Greece','europe',37.9364,23.9445],
        ['IST','LTFM','Istanbul Airport','Istanbul','Turkey','europe',41.2753,28.7519],
        ['WAW','EPWA','Warsaw Chopin','Warsaw','Poland','europe',52.1657,20.9671],
        // Asia
        ['HND','RJTT','Tokyo Haneda','Tokyo','Japan','asia',35.5494,139.7798],
        ['NRT','RJAA','Tokyo Narita','Tokyo','Japan','asia',35.7720,140.3929],
        ['ICN','RKSI','Seoul Incheon','Seoul','South Korea','asia',37.4602,126.4407],
        ['PEK','ZBAA','Beijing Capital','Beijing','China','asia',40.0799,116.6031],
        ['PVG','ZSPD','Shanghai Pudong','Shanghai','China','asia',31.1443,121.8083],
        ['HKG','VHHH','Hong Kong International','Hong Kong','China','asia',22.3080,113.9185],
        ['SIN','WSSS','Singapore Changi','Singapore','Singapore','asia',1.3644,103.9915],
        ['BKK','VTBS','Bangkok Suvarnabhumi','Bangkok','Thailand','asia',13.6900,100.7501],
        ['KUL','WMKK','Kuala Lumpur International','Kuala Lumpur','Malaysia','asia',2.7456,101.7099],
        ['DEL','VIDP','Indira Gandhi International','Delhi','India','asia',28.5562,77.1000],
        ['BOM','VABB','Chhatrapati Shivaji Maharaj','Mumbai','India','asia',19.0896,72.8656],
        ['DXB','OMDB','Dubai International','Dubai','United Arab Emirates','asia',25.2532,55.3657],
        ['DOH','OTHH','Hamad International','Doha','Qatar','asia',25.2731,51.6081],
        ['AUH','OMAA','Abu Dhabi International','Abu Dhabi','United Arab Emirates','asia',24.4330,54.6511],
        // South America
        ['GRU','SBGR','São Paulo Guarulhos','São Paulo','Brazil','samerica',-23.4356,-46.4731],
        ['GIG','SBGL','Rio de Janeiro Galeão','Rio de Janeiro','Brazil','samerica',-22.8090,-43.2506],
        ['EZE','SAEZ','Buenos Aires Ezeiza','Buenos Aires','Argentina','samerica',-34.8222,-58.5358],
        ['SCL','SCEL','Santiago International','Santiago','Chile','samerica',-33.3930,-70.7858],
        ['BOG','SKBO','Bogotá El Dorado','Bogotá','Colombia','samerica',4.7016,-74.1469],
        ['LIM','SPJC','Lima Jorge Chávez','Lima','Peru','samerica',-12.0219,-77.1143],
        // Africa + Oceania
        ['JNB','FAOR','Johannesburg OR Tambo','Johannesburg','South Africa','africa',-26.1392,28.2460],
        ['CPT','FACT','Cape Town International','Cape Town','South Africa','africa',-33.9648,18.6017],
        ['CAI','HECA','Cairo International','Cairo','Egypt','africa',30.1219,31.4056],
        ['ADD','HAAB','Addis Ababa Bole','Addis Ababa','Ethiopia','africa',8.9779,38.7993],
        ['NBO','HKJK','Nairobi Jomo Kenyatta','Nairobi','Kenya','africa',-1.3192,36.9278],
        ['SYD','YSSY','Sydney Kingsford Smith','Sydney','Australia','oceania',-33.9399,151.1753],
        ['MEL','YMML','Melbourne Airport','Melbourne','Australia','oceania',-37.6690,144.8410],
        ['AKL','NZAA','Auckland Airport','Auckland','New Zealand','oceania',-37.0082,174.7850],
        ['PER','YPPH','Perth Airport','Perth','Australia','oceania',-31.9403,115.9669],
    ];
    foreach ($airports as $a) {
        insert_row($pdo, 'airports', [
            'iata' => $a[0],
            'icao' => $a[1],
            'name' => $a[2],
            'city' => $a[3],
            'country' => $a[4],
            'continent' => $a[5],
            'lat' => $a[6],
            'lon' => $a[7],
        ]);
    }
}

function seed_gates(PDO $pdo, bool $withSampleOccupied = false): array
{
    // Realistic ATL-style counts per concourse
    $layout = [
        'T' => range(1, 21),
        'A' => range(1, 34),
        'B' => range(1, 35),
        'C' => range(1, 34),
        'D' => range(1, 40),
        'E' => range(1, 28),
        'F' => range(1, 12),
    ];
    $gateIds = [];
    foreach ($layout as $term => $nums) {
        $maxN = max($nums);
        foreach ($nums as $n) {
            $code = $term . $n;
            // Last 2 gates of each hall = emergency / overflow only
            $isReserve = ($n >= $maxN - 1) ? 1 : 0;
            $status = 'available';
            $gid = insert_row($pdo, 'gates', [
                'code' => $code,
                'terminal' => $term,
                'gate_number' => $n,
                'is_reserve' => $isReserve,
                'status' => $status,
                'current_flight_id' => null,
                'occupied_since' => null,
            ]);
            $gateIds[$code] = $gid;
        }
    }
    return $gateIds;
}

function seed_initial(PDO $pdo, array &$log): void
{
    seed_system_state($pdo);
    seed_admin($pdo);
    seed_terminal_settings($pdo);
    seed_runways($pdo);
    seed_aircraft($pdo);
    seed_airports($pdo);
    seed_gates($pdo, false);
    // Use Atlanta calendar date so Overview KPI matches live clock
    $atlDate = (new DateTime('now', new DateTimeZone('America/New_York')))->format('Y-m-d');
    insert_row($pdo, 'airport_kpis', [
        'op_date' => $atlDate,
        'ops_total' => 0,
        'takeoffs' => 0,
        'landings' => 0,
        'otp_pct' => 86.40,
        'gates_used' => 0,
        'active_alerts' => 2,
        'pax_today' => null,
        'security_status' => 'SECURE',
    ]);
    $log[] = 'Admin account, terminal settings, runways, aircraft models, worldwide airports, empty gates, and empty KPI created.';
    $log[] = 'Add Flight is ready (airport autocomplete + continent gate filter work).';
}

function seed_full(PDO $pdo, array &$log): void
{
    seed_system_state($pdo);
    $adminId = seed_admin($pdo);
    seed_terminal_settings($pdo);
    seed_runways($pdo);
    $aircraftIds = seed_aircraft($pdo);
    seed_airports($pdo);
    $gateIds = seed_gates($pdo, true);

    // Departments
    $departmentIds = [];
    $departments = [
        ['OPS', 'Airport Operations'],
        ['ATC', 'Air Traffic Control'],
        ['GATE', 'Gate Operations'],
        ['RAMP', 'Ramp Services'],
        ['SEC', 'Aviation Security'],
        ['ARFF', 'Aircraft Rescue and Fire Fighting'],
        ['BHS', 'Baggage Handling'],
        ['FAC', 'Facilities and Energy'],
        ['CLEAN', 'Terminal Cleaning'],
        ['INFO', 'Passenger Information'],
        ['CUST', 'Customer Service'],
        ['TECH', 'IT & Systems'],
        ['MED', 'Airport Medical'],
        ['RETAIL', 'Retail & Concessions Support'],
    ];
    foreach ($departments as [$code, $name]) {
        $departmentIds[$code] = insert_row($pdo, 'departments', [
            'code' => $code,
            'name' => $name,
            'is_active' => 1,
        ]);
    }

    // Demo non-admin users
    $demoUsers = [
        ['ops.supervisor', 'Jordan Williams', 'supervisor', 'Airport Duty Manager', ['overview', 'flights', 'addflight', 'gates', 'airside', 'terminal', 'staff']],
        ['tower.control', 'Morgan Reed', 'controller', 'Air Traffic Controller', ['overview', 'flights', 'airside', 'weather']],
        ['gate.agent', 'Taylor Brooks', 'gate_agent', 'Senior Gate Agent', ['overview', 'flights', 'gates', 'terminal', 'baggage']],
        ['ramp.agent', 'Cameron Price', 'ramp_agent', 'Ramp Coordinator', ['overview', 'flights', 'gates', 'baggage', 'fuel']],
        ['security.lead', 'Avery Johnson', 'security', 'Security Shift Lead', ['overview', 'terminal', 'transit', 'safety']],
        ['safety.inspect', 'Riley Carter', 'inspector', 'Safety Inspector', ['overview', 'airside', 'fuel', 'safety', 'weather']],
        ['executive.view', 'Casey Thompson', 'viewer', 'Operations Analyst', ['overview', 'flights', 'terminal', 'weather']],
    ];
    foreach ($demoUsers as [$username, $name, $role, $title, $permissions]) {
        $userId = insert_row($pdo, 'users', [
            'username' => $username,
            'password' => password_hash('admin123456', PASSWORD_DEFAULT),
            'full_name' => $name,
            'role' => $role,
            'position_title' => $title,
            'is_active' => 1,
        ]);
        foreach ($permissions as $section) {
            if (in_array($section, ALL_SECTIONS, true)) {
                insert_row($pdo, 'user_permissions', [
                    'user_id' => $userId,
                    'section_key' => $section,
                ]);
            }
        }
    }

    // Sample flights — ~2520 ops/day (1260 dep + 1260 arr) across 24 hours
    $flightIds = [];
    $carriers = ['DL','AA','UA','WN','B6','F9','NK','AS','BA','AF','LH','KL','QR','EK','VS','AM','JL','KE','SQ','CX','LA','AV','TP','IB','SK','OS','LX','EI','AC','WS'];
    $domDest = ['JFK','LAX','ORD','DFW','MIA','BOS','DEN','SEA','SFO','EWR','CLT','IAH','LAS','MCO','PHX','BWI','DTW','MSP','PHL','TPA','RDU','AUS','MSY','STL','IND','SLC','SAN','PDX','BNA','CLE','CMH','PIT','MCI','SAT','JAX','CHS','SAV','GSP','BHM','TYS'];
    $intlDest = ['CDG','LHR','FRA','AMS','MUC','MAD','FCO','DUB','YYZ','YUL','YVR','CUN','MEX','GRU','GIG','EZE','SCL','BOG','LIM','NRT','HND','ICN','PEK','PVG','HKG','SIN','BKK','DEL','BOM','DOH','DXB','AUH','IST','JNB','CPT','SYD','MEL','AKL','LIS','ATH','ZRH','VIE','CPH','ARN','OSL','WAW'];
    $acPool = array_keys($aircraftIds);
    $gatePool = array_keys($gateIds);
    // Preload seats by model
    $seatsByModel = [];
    foreach ($aircraftIds as $model => $aid) {
        $seatsByModel[$model] = (int)$pdo->query('SELECT seats_total FROM aircraft WHERE id=' . (int)$aid)->fetchColumn();
    }
    $pilots = ['James Wilson','Olivia Harris','Michael Davis','Sophia Clark','Ethan Martinez','Mia Anderson','Daniel Thomas','Emma Robinson','Lucas Bernard','Chloe Martin','Noah Walker','Isabella Hall','Henry Laurent','Amelia Scott','William Clarke','Sophie Reed','Ryan Cooper','Grace Lee','Carlos Rivera','Elena Vargas','Kenji Sato','Yuki Tanaka','Omar Al-Rashid','Fatima Hassan','Hans Mueller','Anna Schmidt','Daan de Vries','Sophie Bakker'];
    $delayReasons = ['Late inbound aircraft','Crew rotation','Air traffic flow','Weather at origin','Gate conflict','De-icing','Cabin issue'];

    $flightRows = [];
    $flightMeta = []; // for bags/gates after insert
    $tzAtl = new DateTimeZone('America/New_York');
    $todayBase = (new DateTime('today', $tzAtl))->getTimestamp();

    // High volume + realistic diurnal density (ATL-scale). Status is ALWAYS derived from
    // Atlanta wall-clock vs scheduled_time — never random phases for past/future slots.
    // Quiet 00–05 / 22–23: ~2 ops/min; peaks: 4 ops/min; shoulder 2–3.
    $TOTAL_TODAY = 3200;
    for ($i = 0; $i < $TOTAL_TODAY; $i++) {
        $isDep = ($i % 2 === 0);
        $type = $isDep ? 'dep' : 'arr';
        $isIntl = ($i % 5 === 0);
        $carrier = $carriers[$i % count($carriers)];
        $num = $carrier . (10000 + $i);

        $ac = $acPool[$i % count($acPool)];
        $seats = $seatsByModel[$ac] ?? 180;
        $load = 0.60 + (($i * 11) % 41) / 100.0;
        if ($load > 1.0) {
            $load = 1.0;
        }
        $pax = (int)round($seats * $load);
        $bags = 250 + ($i % 51);
        $minuteOfDay = (int)floor(($i / $TOTAL_TODAY) * 1440);
        $hour = (int)floor($minuteOfDay / 60);
        $quiet = ($hour <= 5 || $hour >= 22);
        $peak = (!$quiet && in_array($hour, [7, 8, 9, 11, 12, 16, 17, 18, 19], true));
        $opsThisMinute = $quiet ? 2 : ($peak ? 4 : 2 + ($minuteOfDay % 2));
        $slotInMinute = $i % max(1, $opsThisMinute);
        $scheduled = $todayBase + ($minuteOfDay * 60) + min(50, $slotInMinute * (int)floor(60 / max(1, $opsThisMinute)));
        $schedStr = date('Y-m-d H:i:s', $scheduled);

        // STRICT time-based status (same rules as live tick) — past morning flights cannot be taxiing at evening
        $status = status_from_schedule($type, $schedStr, $isIntl);

        // Sparse realistic delays only on flights still in future / near window
        $delay = null;
        $reason = null;
        $minsFromNow = (atl_now_ts() - $scheduled) / 60.0;
        if ($minsFromNow > -90 && $minsFromNow < 30 && ($i % 17 === 0) && $status !== 'Departed' && $status !== 'Arrived') {
            $status = 'Delayed';
            $delay = 12 + ($i % 38);
            $reason = $delayReasons[$i % count($delayReasons)];
        }

        if ($isDep) {
            $origin = 'ATL';
            $dest = $isIntl ? $intlDest[$i % count($intlDest)] : $domDest[$i % count($domDest)];
        } else {
            $dest = 'ATL';
            $origin = $isIntl ? $intlDest[($i + 3) % count($intlDest)] : $domDest[($i + 5) % count($domDest)];
        }
        $gateCode = $gatePool[$i % count($gatePool)];
        $gateId = $gateIds[$gateCode] ?? null;
        $pilot = $pilots[$i % count($pilots)];
        $copilot = $pilots[($i + 5) % count($pilots)];
        $crew = 4 + ($seats > 250 ? 8 : ($seats > 180 ? 4 : 2));
        $flightRows[] = [
            $num, $type, $origin, $dest, $aircraftIds[$ac] ?? null, $gateId, $status,
            $schedStr,
            $delay ? date('Y-m-d H:i:s', $scheduled + $delay * 60) : $schedStr,
            $pax, $bags, $pilot, $copilot, $crew, $isIntl ? 1 : 0, 0,
            date('Y-m-d H:i:s', $scheduled - 600), 0, $delay, $reason,
        ];
        $flightMeta[] = ['num' => $num, 'gate' => $gateCode, 'status' => $status, 'bags' => $bags, 'pax' => $pax];
    }

    // ~200 tomorrow scheduled
    $tomorrowBase = (new DateTime('tomorrow', $tzAtl))->getTimestamp();
    for ($i = 0; $i < 200; $i++) {
        $isDep = ($i % 2 === 0);
        $type = $isDep ? 'dep' : 'arr';
        $isIntl = ($i % 4 === 0);
        $carrier = $carriers[$i % count($carriers)];
        $num = $carrier . (30000 + $i);
        $ac = $acPool[($i + 9) % count($acPool)];
        $seats = $seatsByModel[$ac] ?? 180;
        $minuteOfDay = (int)floor(($i / 200) * 1440);
        $scheduled = $tomorrowBase + ($minuteOfDay * 60);
        if ($isDep) {
            $origin = 'ATL';
            $dest = $isIntl ? $intlDest[$i % count($intlDest)] : $domDest[$i % count($domDest)];
        } else {
            $dest = 'ATL';
            $origin = $isIntl ? $intlDest[$i % count($intlDest)] : $domDest[$i % count($domDest)];
        }
        $gateCode = $gatePool[($i + 20) % count($gatePool)];
        $flightRows[] = [
            $num, $type, $origin, $dest, $aircraftIds[$ac] ?? null, $gateIds[$gateCode] ?? null, 'Scheduled',
            date('Y-m-d H:i:s', $scheduled), date('Y-m-d H:i:s', $scheduled),
            0, 0, $pilots[$i % count($pilots)], $pilots[($i + 2) % count($pilots)], 6, $isIntl ? 1 : 0, 0,
            date('Y-m-d H:i:s', $scheduled), 1, null, null,
        ];
        $flightMeta[] = ['num' => $num, 'gate' => $gateCode, 'status' => 'Scheduled', 'bags' => 0, 'pax' => 0];
    }

    batch_insert($pdo, 'flights', [
        'flight_number','type','origin','destination','aircraft_id','gate_id','status',
        'scheduled_time','estimated_time','pax_accepted','bags_count','pilot_name','copilot_name',
        'cabin_crew','is_international','is_manual','phase_started_at','is_tomorrow','delay_minutes','delay_reason',
    ], $flightRows, 200);

    // Hard align any residual past slots (status_from_schedule already did the heavy work)
    $nowAtl = atl_now_str('Y-m-d H:i:s');
    $pdo->prepare("UPDATE flights SET status='Departed', phase_started_at=" . sql_now() . ", updated_at=" . sql_now() . "
        WHERE is_tomorrow=0 AND type='dep' AND status NOT IN ('Departed','Cancelled')
          AND scheduled_time <= " . sql_dt_minus("?", 15) . "")->execute([$nowAtl]);
    $pdo->prepare("UPDATE flights SET status='Arrived', phase_started_at=" . sql_now() . ", updated_at=" . sql_now() . "
        WHERE is_tomorrow=0 AND type='arr' AND status NOT IN ('Arrived','Cancelled')
          AND scheduled_time <= " . sql_dt_minus("?", 55) . "")->execute([$nowAtl]);

    if (is_sqlite()) {
        $pdo->exec('UPDATE flights SET seats_total = (SELECT seats_total FROM aircraft WHERE aircraft.id = flights.aircraft_id) WHERE aircraft_id IS NOT NULL');
    } else {
        $pdo->exec('UPDATE flights f JOIN aircraft a ON a.id = f.aircraft_id SET f.seats_total = a.seats_total WHERE f.aircraft_id IS NOT NULL');
    }

    // Map flight numbers to ids
    $stMap = $pdo->query('SELECT id, flight_number, gate_id, status FROM flights WHERE is_tomorrow=0')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stMap as $row) {
        $flightIds[$row['flight_number']] = (int)$row['id'];
    }

    // Dense gate occupation for active / near-active turnarounds so halls start nearly full
    $activeStatuses = ['Boarding','Final Call','Pushback','Taxi to Runway','Landing','Taxi to Gate','Deboarding','Cleaning','Ready at Gate','On Time'];
    $occupyCount = 0;
    foreach ($stMap as $row) {
        if (!$row['gate_id']) {
            continue;
        }
        if (in_array($row['status'], $activeStatuses, true)) {
            $pdo->prepare('UPDATE gates SET status=?, current_flight_id=?, occupied_since=? WHERE id=? AND is_reserve=0')
                ->execute(['occupied', $row['id'], date('Y-m-d H:i:s', strtotime('-20 minutes')), $row['gate_id']]);
            $occupyCount++;
        }
    }

    // Enforce the same high-utilization policy as live tick (1 free busy / 2 free quiet per hall)
    sim_gate_occupancy_cycle($pdo);
    sim_release_stale_gates($pdo);

    $freeNow = (int)$pdo->query("SELECT COUNT(*) FROM gates WHERE status='available' AND is_reserve=0")->fetchColumn();
    $occNow = (int)$pdo->query("SELECT COUNT(*) FROM gates WHERE status='occupied' AND is_reserve=0")->fetchColumn();
    $log[] = count($flightIds) . ' today flights + 200 tomorrow seeded. Statuses aligned to Atlanta clock. Gates: occupied=' . $occNow . ', free(non-reserve)=' . $freeNow . '.';

    // Cancelled
    $cancelledFlights = [
        ['NK1207', 'A320', 'ATL', 'FLL', 'today 06:45', 142, 'Crew availability', 'NK9207'],
        ['F91102', 'A320neo', 'ATL', 'DEN', 'today 11:25', 155, 'Operational disruption', 'F91302'],
        ['UA1547', 'B757-200', 'ATL', 'EWR', 'today 13:20', 168, 'Air traffic restrictions', null],
    ];
    
    foreach ($cancelledFlights as [$number, $aircraft, $origin, $destination, $time, $pax, $reason, $replacement]) {
        insert_row($pdo, 'cancelled_flights', [
            'flight_number' => $number,
            'aircraft_code' => $aircraft,
            'origin' => $origin,
            'destination' => $destination,
            'scheduled_time' => date('Y-m-d H:i:s', strtotime($time)),
            'pax' => $pax,
            'reason' => $reason,
            'replacement_flight' => $replacement,
        ]);
    }

    // Staff — ~63,000 across ATL workforce categories (batch insert)
    $firstNames = ['James','Maria','Robert','Sarah','Michael','Emily','David','Jessica','William','Ashley','Christopher','Amanda','Daniel','Melissa','Matthew','Nicole','Anthony','Stephanie','Mark','Laura','Paul','Karen','Steven','Lisa','Andrew','Nancy','Joshua','Betty','Kevin','Helen','Brian','Sandra','George','Donna','Edward','Carol','Ronald','Ruth','Timothy','Sharon','Jason','Michelle','Jeffrey','Ryan','Kimberly','Jacob','Deborah','Gary','Dorothy','Nicholas','Amy','Eric','Angela','Jonathan','Brenda','Stephen','Emma','Larry','Olivia','Justin','Cynthia','Scott','Marie','Brandon','Janet','Benjamin','Catherine','Samuel','Frances','Raymond','Christine','Gregory','Samantha','Frank','Debra','Alexander','Rachel','Patrick','Carolyn','Jack','Dennis','Jerry','Heather','Tyler','Diane','Aaron','Julie','Jose','Joyce','Adam','Victoria','Nathan','Kelly','Henry','Christina','Douglas','Joan','Zachary','Evelyn','Aisha','Omar','Priya','Wei','Yuki','Hans','Sofia','Diego','Fatima'];
    $lastNames = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores','Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts','Gomez','Phillips','Evans','Turner','Diaz','Parker','Cruz','Edwards','Collins','Reyes','Stewart','Morris','Morales','Murphy','Cook','Rogers','Gutierrez','Ortiz','Morgan','Cooper','Peterson','Bailey','Reed','Kelly','Howard','Ramos','Kim','Cox','Ward','Richardson','Watson','Brooks','Chavez','Wood','James','Bennett','Gray','Mendoza','Ruiz','Hughes','Price','Alvarez','Castillo','Sanders','Patel','Myers','Long','Ross','Foster','Jimenez'];
    // Ensure departments exist for all categories
    $extraDepts = [
        ['AIRLINE', 'Airline Operations'],
        ['TSA', 'TSA / Checkpoint Security'],
        ['CBP', 'Customs & Border Protection'],
        ['APD', 'Airport Police'],
        ['DOA', 'Dept of Aviation / City'],
        ['CONC', 'Concessions & Retail'],
        ['GT', 'Ground Transportation'],
        ['CLEAN', 'Terminal Cleaning'],
        ['INFO', 'Passenger Information'],
        ['CUST', 'Customer Service'],
        ['TECH', 'IT & Systems'],
        ['MED', 'Airport Medical'],
    ];
    foreach ($extraDepts as [$code, $name]) {
        if (!isset($departmentIds[$code])) {
            $departmentIds[$code] = insert_row($pdo, 'departments', [
                'code' => $code,
                'name' => $name,
                'is_active' => 1,
            ]);
        }
    }
    $roleMap = [
        'AIRLINE' => ['Flight Attendant','Check-in Agent','Gate Agent','Delta Ops Agent','Airline Supervisor','Baggage Service Agent'],
        'GATE' => ['Gate Agent','Senior Gate Agent','Passenger Service Agent','Gate Lead'],
        'RAMP' => ['Ramp Agent','Lead Ramp','Baggage Loader','GSE Operator','Pushback Driver'],
        'BHS' => ['Baggage Handler','BHS Technician','Sortation Operator','Claim Agent'],
        'TSA' => ['TSA Officer','TSA Lead','TSA Supervisor','Travel Document Checker'],
        'CBP' => ['CBP Officer','Immigration Officer','Customs Inspector'],
        'APD' => ['Airport Police Officer','Police Sergeant','K-9 Officer','Traffic Officer'],
        'SEC' => ['Security Officer','Access Control','Security Supervisor'],
        'DOA' => ['DoA Analyst','Terminal Manager','Finance Specialist','Engineering Tech','OCC Specialist'],
        'OPS' => ['Duty Manager','Ops Coordinator','OCC Specialist'],
        'FAC' => ['Facilities Technician','HVAC Tech','Electrician','Plumber','Painter'],
        'CLEAN' => ['Cleaner','Lead Cleaner','Restroom Attendant','Cabin Clean Coordinator'],
        'CONC' => ['Retail Associate','Food Court Staff','Duty-Free Associate','Concession Supervisor','Barista'],
        'GT' => ['Plane Train Operator','Shuttle Driver','Parking Attendant','Ground Transport Coord','Taxi Dispatch'],
        'ARFF' => ['Firefighter','ARFF Captain','Rescue Specialist','ARFF Driver'],
        'MED' => ['EMT','Airport Nurse','Medical Response Lead'],
        'ATC' => ['Air Traffic Controller','Tower Supervisor','Ground Controller'],
        'INFO' => ['Information Desk','Wayfinding Ambassador'],
        'CUST' => ['Customer Service Agent','Special Assistance','Complaint Resolution'],
        'TECH' => ['Systems Admin','Network Tech','Helpdesk'],
    ];
    // Target ~63,000 total
    $headcount = [
        'AIRLINE' => 28500,
        'GATE' => 1200,
        'RAMP' => 2800,
        'BHS' => 1500,
        'TSA' => 4500,
        'CBP' => 1800,
        'APD' => 1200,
        'SEC' => 800,
        'DOA' => 900,
        'OPS' => 600,
        'FAC' => 1100,
        'CLEAN' => 2800,
        'CONC' => 11000,
        'GT' => 3500,
        'ARFF' => 350,
        'MED' => 150,
        'ATC' => 280,
        'INFO' => 320,
        'CUST' => 400,
        'TECH' => 300,
    ];
    $zones = ['Concourse T','Concourse A','Concourse B','Concourse C','Concourse D','Concourse E','Concourse F','Domestic Terminal','International Terminal','Ramp North','Ramp South','BHS East','BHS West','Main Checkpoint','North Checkpoint','OCC','Control Tower','Station 1','Utility Plant','Parking North','Parking South','Plane Train','Duty Free Hall','Food Court B','Immigration E'];
    $shifts = ['morning','afternoon','night'];
    $statuses = ['on_duty','on_duty','on_duty','on_duty','break','off','off'];
    $staffRows = [];
    $empSeq = 1;
    $fnN = count($firstNames);
    $lnN = count($lastNames);
    foreach ($headcount as $deptCode => $n) {
        if (!isset($departmentIds[$deptCode])) {
            continue;
        }
        $roles = $roleMap[$deptCode] ?? ['Staff'];
        $deptId = $departmentIds[$deptCode];
        for ($i = 0; $i < $n; $i++) {
            $fn = $firstNames[($empSeq + $i) % $fnN];
            $ln = $lastNames[($empSeq * 3 + $i * 7) % $lnN];
            $role = $roles[$i % count($roles)];
            $code = 'ATL' . str_pad((string)$empSeq, 6, '0', STR_PAD_LEFT);
            $staffRows[] = [
                $code, $fn, $ln, $role, $deptId,
                $shifts[$i % 3], $zones[($i + $empSeq) % count($zones)],
                $statuses[$i % count($statuses)], 1,
                date('Y-m-d', strtotime('-' . (1 + ($i % 15)) . ' years')),
            ];
            $empSeq++;
        }
    }
    batch_insert($pdo, 'staff', [
        'employee_code','first_name','last_name','role','department_id','shift','zone','status','is_active','hired_at',
    ], $staffRows, 500);
    $log[] = ($empSeq - 1) . ' staff seeded across ' . count($headcount) . ' departments (64,000 ATL workforce).';

    // Baggage — 250–300 bag rows for primary flights + lighter sample for more flights
    $ownerFirst = ['Alex','Jamie','Sam','Robin','Drew','Kendall','Leslie','Chris','Camille','Marie','Helen','Tom','Zoe','Ivan','Nora','Mason','Chloe','Liam','Ava','Priya','Elena','Omar','Wei','Yuki','Sofia','Diego'];
    $ownerLast = ['Morgan','Chen','Patel','Garcia','Miller','Brown','Davis','Wilson','Dubois','Lefevre','Brooks','Hardy','Mitchell','Petrov','Kim','Reed','Adams','Torres','Park','Shah','Nguyen','Ross','Foster','Alvarez'];
    $bagStatuses = ['checked','in_system','in_transit','delivered','checked','in_transit','in_system','delivered'];
    $bagRows = [];
    $bagSeq = 18400;
    // Full density: 45 flights × ~270 bags ≈ 12k rows (UI has enough to show)
    $fullFlights = $pdo->query("SELECT id, flight_number, bags_count FROM flights WHERE is_tomorrow=0 ORDER BY scheduled_time LIMIT 45")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($fullFlights as $frow) {
        $fn = $frow['flight_number'];
        $fid = (int)$frow['id'];
        $n = max(250, min(300, (int)($frow['bags_count'] ?? 270)));
        for ($b = 0; $b < $n; $b++) {
            $bagSeq++;
            $st = $bagStatuses[($bagSeq + $b) % count($bagStatuses)];
            if ($bagSeq % 33 === 0) $st = 'missing';
            elseif ($bagSeq % 47 === 0) $st = 'damaged';
            elseif ($bagSeq % 61 === 0) $st = 'wrong_location';
            $bagRows[] = [
                'ATL' . str_pad((string)$bagSeq, 8, '0', STR_PAD_LEFT),
                $fid,
                $fn,
                $ownerFirst[$bagSeq % count($ownerFirst)] . ' ' . $ownerLast[($bagSeq * 3) % count($ownerLast)],
                round(12 + (($bagSeq * 3) % 180) / 10, 1),
                $st,
                'BHS-' . chr(65 + ($b % 6)) . (1 + ($b % 3)),
                date('Y-m-d H:i:s', strtotime('-' . (2 + ($bagSeq % 90)) . ' minutes')),
            ];
        }
    }
    // Extra light sample for more flight numbers in search
    $more = $pdo->query("SELECT id, flight_number, bags_count FROM flights WHERE is_tomorrow=0 ORDER BY id LIMIT 200 OFFSET 45")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($more as $frow) {
        $fn = $frow['flight_number'];
        $fid = (int)$frow['id'];
        $n = 8;
        for ($b = 0; $b < $n; $b++) {
            $bagSeq++;
            $st = $bagStatuses[($bagSeq + $b) % count($bagStatuses)];
            $bagRows[] = [
                'ATL' . str_pad((string)$bagSeq, 8, '0', STR_PAD_LEFT),
                $fid,
                $fn,
                $ownerFirst[$bagSeq % count($ownerFirst)] . ' ' . $ownerLast[($bagSeq * 3) % count($ownerLast)],
                round(12 + (($bagSeq * 3) % 180) / 10, 1),
                $st,
                'BHS-' . chr(65 + ($b % 6)) . (1 + ($b % 3)),
                date('Y-m-d H:i:s', strtotime('-' . (2 + ($bagSeq % 40)) . ' minutes')),
            ];
        }
    }
    batch_insert($pdo, 'baggage', [
        'bag_id','flight_id','flight_number','owner_name','weight_kg','status','belt_code','updated_at',
    ], $bagRows, 400);
    $log[] = count($bagRows) . ' baggage records seeded (250–300 per primary flight).';

    $belts = [
        ['BHS-A1', 'Concourse A Sortation', 84, 'running'],
        ['BHS-B1', 'Concourse B Sortation', 61, 'running'],
        ['BHS-C2', 'Concourse C Transfer', 0, 'idle'],
        ['BHS-D1', 'Concourse D Sortation', 47, 'running'],
        ['BHS-E2', 'International Transfer', 29, 'running'],
        ['CLAIM-5', 'Domestic Claim Belt 5', 0, 'fault'],
    ];
    foreach ($belts as [$code, $name, $count, $status]) {
        insert_row($pdo, 'bhs_belts', [
            'code' => $code,
            'name' => $name,
            'bags_on_belt' => $count,
            'status' => $status,
        ]);
    }

    $tanks = [
        // capacity_gal stored; 19,000,000 L ≈ 5,019,000 gal each
        ['Jet A North 1', 'jet_a', 5019000, 78, 20],
        ['Jet A North 2', 'jet_a', 5019000, 65, 20],
        ['Jet A South 1', 'jet_a', 5019000, 22, 20],
        ['Jet A South 2', 'jet_a', 5019000, 55, 20],
        ['Jet A Reserve', 'jet_a', 5019000, 88, 20],
        ['SAF Blend 1', 'saf', 5019000, 40, 20],
        ['SAF Blend Tank', 'saf', 250000, 43, 20],
    ];
    foreach ($tanks as [$name, $type, $capacity, $level, $threshold]) {
        insert_row($pdo, 'fuel_tanks', [
            'name' => $name,
            'fuel_type' => $type,
            'capacity_gal' => $capacity,
            'level_pct' => $level,
            'low_threshold_pct' => $threshold,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    foreach ([74.20, 71.80, 76.40, 79.10, 83.70, 89.30, 94.60, 91.20] as $index => $mwh) {
        insert_row($pdo, 'energy_monthly', [
            'year' => 2026,
            'month' => $index + 1,
            'mwh' => $mwh,
            'is_actual' => $index < 7 ? 1 : 0,
        ]);
    }

    $terminalZones = [
        ['CONC_T', 'Concourse T', 8, 42, 6, 1840],
        ['CONC_A', 'Concourse A', 14, 68, 8, 3210],
        ['CONC_B', 'Concourse B', 11, 57, 7, 2885],
        ['CONC_C', 'Concourse C', 19, 79, 5, 3560],
        ['CONC_D', 'Concourse D', 16, 73, 6, 3375],
        ['CONC_E', 'Concourse E', 9, 51, 7, 2410],
        ['CONC_F', 'Concourse F', 12, 62, 5, 2195],
    ];
    foreach ($terminalZones as [$code, $name, $wait, $density, $lanes, $pax]) {
        insert_row($pdo, 'terminal_zones', [
            'zone_code' => $code,
            'name' => $name,
            'wait_minutes' => $wait,
            'density_pct' => $density,
            'open_lanes' => $lanes,
            'pax_inside' => $pax,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $transitLines = [
        ['PLANE', 'ATL Plane Train', 'on', 25, 250, 74, 'Domestic Terminal ↔ Concourses T–F'],
        ['RED', 'MARTA Red Line', 'on', 35, 180, 63, 'North Springs ↔ Airport'],
        ['GOLD', 'MARTA Gold Line', 'on', 35, 180, 58, 'Doraville ↔ Airport'],
        ['SKYTRAIN', 'ATL SkyTrain', 'on', 40, 100, 46, 'Airport ↔ Rental Car Center'],
        ['SHUTTLE', 'Terminal Shuttle', 'maintenance', 20, 55, 0, 'Domestic ↔ International Terminal'],
    ];
    foreach ($transitLines as [$code, $name, $status, $speed, $capacity, $load, $route]) {
        insert_row($pdo, 'transit_lines', [
            'code' => $code,
            'name' => $name,
            'status' => $status,
            'speed_mph' => $speed,
            'capacity_per_trip' => $capacity,
            'avg_load_pct' => $load,
            'route_label' => $route,
        ]);
    }

    // Ground fleet — taxis, vans, buses (ATL licensed operators)
    $fleet = [
        // Taxis ~1,580 licensed; ~300 on-site queues
        ['taxi', 'economy', 'Atlanta Airport Cab / City Metered', 'Toyota Camry', 4, 900, 180, 'active', 'Flat ~$36–40 Downtown / metered elsewhere', 'Standard metered taxi'],
        ['taxi', 'economy', 'Atlanta Airport Cab / City Metered', 'Honda Accord', 4, 420, 80, 'active', 'Flat ~$36–40 Downtown / metered elsewhere', 'Standard metered taxi'],
        ['taxi', 'vip', 'Authorized Black Car / Terminal Transfer', 'Lincoln Town Car', 3, 120, 25, 'active', '$60–110 by trip / hourly', 'VIP black car'],
        ['taxi', 'vip', 'Authorized Black Car / Terminal Transfer', 'Lincoln Continental', 3, 60, 12, 'active', '$70–120 by trip', 'VIP sedan'],
        ['taxi', 'vip', 'Authorized Black Car / Terminal Transfer', 'Mercedes-Benz S-Class', 3, 40, 8, 'active', '$90–150+ by trip', 'Luxury VIP'],
        ['taxi', 'vip', 'Authorized SUV Transfer', 'Chevrolet Suburban', 6, 25, 6, 'active', '$90–140 with luggage', 'Large SUV'],
        ['taxi', 'vip', 'Authorized SUV Transfer', 'Cadillac Escalade', 6, 15, 4, 'active', '$100–160 with luggage', 'Large SUV'],
        // Vans
        ['van', 'economy', 'Atlanta Airport Cab / GWT', 'Ford Transit / E-350', 9, 180, 35, 'active', '$70–120 group shuttle', 'Economy group van 7–11 pax'],
        ['van', 'economy', 'Greene Worldwide Transportation', 'Ford Transit', 11, 90, 18, 'active', '$70–120 group shuttle', 'Economy group van'],
        ['van', 'executive', 'GWT Executive / Terminal VIP', 'Mercedes-Benz Sprinter', 12, 70, 14, 'active', '$150–300 executive / hourly', 'Executive Sprinter 8–14 pax'],
        ['van', 'executive', 'Authorized Executive Transfer', 'Mercedes-Benz Sprinter', 14, 40, 8, 'active', '$180–300+ chartered', 'VIP Sprinter'],
        // Buses & shuttles
        ['bus', 'airport_shuttle', 'ATL Airport Shuttle Connector', 'New Flyer / Gillig Low Floor', 45, 28, 12, 'active', 'Airport internal — free or parking-linked', 'Terminal↔terminal / parking 35–50 pax'],
        ['bus', 'parking_shuttle', 'ATL Parking Shuttle', 'Ford E-Series / Transit shuttle', 20, 35, 15, 'active', 'Included with Economy / Park-Ride', 'Parking shuttle 15–25 pax'],
        ['bus', 'marta', 'MARTA Bus', 'New Flyer / Gillig', 40, 18, 4, 'active', 'MARTA local fare', 'City bus at MARTA Airport Station'],
        ['shuttle', 'airport_shuttle', 'SkyTrain feeder / RCC link', 'Automated / rubber-tire shuttle', 100, 8, 6, 'active', 'Free with terminal access', 'Rental Car Center link'],
    ];
    foreach ($fleet as [$ftype, $sclass, $company, $model, $cap, $units, $onsite, $status, $fare, $notes]) {
        insert_row($pdo, 'ground_fleet', [
            'fleet_type' => $ftype,
            'service_class' => $sclass,
            'company' => $company,
            'model' => $model,
            'capacity_pax' => $cap,
            'unit_count' => $units,
            'on_site_now' => $onsite,
            'status' => $status,
            'fare_note' => $fare,
            'notes' => $notes,
        ]);
    }

    // Stations
    $stations = [
        ['MARTA-ATL', 'MARTA Airport Station', 'marta_rail', 'West side Domestic Terminal · between North & South baggage claim', 'Red Line, Gold Line', 'open'],
        ['PT-DOM', 'Plane Train · Domestic Terminal', 'plane_train', 'Domestic Terminal (post-security)', 'Plane Train T–F', 'open'],
        ['PT-A', 'Plane Train · Concourse A', 'plane_train', 'Concourse A', 'Plane Train', 'open'],
        ['PT-B', 'Plane Train · Concourse B', 'plane_train', 'Concourse B', 'Plane Train', 'open'],
        ['PT-C', 'Plane Train · Concourse C', 'plane_train', 'Concourse C', 'Plane Train', 'open'],
        ['PT-D', 'Plane Train · Concourse D', 'plane_train', 'Concourse D', 'Plane Train', 'open'],
        ['PT-E', 'Plane Train · Concourse E', 'plane_train', 'Concourse E', 'Plane Train', 'open'],
        ['PT-F', 'Plane Train · Concourse F / International', 'plane_train', 'Maynard H. Jackson Jr. International Terminal', 'Plane Train', 'open'],
        ['GTC-DOM', 'Ground Transportation Center · Domestic', 'taxi_queue', 'Ground level · Domestic Terminal curb', 'Taxi, Van, Ride-hail', 'open'],
        ['GTC-INT', 'Ground Transportation Center · International', 'van_queue', 'Ground level · International Terminal', 'Taxi, Van, Shuttle', 'open'],
        ['BUS-ECO-N', 'North Economy Parking Shuttle Stop', 'shuttle', 'North Economy Lot', 'Parking Shuttle', 'open'],
        ['BUS-ECO-S', 'South Economy Parking Shuttle Stop', 'shuttle', 'South Economy Lot', 'Parking Shuttle', 'open'],
        ['BUS-PR-DOM', 'Domestic Park-Ride Shuttle Stop', 'shuttle', 'Domestic Park-Ride Lot', 'Parking Shuttle', 'open'],
        ['BUS-MARTA', 'MARTA Bus Bays · Airport', 'bus', 'MARTA Airport Station bus bays', 'MARTA local bus', 'open'],
        ['SKY-RCC', 'SkyTrain · Rental Car Center', 'shuttle', 'Rental Car Center', 'SkyTrain', 'open'],
    ];
    foreach ($stations as [$code, $name, $stype, $loc, $lines, $status]) {
        insert_row($pdo, 'transit_stations', [
            'code' => $code,
            'name' => $name,
            'station_type' => $stype,
            'location' => $loc,
            'lines_served' => $lines,
            'status' => $status,
        ]);
    }

    // Parking lots
    $lots = [
        ['DAILY-N', 'Daily Parking North Deck', 'daily_deck', 1, 6200, 4850, 3.00, 36.00, 'Domestic Terminal North', '4-level covered deck', 'open'],
        ['DAILY-S', 'Daily Parking South Deck', 'daily_deck', 1, 6000, 5120, 3.00, 36.00, 'Domestic Terminal South', '4-level covered deck', 'open'],
        ['ATL-WEST', 'ATL West Parking Deck', 'daily_deck', 1, 5700, 3980, 2.00, 14.00, 'SkyTrain to terminal', 'Connected via SkyTrain', 'open'],
        ['INT-HR', 'International Hourly Deck', 'hourly', 1, 1100, 720, 3.00, null, 'International Terminal', 'Short-term / hourly', 'open'],
        ['INT-PR', 'International Park-Ride Deck', 'park_ride', 1, 2400, 1650, 2.00, 14.00, 'International area', 'Park & ride deck', 'open'],
        ['ECO-N', 'North Economy Lot', 'economy', 0, 1500, 980, 2.00, 12.00, 'Shuttle to Domestic', 'Surface economy', 'open'],
        ['ECO-S', 'South Economy Lot', 'economy', 0, 1100, 640, 2.00, 12.00, 'Shuttle to Domestic', 'Surface economy', 'open'],
        ['DOM-PR', 'Domestic Park-Ride', 'park_ride', 0, 4800, 3200, 2.00, 12.00, 'Shuttle to Domestic', 'Surface park-ride', 'open'],
        ['ATL-SEL', 'ATL Select', 'select', 1, 1500, 890, 3.00, 18.00, 'Domestic / oversized options', 'Covered + open + oversized', 'open'],
    ];
    foreach ($lots as [$code, $name, $ltype, $cov, $cap, $occ, $rh, $rd, $link, $notes, $status]) {
        insert_row($pdo, 'parking_lots', [
            'code' => $code,
            'name' => $name,
            'lot_type' => $ltype,
            'covered' => $cov,
            'capacity' => $cap,
            'occupied' => $occ,
            'rate_hourly' => $rh,
            'rate_daily' => $rd,
            'terminal_link' => $link,
            'notes' => $notes,
            'status' => $status,
        ]);
    }

    // Fares reference
    $fares = [
        ['taxi', 'economy', 'Downtown / Midtown flat', 36, 40, 38, 'USD', 'Official airport flat rate band'],
        ['taxi', 'vip', 'Black car / VIP sedan', 60, 110, null, 'USD', 'By trip or reservation'],
        ['taxi', 'vip', 'Large SUV (Suburban / Escalade)', 90, 160, null, 'USD', 'Luggage-friendly SUV'],
        ['van', 'economy', 'Group van 7–11 pax', 70, 120, null, 'USD', 'Shared / group transfer'],
        ['van', 'executive', 'Executive Sprinter 8–14 pax', 150, 300, null, 'USD', 'Charter / hourly executive'],
        ['marta', 'standard', 'One-way rail fare', 2.50, 2.50, 2.50, 'USD', 'Any distance one-way'],
        ['marta', 'standard', 'Breeze Card (media)', 2.00, 2.00, 2.00, 'USD', 'Reloadable card fee'],
        ['marta', 'standard', 'Day Pass', 9.00, 9.00, 9.00, 'USD', '24-hour pass'],
        ['marta', 'standard', '7-Day Pass', 23.75, 23.75, 23.75, 'USD', 'Weekly pass'],
        ['plane_train', 'airport_shuttle', 'Plane Train (airside)', 0, 0, 0, 'USD', 'Free after security'],
        ['bus', 'parking_shuttle', 'Economy / Park-Ride shuttle', 0, 0, 0, 'USD', 'Included with parking'],
    ];
    foreach ($fares as [$mode, $sclass, $route, $mn, $mx, $flat, $cur, $notes]) {
        insert_row($pdo, 'transit_fares', [
            'mode' => $mode,
            'service_class' => $sclass,
            'route_label' => $route,
            'fare_min' => $mn,
            'fare_max' => $mx,
            'fare_flat' => $flat,
            'currency' => $cur,
            'notes' => $notes,
        ]);
    }
    $log[] = 'Ground transport: taxis/vans/buses, 15 stations, 9 parking lots, fare table.';

    $securityZones = [
        ['SEC-NORTH', 'North Perimeter', 'clear'],
        ['SEC-SOUTH', 'South Perimeter', 'watch'],
        ['SEC-T', 'Domestic Terminal', 'clear'],
        ['SEC-A', 'Concourse A', 'clear'],
        ['SEC-D', 'Concourse D', 'watch'],
        ['SEC-E', 'Concourse E', 'clear'],
        ['SEC-F', 'Concourse F', 'clear'],
    ];
    foreach ($securityZones as [$code, $name, $status]) {
        insert_row($pdo, 'security_zones', [
            'code' => $code,
            'name' => $name,
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $alerts = [
        ['warning', 'Unattended bag under assessment', 'Concourse C food court', 'bag', 1, '-18 minutes', null],
        ['info', 'Perimeter sensor maintenance in progress', 'South service road', 'perimeter', 1, '-52 minutes', null],
        ['warning', 'Gate assignment conflict detected', 'Gate D15', 'gate_conflict', 1, '-12 minutes', null],
        ['info', 'Fire panel inspection completed', 'Concourse E', 'fire', 0, '-3 hours', '-2 hours'],
        ['warning', 'Restricted door forced-open alarm reviewed', 'Baggage Level 2', 'other', 0, '-5 hours', '-4 hours'],
        ['info', 'K-9 patrol sweep completed', 'International arrivals', 'other', 0, '-7 hours', '-6 hours'],
    ];
    foreach ($alerts as [$level, $title, $location, $category, $active, $created, $resolved]) {
        insert_row($pdo, 'safety_alerts', [
            'level' => $level,
            'title' => $title,
            'location' => $location,
            'category' => $category,
            'is_active' => $active,
            'created_at' => date('Y-m-d H:i:s', strtotime($created)),
            'resolved_at' => $resolved ? date('Y-m-d H:i:s', strtotime($resolved)) : null,
        ]);
    }

    $cameras = [
        ['CAM-T-01', 'Domestic Terminal Hall', 'https://placehold.co/640x360/172033/7dd3fc?text=Domestic+Terminal', 'online'],
        ['CAM-T-CKPT', 'Domestic Checkpoint North', 'https://placehold.co/640x360/172033/7dd3fc?text=Checkpoint+North', 'online'],
        ['CAM-A-12', 'Concourse A · Gate A12', 'https://placehold.co/640x360/172033/7dd3fc?text=Gate+A12', 'online'],
        ['CAM-A-RMP', 'Ramp A', 'https://placehold.co/640x360/172033/7dd3fc?text=Ramp+A', 'online'],
        ['CAM-B-CTR', 'Concourse B Center', 'https://placehold.co/640x360/172033/7dd3fc?text=Concourse+B', 'online'],
        ['CAM-B-RMP', 'Ramp B', 'https://placehold.co/640x360/172033/7dd3fc?text=Ramp+B', 'online'],
        ['CAM-C-SEC', 'Concourse C Security', 'https://placehold.co/640x360/3b2f17/fbbf24?text=Concourse+C', 'online'],
        ['CAM-D-FOOD', 'Concourse D Food Court', 'https://placehold.co/640x360/172033/7dd3fc?text=Concourse+D', 'online'],
        ['CAM-E-ARR', 'International Arrivals E', 'https://placehold.co/640x360/172033/7dd3fc?text=Intl+Arrivals', 'online'],
        ['CAM-E-IMM', 'Immigration Hall E', 'https://placehold.co/640x360/172033/7dd3fc?text=Immigration', 'online'],
        ['CAM-F-GATES', 'Concourse F Gates', 'https://placehold.co/640x360/172033/7dd3fc?text=Concourse+F', 'online'],
        ['CAM-BAG-5', 'Baggage Claim 5', 'https://placehold.co/640x360/172033/7dd3fc?text=Claim+5', 'online'],
        ['CAM-S-PER', 'South Perimeter', 'https://placehold.co/640x360/3b1820/f87171?text=South+Perimeter', 'offline'],
        ['CAM-N-PER', 'North Perimeter Fence', 'https://placehold.co/640x360/172033/7dd3fc?text=North+Perimeter', 'online'],
        ['CAM-TWY-08', 'Taxiway / Runway 08L view', 'https://placehold.co/640x360/172033/7dd3fc?text=Runway+08L', 'online'],
    ];
    
    foreach ($cameras as [$code, $zone, $snapshot, $status]) {
        insert_row($pdo, 'cameras', [
            'cam_code' => $code,
            'zone' => $zone,
            'stream_url' => null,
            'snapshot_url' => $snapshot,
            'is_live' => $status === 'online' ? 1 : 0,
            'status' => $status,
        ]);
    }

    $arffResources = [
        ['ARFF-01', 'Oshkosh Striker 3000 Unit 1', 3000, 96, 'ready'],
        ['ARFF-02', 'Oshkosh Striker 3000 Unit 2', 3000, 88, 'ready'],
        ['ARFF-03', 'Rapid Intervention Vehicle', 1500, 74, 'deployed'],
        ['ARFF-04', 'Foam Support Unit', 2000, 61, 'ready'],
        ['ARFF-05', 'Rescue Command Unit', 500, 35, 'maintenance'],
    ];
    foreach ($arffResources as [$code, $name, $capacity, $level, $status]) {
        insert_row($pdo, 'arff_resources', [
            'unit_code' => $code,
            'name' => $name,
            'water_capacity_gal' => $capacity,
            'water_level_pct' => $level,
            'status' => $status,
        ]);
    }

    // Atlanta late-summer weather (hot, humid, afternoon storms common)
    $weather = [
        [0, 26.1, 'clear', 'Clear', 220, 6, 10.0, 12000, 'none'],
        [1, 27.4, 'clear', 'Mostly Sunny', 230, 7, 10.0, 12000, 'none'],
        [2, 29.0, 'cloudy', 'Partly Cloudy', 240, 9, 10.0, 10000, 'none'],
        [3, 30.6, 'cloudy', 'Scattered Clouds', 250, 10, 10.0, 9000, 'low'],
        [4, 31.8, 'cloudy', 'Humid / Hazy', 255, 12, 8.0, 7000, 'low'],
        [5, 32.4, 'windy', 'Breezy', 260, 14, 8.0, 6000, 'low'],
        [6, 31.2, 'rain', 'Isolated Showers', 270, 16, 5.0, 4000, 'medium'],
        [7, 29.5, 'rain', 'Thunderstorm', 280, 18, 3.0, 2500, 'high'],
        [8, 28.0, 'rain', 'Light Rain', 275, 12, 6.0, 4500, 'medium'],
        [9, 27.2, 'cloudy', 'Clearing', 265, 8, 9.0, 8000, 'low'],
    ];
    
    $hourStart = strtotime(date('Y-m-d H:00:00'));
    foreach ($weather as [$offset, $temp, $code, $label, $windDir, $wind, $visibility, $ceiling, $impact]) {
        insert_row($pdo, 'weather_hourly', [
            'observed_at' => date('Y-m-d H:i:s', $hourStart + ($offset * 3600)),
            'temp_c' => $temp,
            'condition_code' => $code,
            'condition_label' => $label,
            'wind_dir_deg' => $windDir,
            'wind_kt' => $wind,
            'visibility_sm' => $visibility,
            'ceiling_ft' => $ceiling,
            'impact_level' => $impact,
        ]);
    }

    $atlTz = new DateTimeZone('America/New_York');
    for ($daysAgo = 4; $daysAgo >= 0; $daysAgo--) {
        $isToday = $daysAgo === 0;
        $d = new DateTime('now', $atlTz);
        if ($daysAgo > 0) {
            $d->modify("-{$daysAgo} days");
        }
        insert_row($pdo, 'airport_kpis', [
            'op_date' => $d->format('Y-m-d'),
            'ops_total' => $isToday ? 2520 : 2480 + ((4 - $daysAgo) * 15),
            'takeoffs' => $isToday ? 1260 : 1240 + ((4 - $daysAgo) * 8),
            'landings' => $isToday ? 1260 : 1240 + ((4 - $daysAgo) * 7),
            'otp_pct' => $isToday ? 86.40 : 84.10 + ((4 - $daysAgo) * 0.65),
            'gates_used' => $isToday ? 165 : 170 + (4 - $daysAgo),
            'active_alerts' => $isToday ? 3 : ($daysAgo % 3),
            'pax_today' => $isToday ? 285000 : 278000 + ((4 - $daysAgo) * 2500),
            'security_status' => 'SECURE',
        ]);
    }

    $reports = [
        ['Long checkpoint queue', 'Wait appears longer than posted near the north checkpoint.', 'warning', 'Domestic Terminal North', 1, '-10 minutes'],
        ['Escalator temporarily stopped', 'Passengers are being directed to the adjacent escalator.', 'info', 'Concourse B center', 1, '-26 minutes'],
        ['Water spill near gate', 'Cleaning team requested for a small spill.', 'info', 'Gate A17', 1, '-41 minutes'],
        ['Unattended item reported', 'Small backpack reported to airport staff.', 'warning', 'Concourse C food court', 1, '-58 minutes'],
        ['Shuttle sign is difficult to read', 'Digital wayfinding screen is flickering.', 'info', 'International Terminal', 1, '-2 hours'],
        ['Baggage claim crowding', 'Heavy crowd around claim belt 5.', 'warning', 'Domestic baggage claim', 0, '-3 hours'],
    ];
    foreach ($reports as [$title, $detail, $level, $location, $active, $created]) {
        insert_row($pdo, 'citizen_reports', [
            'title' => $title,
            'detail' => $detail,
            'level' => $level,
            'location' => $location,
            'is_active' => $active,
            'created_at' => date('Y-m-d H:i:s', strtotime($created)),
        ]);
    }

    $notifications = [
        ['warning', 'Flight delay', 'AA1468 to Dallas is delayed 35 minutes.', 0, 0, '-5 minutes'],
        ['primary', 'Gate activity', 'DL1042 boarding is active at Gate A12.', 0, 0, '-9 minutes'],
        ['warning', 'Safety review', 'An unattended bag is being assessed in Concourse C.', 0, 0, '-18 minutes'],
        ['danger', 'Low fuel threshold', 'Jet A South 1 is below its configured threshold.', 0, 1, '-24 minutes'],
        ['success', 'Runway inspection', 'Inspection team has entered runway 09R/27L.', 1, 0, '-38 minutes'],
        ['info', 'Transit maintenance', 'Terminal shuttle is temporarily under maintenance.', 1, 0, '-52 minutes'],
        ['success', 'Weather feed updated', 'The next ten hourly observations are available.', 1, 0, '-1 hour'],
        ['info', 'Shift handoff', 'Afternoon operations briefing is scheduled in the OCC.', 1, 0, '-2 hours'],
    ];
    foreach ($notifications as [$type, $title, $message, $read, $critical, $created]) {
        insert_row($pdo, 'notifications', [
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'is_read' => $read,
            'is_critical' => $critical,
            'created_at' => date('Y-m-d H:i:s', strtotime($created)),
        ]);
    }


    // Individual ground vehicles — taxis, vans, buses
    $taxiModels = [
        ['Toyota', 'Camry', 4, 'economy'],
        ['Honda', 'Accord', 4, 'economy'],
        ['Toyota', 'Camry Hybrid', 4, 'standard'],
        ['Lincoln', 'Town Car', 4, 'vip'],
        ['Lincoln', 'Continental', 4, 'vip'],
        ['Mercedes-Benz', 'S-Class', 4, 'vip'],
        ['Chevrolet', 'Suburban', 6, 'vip'],
        ['Cadillac', 'Escalade', 6, 'vip'],
    ];
    $vanModels = [
        ['Ford', 'Transit', 10, 'economy'],
        ['Ford', 'E350', 11, 'economy'],
        ['Mercedes-Benz', 'Sprinter', 12, 'executive'],
        ['Mercedes-Benz', 'Sprinter VIP', 14, 'executive'],
        ['Ford', 'Transit Passenger', 8, 'standard'],
    ];
    $busModels = [
        ['New Flyer', 'Xn40', 40, 'airport_shuttle'],
        ['Gillig', 'Low Floor', 45, 'airport_shuttle'],
        ['Ford', 'E-Series Shuttle', 20, 'parking_shuttle'],
        ['Golden Dragon', 'XML6125', 50, 'marta'],
        ['New Flyer', 'XD40', 38, 'airport_shuttle'],
    ];
    $drivers = ['Marcus Johnson','Elena Rodriguez','James Park','Aisha Williams','Carlos Mendez','Sarah Chen','David Okonkwo','Priya Sharma','Robert Hale','Nina Petrova','Kevin Brooks','Lisa Nguyen','Omar Hassan','Grace Kim','Tony Russo','Maya Patel','Chris Evans','Diana Foster','Sam Torres','Helen Wu'];
    $gvRows = [];
    $seq = 1;
    // ~80 taxis on-site sample
    for ($i = 0; $i < 80; $i++) {
        $m = $taxiModels[$i % count($taxiModels)];
        $gvRows[] = ['taxi', $m[3], 'TX' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT), 'ATL-' . (1000 + $i), $m[0], $m[1], $m[2], $drivers[$i % count($drivers)], 2 + ($i % 18), ($i % 9 === 0 ? 'on_trip' : ($i % 17 === 0 ? 'maintenance' : 'available')), 'Atlanta Airport Cab'];
        $seq++;
    }
    for ($i = 0; $i < 40; $i++) {
        $m = $vanModels[$i % count($vanModels)];
        $gvRows[] = ['van', $m[3], 'VN' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT), 'VAN-' . (2000 + $i), $m[0], $m[1], $m[2], $drivers[($i + 3) % count($drivers)], 1 + ($i % 12), ($i % 8 === 0 ? 'on_trip' : 'available'), $i % 2 ? 'Greene Worldwide Transportation' : 'Atlanta Airport Cab'];
        $seq++;
    }
    for ($i = 0; $i < 35; $i++) {
        $m = $busModels[$i % count($busModels)];
        $gvRows[] = ['bus', $m[3], 'BS' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT), 'BUS-' . (3000 + $i), $m[0], $m[1], $m[2], $drivers[($i + 7) % count($drivers)], 3 + ($i % 15), ($i % 11 === 0 ? 'on_trip' : 'available'), $m[3] === 'marta' ? 'MARTA' : 'ATL Airport Shuttle'];
        $seq++;
    }
    batch_insert($pdo, 'ground_vehicles', [
        'fleet_type','service_class','vehicle_code','plate_number','manufacturer','model','capacity_pax','driver_name','trips_today','status','company',
    ], $gvRows, 100);
    $log[] = count($gvRows) . ' ground vehicles (taxi/van/bus) seeded.';

    // Parking vehicles sample across lots
    $lots = $pdo->query('SELECT id, code, capacity, occupied FROM parking_lots')->fetchAll(PDO::FETCH_ASSOC);
    $carModels = [['Toyota','Camry'],['Honda','Civic'],['Ford','F-150'],['Tesla','Model 3'],['BMW','X5'],['Hyundai','Elantra'],['Chevrolet','Malibu'],['Jeep','Grand Cherokee'],['Nissan','Altima'],['Kia','Sorento']];
    $owners = ['John Smith','Maria Garcia','Wei Chen','Amina Yusuf','Paul Anderson','Sofia Rossi','James Lee','Emma Brown','Omar Ali','Julia Costa'];
    $pvRows = [];
    foreach ($lots as $li => $lot) {
        $n = min(25, max(5, (int)round(((int)$lot['occupied'] / max(1, (int)$lot['capacity'])) * 20)));
        for ($j = 0; $j < $n; $j++) {
            $cm = $carModels[($li + $j) % count($carModels)];
            $hours = [2, 4, 8, 12, 24, 48, 72][($j + $li) % 7];
            $status = ['parked','parked','parked','entering','exiting'][($j) % 5];
            $pvRows[] = [
                (int)$lot['id'],
                strtoupper(substr($cm[0], 0, 2)) . (1000 + $li * 30 + $j),
                $owners[($j + $li) % count($owners)],
                $cm[0], $cm[1], $status,
                $hours < 24 ? $hours : null,
                $hours >= 24 ? round($hours / 24, 1) : null,
                date('Y-m-d H:i:s', strtotime('-' . ($j + 1) . ' hours')),
                date('Y-m-d H:i:s', strtotime('+' . max(1, $hours - $j) . ' hours')),
            ];
        }
    }
    if ($pvRows) {
        batch_insert($pdo, 'parking_vehicles', [
            'lot_id','plate_number','owner_name','manufacturer','model','status','duration_hours','duration_days','parked_at','expected_leave',
        ], $pvRows, 100);
        $log[] = count($pvRows) . ' parking vehicles seeded.';
    }

    // Daily transit stats since midnight (Atlanta-style)
    $today = date('Y-m-d');
    $dailyStats = [
        ['taxi', 'economy', 420 + random_int(0, 80)],
        ['taxi', 'standard', 180 + random_int(0, 40)],
        ['taxi', 'vip', 55 + random_int(0, 20)],
        ['van', 'economy', 95 + random_int(0, 25)],
        ['van', 'standard', 40 + random_int(0, 15)],
        ['van', 'executive', 22 + random_int(0, 10)],
        ['bus', 'airport_shuttle', 210 + random_int(0, 40)],
        ['bus', 'parking_shuttle', 160 + random_int(0, 30)],
        ['bus', 'marta', 85 + random_int(0, 20)],
        ['metro', 'marta_rail', 4800 + random_int(0, 600)],
        ['metro', 'plane_train', 28000 + random_int(0, 4000)],
    ];
    foreach ($dailyStats as [$mode, $cls, $cnt]) {
        insert_row($pdo, 'transit_daily_stats', [
            'stat_date' => $today,
            'mode' => $mode,
            'service_class' => $cls,
            'trips_count' => $cnt,
        ]);
    }

    $log[] = 'Created realistic demo data across every schema table.';
    $log[] = '204 gates · ~80 worldwide airports · 32 aircraft models · 3000 daily flights + 64k staff.';
    $log[] = 'Admin user ID: ' . $adminId . '. Demo users password: admin123456';
}

// ---------- UI / request handling ----------
if (empty($_SESSION['seed_csrf'])) {
    $_SESSION['seed_csrf'] = bin2hex(random_bytes(24));
}

$result = null;
$error = null;
$seedType = null;
$log = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $seedType = (string)($_POST['seed_type'] ?? '');

    if (!hash_equals((string)$_SESSION['seed_csrf'], $token)) {
        $error = 'The request token is invalid. Refresh the page and try again.';
    } elseif (!in_array($seedType, ['initial', 'full'], true)) {
        $error = 'Unknown seed type.';
    } else {
        try {
            $pdo = db();
            reset_database($pdo, $log);
            $pdo->beginTransaction();
            try {
                if ($seedType === 'initial') {
                    seed_initial($pdo, $log);
                } else {
                    seed_full($pdo, $log);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            $result = $seedType === 'initial' ? 'Initial seed completed.' : 'Full demo seed completed.';
            $_SESSION['seed_csrf'] = bin2hex(random_bytes(24));
        } catch (Throwable $exception) {
            try {
                if (is_sqlite()) { db()->exec('PRAGMA foreign_keys = ON'); } else { db()->exec('SET FOREIGN_KEY_CHECKS=1'); }
            } catch (Throwable $ignored) {
            }
            $error = $exception->getMessage();
        }
    }
}

$token = htmlspecialchars((string)$_SESSION['seed_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ATL Airport — Database Seeder</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
:root {
  --bg:#06070a; --bg2:#0c0e12; --panel:#12141a; --panel2:#181b22;
  --border:rgba(180,190,210,.12); --border2:rgba(190,200,220,.18);
  --text:#f2f3f7; --text2:#a4aab8; --muted:#6b7285;
  --blue:#3b82f6; --cyan:#22d3ee; --green:#22c55e; --amber:#eab308; --red:#ef4444;
  --radius:14px; --font:Inter,system-ui,sans-serif; --mono:"JetBrains Mono",monospace;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--font);min-height:100vh;-webkit-font-smoothing:antialiased}
.wrap{max-width:920px;margin:48px auto;padding:0 18px 48px}
.eyebrow{color:var(--cyan);font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;margin-bottom:8px}
h1{font-size:26px;font-weight:700;margin-bottom:8px}
.lead{color:var(--text2);line-height:1.65;margin:0 0 22px;max-width:720px;font-size:14px}
.notice{border:1px solid rgba(234,179,8,.28);background:rgba(234,179,8,.08);color:#f6d77a;border-radius:var(--radius);padding:12px 14px;margin-bottom:18px;font-size:13px;line-height:1.5}
.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
@media(max-width:720px){.grid{grid-template-columns:1fr}}
.card{background:linear-gradient(180deg,rgba(28,32,40,.96),rgba(16,18,24,.98));border:1px solid var(--border);border-radius:var(--radius);padding:20px;box-shadow:0 1px 0 rgba(255,255,255,.03) inset}
.card.full{border-color:rgba(34,211,238,.22);grid-column:1/-1}
.tag{display:inline-flex;padding:4px 9px;border-radius:999px;background:rgba(59,130,246,.13);color:#93c5fd;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.full .tag{background:rgba(34,211,238,.12);color:#67e8f9}
h2{font-size:17px;margin:12px 0 8px;font-weight:600}
.desc{color:var(--muted);font-size:13px;line-height:1.55;margin-bottom:16px;min-height:48px}
ul.points{margin:0 0 16px 18px;color:var(--text2);font-size:13px;line-height:1.6}
button[type=submit]{appearance:none;width:100%;border:1px solid transparent;border-radius:10px;padding:11px 14px;font:600 13px var(--font);cursor:pointer;background:linear-gradient(135deg,var(--blue),#2563eb);color:#fff}
button[type=submit]:hover{filter:brightness(1.08)}
.card.full button[type=submit]{background:linear-gradient(135deg,rgba(34,211,238,.35),rgba(59,130,246,.55));border-color:rgba(34,211,238,.35)}
.credentials,.actions{margin-top:16px;padding:14px 16px;border-radius:var(--radius);border:1px solid var(--border);background:var(--panel);font-size:13px;color:var(--text2)}
.credentials code{font-family:var(--mono);color:var(--cyan);background:rgba(0,0,0,.25);padding:2px 6px;border-radius:6px}
.actions{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.actions a{color:var(--cyan);text-decoration:none;font-weight:600}
.actions a:hover{text-decoration:underline}
.result,.error{margin-bottom:18px;border-radius:var(--radius);padding:14px 16px;font-size:13px;line-height:1.55;border:1px solid}
.result{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.35);color:#86efac}
.error{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.4);color:#fca5a5}
.result ul{margin:10px 0 0 18px}
    </style>
</head>
<body>
<main class="wrap">
    <div class="eyebrow">ATL Airport</div>
    <h1>Database Seeder</h1>
    <p class="lead">Initialize or fully populate the database (MySQL or SQLite) for the ATL Airport dashboard. Use <strong>Initial</strong> for empty structure + admin; use <strong>Full Seed</strong> for realistic flights, staff, bags and transit data.</p>

    <div class="notice">⚠ Full Seed permanently deletes existing operational data and rebuilds it. Run only when you intend to reset the database.</div>

<?php if ($result !== null): ?>
    <div class="result">
        <strong><?= htmlspecialchars($result, ENT_QUOTES, 'UTF-8') ?></strong>
        <ul>
<?php foreach ($log as $line): ?>
            <li><?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
        </ul>
    </div>
<?php elseif ($error !== null): ?>
    <div class="error"><strong>Error:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

    <div class="grid">
        <section class="card">
            <span class="tag">Safe</span>
            <h2>Initial seed</h2>
            <p class="desc">Creates admin user, terminals, runways, aircraft models, empty gates and KPI shell. Does not load daily flight volume.</p>
            <ul class="points">
                <li>Admin login ready</li>
                <li>Empty gates + aircraft catalog</li>
                <li>Safe to run first</li>
            </ul>
            <form method="post" onsubmit="return confirmSeed('INITIAL')">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="seed_type" value="initial">
                <button type="submit">Run Initial Seed</button>
            </form>
        </section>

        <section class="card full">
            <span class="tag">Full reset</span>
            <h2>Full seed</h2>
            <p class="desc">Rebuilds the complete demo dataset: ~3000 daily flights, 64k staff, bags 250–300 per flight, transit, fuel and weather samples.</p>
            <ul class="points">
                <li>Flights + gates linked by schedule</li>
                <li>Staff roster 64,000 · baggage rows</li>
                <li>Transit / fuel / safety samples</li>
            </ul>
            <form method="post" onsubmit="return confirmSeed('FULL')">
                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                <input type="hidden" name="seed_type" value="full">
                <button type="submit">Run Full Seed</button>
            </form>
        </section>
    </div>

    <div class="credentials">
        Admin login: <code>admin</code> / <code>admin123456</code>
        · Demo password: <code>admin123456</code>
    </div>
    <div class="actions">
        <a href="../index.php">Open Dashboard →</a>
        <a href="../setup.php">Setup Check</a>
    </div>
</main>
<script>
function confirmSeed(type) {
  return window.confirm('Run the ' + type + ' seed?\n\nThis will change database data. Full Seed deletes and replaces operational rows.');
}
</script>
</body>
</html>
