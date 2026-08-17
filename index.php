<!DOCTYPE html>
<html lang="en">
<head>
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ATL Airport — Operations Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin=""/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer"/>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
</head>
<body>
<div id="alertStack"></div>

<div id="loginScreen">
  <div class="login-card">
    <div class="login-logo">
      <img src="assets/img/ATL%20logo.svg" alt="ATL" class="atl-logo-login">
      <div class="brand">Hartsfield-Jackson<br>Atlanta International Airport®</div>
      <div class="sub">Airport Management System</div>
    </div>
    <h1>Sign in</h1>
    <p class="hint">Enter your credentials</p>
    <div class="field"><label>Username</label><input type="text" id="loginUser" autocomplete="username"></div>
    <div class="field"><label>Password</label><input type="password" id="loginPass" autocomplete="current-password"></div>
    <div class="field">
      <label>Captcha</label>
      <div class="captcha-row">
        <div class="captcha-box" id="captchaDisplay">----</div>
        <button type="button" class="captcha-refresh" id="captchaRefresh">↻</button>
      </div>
      <input type="text" id="loginCaptcha" maxlength="6" autocomplete="off" placeholder="Enter captcha" style="margin-top:10px">
    </div>
    <button class="login-btn" id="loginBtn" type="button">Sign in</button>
    <div class="login-error" id="loginError"></div>
  </div>
</div>

<div id="app">
<aside id="sidebar">
  <div class="logo-box">
    <img src="assets/img/ATL%20logo.svg" alt="ATL" class="atl-logo-side">
    <div class="t">Hartsfield-Jackson<br>Atlanta International Airport®</div>
    <div class="s">Airport Management</div>
  </div>
  <nav class="side" id="sideNav">
    <button class="active" data-view="overview" type="button"><span class="ico"><i class="fa-solid fa-gauge-high"></i></span> overview</button>
    <button data-view="flights" type="button"><span class="ico"><i class="fa-solid fa-plane-departure"></i></span> flight ops</button>
    <button data-view="addflight" type="button"><span class="ico"><i class="fa-solid fa-plus"></i></span> add flight</button>
    <button data-view="gates" type="button"><span class="ico"><i class="fa-solid fa-door-open"></i></span> gates</button>
    <button data-view="airside" type="button"><span class="ico"><i class="fa-solid fa-road"></i></span> runway</button>
    <button data-view="global" type="button"><span class="ico"><i class="fa-solid fa-earth-americas"></i></span> global traffic</button>
    <button data-view="airspace" type="button"><span class="ico"><i class="fa-solid fa-satellite-dish"></i></span> local radar</button>
    <button data-view="terminal" type="button"><span class="ico"><i class="fa-solid fa-building"></i></span> terminal</button>
    <button data-view="baggage" type="button"><span class="ico"><i class="fa-solid fa-suitcase-rolling"></i></span> baggage tracking</button>
    <button data-view="staff" type="button"><span class="ico"><i class="fa-solid fa-users"></i></span> staff & gse</button>
    <button data-view="fuel" type="button"><span class="ico"><i class="fa-solid fa-gas-pump"></i></span> fuel & energy</button>
    <button data-view="transit" type="button"><span class="ico"><i class="fa-solid fa-train-subway"></i></span> transit</button>
    <button data-view="safety" type="button"><span class="ico"><i class="fa-solid fa-shield-halved"></i></span> safety & security</button>
    <button data-view="weather" type="button"><span class="ico"><i class="fa-solid fa-cloud-sun"></i></span> weather</button>
  </nav>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="main-wrap">
  <header class="top">
    <button class="menu-btn" id="menuBtn" type="button"><i class="fa-solid fa-bars"></i></button>
    <div class="hdr-logo"><img src="assets/img/ATL%20logo.svg" alt="ATL" class="atl-logo"> <span class="hdr-logo-text">Hartsfield-Jackson<br>Atlanta International Airport®</span></div>
    <div class="hdr-title" id="pageTitle">overview <span class="dot"></span></div>
    <div class="clock" id="clock">ATL --:--:--</div>
    <div style="position:relative">
      <button class="account-btn" id="accountBtn" type="button"><i class="fa-solid fa-user"></i> <span id="accountName">Account</span> ▾</button>
      <div class="account-menu" id="accountMenu">
        <div style="padding:8px 12px;font-size:12px;color:var(--muted);border-bottom:1px solid var(--border);margin-bottom:4px" id="accountInfo">Signed in</div>
        <button type="button" id="manageUsersBtn">Manage Users</button>
        <button type="button" id="logoutBtn">Sign out</button>
      </div>
    </div>
  </header>
  <main>

    <section class="panel-view active" id="view-overview">
      <div class="grid-2" style="margin-bottom:16px">
        <div class="card">
          <div class="card-title">Airport status</div>
          <div class="grid-kpi">
            <div class="kpi"><div class="lab">Ops plan</div><div class="val" style="color:var(--blue)">2,100</div></div>
            <div class="kpi"><div class="lab">OTP</div><div class="val" style="color:var(--green)" id="ovOtp">—</div></div>
            <div class="kpi"><div class="lab">Alerts</div><div class="val" style="color:var(--red)" id="ovAlerts">—</div></div>
          </div>
        </div>
        <div class="weather-card">
          <div class="icon">🌤</div>
          <div class="temp" id="ovTemp">—</div>
          <div class="date" id="ovDay">—</div>
        </div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Flight summary (today)</div>
        <div class="grid-kpi">
          <div class="kpi"><div class="lab">Flights today</div><div class="val" id="ovFlights">—</div></div>
          <div class="kpi"><div class="lab">Take offs</div><div class="val" style="color:var(--cyan)" id="ovDep">—</div></div>
          <div class="kpi"><div class="lab">Landings</div><div class="val" style="color:var(--blue)" id="ovArr">—</div></div>
        </div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">⚠ Delayed (today)</div>
        <div class="soft-head" style="grid-template-columns:90px 1fr 1fr 70px 70px 1fr">
          <span>Flight</span><span>Aircraft</span><span>Route</span><span>Pax</span><span>Delay</span><span>Reason</span>
        </div>
        <div class="soft-table" id="delayedList"></div>
      </div>
      <div class="card">
        <div class="card-title">✕ Cancelled</div>
        <div class="soft-head" style="grid-template-columns:90px 1fr 1fr 70px 80px 1fr 1fr">
          <span>Flight</span><span>Aircraft</span><span>Dest</span><span>Pax</span><span>Time</span><span>Reason</span><span>Replacement</span>
        </div>
        <div class="soft-table" id="cancelledList"></div>
      </div>
    </section>

    <section class="panel-view" id="view-flights">
      <div class="sec-title"><span>flight ops · today</span></div>
      <div class="filter-bar" style="flex-wrap:wrap;gap:10px;align-items:center">
        <span class="label">Filter:</span>
        <button class="filter-btn active" data-ffilter="all" type="button">All</button>
        <button class="filter-btn" data-ffilter="dep" type="button">Take offs</button>
        <button class="filter-btn" data-ffilter="arr" type="button">Landing</button>
        <button class="filter-btn" data-ffilter="delayed" type="button">Delayed</button>
        <button class="filter-btn" data-ffilter="cancelled" type="button">Cancelled</button>
        <button class="filter-btn" data-ffilter="manual" type="button">Manually added</button>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;min-width:220px">
          <input type="search" id="flightSearchInput" placeholder="Search flight number…" autocomplete="off"
            style="flex:1;min-width:160px;padding:8px 12px;border-radius:10px;border:1px solid var(--border);background:var(--bg2);color:var(--text);font:500 13px var(--font)">
          <button class="btn sm" type="button" id="flightSearchBtn">Search</button>
          <button class="btn sm" type="button" id="flightSearchClear">Clear</button>
        </div>
      </div>
      <div class="card" style="margin-bottom:20px">
        <div class="soft-head" style="grid-template-columns:90px 70px 70px 70px 60px 60px 1fr">
          <span>Flight</span><span>Origin</span><span>Dest</span><span>Aircraft</span><span>Gate</span><span>Time</span><span>Status</span>
        </div>
        <div class="soft-table" id="flightBoard"></div>
        <div id="flightPager" class="pager-bar" style="display:none;margin-top:12px;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn sm" type="button" id="flightPrevPage">← Previous</button>
          <span id="flightPageLabel" style="font-size:12px;color:var(--muted)"></span>
          <button class="btn sm primary" type="button" id="flightNextPage">Next page →</button>
        </div>
      </div>
      <div class="sec-title"><span>flights for next day</span><span class="pill cyan" id="nextDayLabel">Tomorrow</span></div>
      <div class="card">
        <div class="soft-head" style="grid-template-columns:90px 70px 70px 70px 60px 60px 1fr">
          <span>Flight</span><span>Origin</span><span>Dest</span><span>Aircraft</span><span>Gate</span><span>Time</span><span>Status</span>
        </div>
        <div class="soft-table" id="nextDayBoard"></div>
        <div id="flightPagerT" class="pager-bar" style="display:none;margin-top:12px;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn sm" type="button" id="flightPrevPageT">← Previous</button>
          <span id="flightPageLabelT" style="font-size:12px;color:var(--muted)"></span>
          <button class="btn sm primary" type="button" id="flightNextPageT">Next page →</button>
        </div>
      </div>
    </section>


    <section class="panel-view" id="view-addflight">
      <div class="sec-title">
        <span>Flights added manually</span>
        <button class="btn primary" id="btnOpenAddFlight" type="button">＋ Add Flight</button>
      </div>
      <div class="card">
        <div class="soft-head" style="grid-template-columns:90px 70px 70px 80px 60px 60px 1fr 70px">
          <span>Flight</span><span>Origin</span><span>Dest</span><span>Aircraft</span><span>Gate</span><span>Time</span><span>Status</span><span>Action</span>
        </div>
        <div class="soft-table" id="manualFlightBoard"></div>
      </div>
    </section>

    <section class="panel-view" id="view-gates">
      <div class="sec-title">
        <span>Gates, stands & turnaround</span>
        <button class="btn sm" id="gateSettingsBtn" type="button">⚙ Terminal settings</button>
      </div>
      <div id="gateSettingsPanel" class="settings-box" style="display:none">
        <div style="font-size:13px;color:var(--text2);margin-bottom:10px">Assign each concourse: Domestic or International (+ continent)</div>
        <div id="gateSettingsRows"></div>
        <button class="btn primary sm" id="saveGateSettings" type="button" style="margin-top:12px">Save settings</button>
      </div>
      <div class="filter-bar">
        <span class="label">Filter:</span>
        <button class="filter-btn active" data-gfilter="all" type="button">All</button>
        <button class="filter-btn" data-gfilter="domestic" type="button">Domestic</button>
        <button class="filter-btn" data-gfilter="international" type="button">International</button>
      </div>
      <div id="gatesContainer"></div>
    </section>

    <section class="panel-view" id="view-airside">
      <div class="sec-title">
        <span>Runway + airside</span>
        <button class="btn sm" id="rwySettingsBtn" type="button">⚙ Runway settings</button>
      </div>
      <div id="rwySettingsPanel" class="settings-box" style="display:none">
        <div style="font-size:13px;color:var(--text2);margin-bottom:10px">Set each runway role</div>
        <div id="rwySettingsRows"></div>
        <button class="btn primary sm" id="saveRwySettings" type="button" style="margin-top:12px">Save settings</button>
      </div>
      <div class="card" id="rwyLive"></div>
    </section>

        <section class="panel-view" id="view-global">
      <div class="sec-title" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span>Global traffic · Live (FR24)</span>
        <button class="btn sm" type="button" id="btnRefreshGlobal">↻ Refresh</button>
      </div>
      <div class="radar-layout">
        <div class="card radar-map-card"><div id="globalMap" class="radar-map"></div></div>
        <div class="card radar-list-card">
          <div class="card-title">Aircraft in view <span id="globalCount" class="pill">0</span></div>
          <div class="radar-table-wrap"><table class="user-table"><thead><tr><th>Callsign</th><th>Alt</th><th>Spd</th><th>Hdg</th></tr></thead><tbody id="globalFlightBody"></tbody></table></div>
          <div id="globalMeta" style="font-size:11px;color:var(--muted);margin-top:8px"></div>
        </div>
      </div>
    </section>

    <section class="panel-view" id="view-airspace">
      <div class="sec-title" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span>Local radar · KATL / ATL</span>
        <button class="btn sm" type="button" id="btnRefreshLocal">↻ Refresh</button>
      </div>
      <div class="radar-layout">
        <div class="card radar-map-card"><div id="localMap" class="radar-map"></div></div>
        <div class="card radar-list-card">
          <div class="card-title">Near Atlanta <span id="localCount" class="pill">0</span></div>
          <div class="radar-table-wrap"><table class="user-table"><thead><tr><th>Callsign</th><th>Alt</th><th>Spd</th><th>Hdg</th></tr></thead><tbody id="localFlightBody"></tbody></table></div>
          <div id="localMeta" style="font-size:11px;color:var(--muted);margin-top:8px"></div>
        </div>
      </div>
    </section>

    <section class="panel-view" id="view-terminal">
      <div class="sec-title">terminal flow</div>
      <div class="grid-3" id="terminalCards"></div>
    </section>

    <section class="panel-view" id="view-baggage">
      <div class="sec-title">Baggage tracking</div>
      <div class="search-bar">
        <input type="text" id="bagSearch" placeholder="Search Bag ID or Flight number…">
        <button class="btn primary" id="bagSearchBtn" type="button">Search</button>
        <button class="btn" id="bagSearchClear" type="button">Clear</button>
      </div>
      <div class="grid-2" style="margin-bottom:16px;gap:16px;display:grid;grid-template-columns:1fr 1fr">
        <div class="card" style="cursor:pointer;border-left:3px solid var(--amber)" id="cardLostBags">
          <div class="card-title">Lost bags</div>
          <div id="lostBagSummary" style="font-size:13px;color:var(--text2)">—</div>
        </div>
        <div class="card" style="cursor:pointer;border-left:3px solid var(--red)" id="cardDamagedBags">
          <div class="card-title">Damaged bags</div>
          <div id="damagedBagSummary" style="font-size:13px;color:var(--text2)">—</div>
        </div>
      </div>
      <div class="card" id="lostBagsPanel" style="display:none;margin-bottom:16px">
        <div class="card-title">
          <span>Lost bags · detail</span>
          <div style="display:flex;gap:8px">
            <button class="btn primary sm" type="button" id="btnAddLostBag">＋ Add lost bag</button>
            <button class="btn sm" type="button" id="closeLostPanel">Close</button>
          </div>
        </div>
        <div id="lostBagsList"></div>
      </div>
      <div class="card" id="damagedBagsPanel" style="display:none;margin-bottom:16px">
        <div class="card-title">
          <span>Damaged bags · detail</span>
          <div style="display:flex;gap:8px">
            <button class="btn primary sm" type="button" id="btnAddDamagedBag">＋ Add damaged bag</button>
            <button class="btn sm" type="button" id="closeDamagedPanel">Close</button>
          </div>
        </div>
        <div id="damagedBagsList"></div>
      </div>
      <div class="card">
        <div class="card-title">Bags by flight</div>
        <div id="bagsByFlight"></div>
      </div>
    </section>

    <section class="panel-view" id="view-staff">
      <div class="sec-title">Staff & GSE</div>
      <div class="grid-kpi" style="margin-bottom:16px">
        <div class="kpi"><div class="lab">Total roster</div><div class="val" style="color:var(--blue)" id="stTotal">—</div></div>
        <div class="kpi"><div class="lab">On duty</div><div class="val" style="color:var(--green)" id="stDuty">—</div></div>
        <div class="kpi"><div class="lab">On break</div><div class="val" style="color:var(--amber)" id="stBreak">—</div></div>
        <div class="kpi"><div class="lab">Off shift</div><div class="val" id="stOff">—</div></div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">
          <span>System users</span>
          <button class="btn primary sm" id="addUserFromStaff" type="button">+ Add User</button>
        </div>
        <div id="staffUsersTable"></div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">
          <span>Staff by department</span>
          <select id="staffDeptFilter" style="max-width:220px">
            <option value="all">All departments</option>
          </select>
        </div>
        <div class="filter-bar" style="margin-bottom:10px">
          <button class="filter-btn active" data-sfilter="all" type="button">All</button>
          <button class="filter-btn" data-sfilter="on_duty" type="button">On duty</button>
          <button class="filter-btn" data-sfilter="break" type="button">Break</button>
          <button class="filter-btn" data-sfilter="off" type="button">Off</button>
        </div>
        <div class="staff-search-wrap" style="position:relative;margin-bottom:12px">
          <input type="search" id="staffSearchInput" placeholder="Search name, family or employee code…" autocomplete="off"
            style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--border);background:#12151f;color:var(--text);font-size:13px">
          <div id="staffSuggestBox" class="suggest-box" style="display:none"></div>
        </div>
        <div id="staffShowingNote" style="font-size:12px;color:var(--muted);margin-bottom:8px"></div>
        <div style="max-height:480px;overflow:auto">
          <table class="user-table">
            <thead><tr><th>#</th><th>Code</th><th>Name</th><th>Role</th><th>Dept</th><th>Shift</th><th>Zone</th><th>Status</th></tr></thead>
            <tbody id="staffRosterBody"></tbody>
          </table>
        </div>
        <div id="staffPager" class="pager-bar" style="display:none;margin-top:12px;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
          <button class="btn sm" type="button" id="staffPrevPage">← Previous</button>
          <span id="staffPageLabel" style="font-size:12px;color:var(--muted)"></span>
          <button class="btn sm primary" type="button" id="staffNextPage">Next page →</button>
        </div>
      </div>
    </section>

    <section class="panel-view" id="view-fuel">
      <div class="sec-title">Fuel & energy</div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Electricity 2026 (Jan–Aug actual)</div>
        <div style="display:flex;align-items:flex-end;gap:6px;height:120px" id="powerChart"></div>
        <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--muted);margin-top:6px">
          <span>Jan</span><span>Feb</span><span>Mar</span><span>Apr</span><span>May</span><span>Jun</span>
          <span>Jul</span><span>Aug</span><span>Sep</span><span>Oct</span><span>Nov</span><span>Dec</span>
        </div>
      </div>
      <div class="card">
        <div class="card-title">
          <span>Fuel farm</span>
          <button class="btn sm" type="button" id="fuelSettingsBtn">⚙ Settings</button>
        </div>
        <div id="fuelSettingsPanel" class="settings-box" style="display:none;margin-bottom:12px">
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn primary sm" type="button" id="btnAddTank">＋ Add tank</button>
            <button class="btn sm" type="button" id="btnManageTanks">Manage tanks</button>
            <button class="btn sm" type="button" id="btnFillTanks">⛽ Pipeline fill</button>
          </div>
        </div>
        <div id="tankAlert" style="display:none;background:rgba(242,92,110,.12);border:1px solid rgba(242,92,110,.35);border-radius:10px;padding:10px 14px;font-size:13px;color:var(--red);margin-bottom:12px"></div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:12px" id="tankRow"></div>
      </div>
    </section>

    <section class="panel-view" id="view-transit">
      <div class="sec-title"><span>Transit & ground transportation · ATL</span></div>
      <div class="filter-bar" id="transitTabs" style="margin-bottom:14px">
        <button class="filter-btn active" data-ttab="overview" type="button">Overview</button>
        <button class="filter-btn" data-ttab="taxi" type="button">Taxi</button>
        <button class="filter-btn" data-ttab="metro" type="button">Metro</button>
      </div>
      <div id="transitPanel"></div>
    </section>

    <section class="panel-view" id="view-safety">
      <div class="sec-title">Safety & security</div>
      <div class="card" style="margin-bottom:16px;border-left:3px solid var(--green)">
        <div class="card-title">Security status report</div>
        <div id="securityReport" style="font-size:14px;line-height:1.7;color:var(--text2)">—</div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">
          <span>Emergency protocols</span>
          <div style="display:flex;gap:8px">
            <button class="btn danger sm" id="evacBtn" type="button">Activate Evacuation</button>
            <button class="btn sm" id="clearCriticalBtn" type="button">Clear critical</button>
          </div>
        </div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Active alerts</div>
        <div id="alertsList"></div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Citizen / tip reports</div>
        <div id="citizenReports"></div>
      </div>
      <div class="card" style="margin-bottom:16px">
        <div class="card-title"><span>CCTV</span><button class="btn sm" type="button" id="camSettingsBtn">⚙ Settings</button></div>
        <div id="camSettingsPanel" class="settings-box" style="display:none;margin-bottom:12px">
          <button class="btn primary sm" type="button" id="btnAddCamera">＋ Add camera</button>
        </div>
        <div class="cam-grid" id="camGrid"></div>
      </div>
      <div class="card" style="border-left:3px solid var(--cyan)">
        <div class="card-title">🔥 Fire / ARFF resources</div>
        <div class="grid-kpi" id="arffRow"></div>
      </div>
    </section>

    <section class="panel-view" id="view-weather">
      <div class="sec-title" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <span>Weather · KATL Atlanta</span>
        <button class="btn sm" type="button" id="btnRefreshWeather">↻ Refresh</button>
      </div>

      <div class="wx-hero card" id="wxHero">
        <div class="wx-hero-main">
          <div class="wx-hero-icon" id="wxHeroIcon">🌤</div>
          <div>
            <div class="wx-hero-temp" id="wxHeroTemp">—°</div>
            <div class="wx-hero-meta" id="wxHeroMeta">Loading Open-Meteo…</div>
          </div>
        </div>
        <div class="wx-hero-grid" id="wxHeroStats"></div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Daily forecast</div>
        <div class="wx-daily" id="weatherDays"></div>
      </div>

      <div class="card" style="margin-bottom:16px">
        <div class="card-title">Hourly · <span id="hourlyDayLabel">Next hours</span></div>
        <div class="hourly-row" id="hourlyWx"></div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-title">Operational impact</div>
          <div id="wxImpact" class="wx-impact">—</div>
        </div>
        <div class="card">
          <div class="card-title">Visibility & wind (next hours)</div>
          <div id="wxVisWind" style="font-size:13px;color:var(--text2);line-height:1.7">—</div>
        </div>
      </div>
    </section>

  </main>
</div>
</div>

<div class="modal-overlay" id="userModal">
  <div class="modal">
    <div class="modal-header">
      <h2 id="userModalTitle">Manage Users</h2>
      <button class="modal-close" id="userModalClose" type="button">&times;</button>
    </div>
    <div class="modal-body">
      <div id="userListView">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
          <span style="font-size:13px;color:var(--text2)">System users</span>
          <button class="btn primary sm" id="showAddUser" type="button">+ Add User</button>
        </div>
        <div id="usersTableWrap"></div>
      </div>
      <div id="userFormView" style="display:none">
        <div class="form-row">
          <div class="form-group"><label>Username</label><input type="text" id="fUsername"></div>
          <div class="form-group"><label>Password</label><input type="text" id="fPassword" placeholder="Leave blank to keep"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>Full name</label><input type="text" id="fName"></div>
          <div class="form-group"><label>Role</label>
            <select id="fRole">
              <option value="controller">Controller</option>
              <option value="supervisor">Supervisor</option>
              <option value="gate_agent">Gate Agent</option>
              <option value="ramp_agent">Ramp Agent</option>
              <option value="security">Security</option>
              <option value="inspector">Inspector</option>
              <option value="viewer">Viewer</option>
            </select>
          </div>
        </div>
        <div class="form-group"><label>Section access</label><div class="section-checks" id="sectionChecks"></div></div>
        <input type="hidden" id="fEditId">
      </div>
    </div>
    <div class="modal-footer" id="userFormFooter" style="display:none">
      <button class="btn" id="cancelUserForm" type="button">Cancel</button>
      <button class="btn primary" id="saveUserBtn" type="button">Save User</button>
    </div>
  </div>
</div>

<div class="drawer-overlay" id="drawerOverlay"></div>
<div class="drawer" id="flightDrawer">
  <div class="drawer-header">
    <strong id="drawerTitle">Flight</strong>
    <button class="modal-close" id="drawerClose" type="button">&times;</button>
  </div>
  <div class="drawer-body" id="drawerBody"></div>
</div>


<!-- Fuel: Add tank -->
<div class="modal-overlay" id="addTankModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><h2>Add fuel tank</h2><button class="modal-close" id="addTankClose" type="button">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Tank name</label><input type="text" id="tkName" placeholder="Jet A East 1"></div>
      <div class="form-row">
        <div class="form-group"><label>Fuel type</label>
          <select id="tkType"><option value="jet_a">Jet A</option><option value="saf">SAF</option></select>
        </div>
        <div class="form-group"><label>Capacity (liters)</label><input type="number" id="tkCapL" min="1000" step="1000" placeholder="e.g. 2800000"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Initial level %</label><input type="number" id="tkLevel" min="0" max="100" value="80"></div>
        <div class="form-group"><label>Low threshold %</label><input type="number" id="tkThr" min="5" max="50" value="20"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="addTankCancel">Cancel</button>
      <button class="btn primary" type="button" id="addTankSave">Save</button>
    </div>
  </div>
</div>

<!-- Fuel: Manage tanks -->
<div class="modal-overlay" id="manageTanksModal">
  <div class="modal" style="max-width:640px">
    <div class="modal-header"><h2>Manage fuel tanks</h2><button class="modal-close" id="manageTanksClose" type="button">&times;</button></div>
    <div class="modal-body" id="manageTanksBody"></div>
    <div class="modal-footer">
      <button class="btn" type="button" id="manageTanksCancel">Cancel</button>
      <button class="btn primary" type="button" id="manageTanksSave">Save</button>
    </div>
  </div>
</div>

<!-- Add camera -->
<div class="modal-overlay" id="addCameraModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-header"><h2>Add security camera</h2><button class="modal-close" id="addCameraClose" type="button">&times;</button></div>
    <div class="modal-body">
      <div class="form-group"><label>Camera ID</label><input type="text" id="camCode" placeholder="CAM-A-15" maxlength="20"></div>
      <div class="form-group"><label>Zone / location</label><input type="text" id="camZone" placeholder="Concourse A"></div>
      <div class="form-group"><label>Snapshot URL (optional)</label><input type="text" id="camSnap" placeholder="https://..."></div>
      <div class="form-group"><label>Status</label>
        <select id="camStatus"><option value="online">Online</option><option value="offline">Offline</option><option value="fault">Fault</option></select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="addCameraCancel">Cancel</button>
      <button class="btn primary" type="button" id="addCameraSave">Save</button>
    </div>
  </div>
</div>


<!-- Add bag (lost / damaged) -->
<div class="modal-overlay" id="addBagModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><h2 id="addBagTitle">Add bag</h2><button class="modal-close" id="addBagClose" type="button">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="abMode" value="lost">
      <div class="form-row">
        <div class="form-group"><label>Bag ID</label><input type="text" id="abId" placeholder="ATL00099999" maxlength="20"></div>
        <div class="form-group"><label>Flight number</label><input type="text" id="abFlight" placeholder="DL1042" maxlength="12"></div>
      </div>
      <div class="form-group"><label>Owner name</label><input type="text" id="abOwner" placeholder="Full name"></div>
      <div class="form-row">
        <div class="form-group"><label>Origin</label><input type="text" id="abOrigin" placeholder="JFK" maxlength="3"></div>
        <div class="form-group"><label>Destination</label><input type="text" id="abDest" placeholder="ATL" maxlength="3"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Weight (kg)</label><input type="number" id="abWeight" min="1" step="0.1" value="18"></div>
        <div class="form-group"><label>Status</label>
          <select id="abStatus">
            <option value="missing">Lost / Missing</option>
            <option value="found">Found</option>
            <option value="damaged">Damaged</option>
          </select>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="addBagCancel">Cancel</button>
      <button class="btn primary" type="button" id="addBagSave">Save</button>
    </div>
  </div>
</div>


<!-- Fill fuel tanks -->
<div class="modal-overlay" id="fillTanksModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header"><h2>Pipeline fuel fill</h2><button class="modal-close" id="fillTanksClose" type="button">&times;</button></div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--muted);margin:0 0 12px">Select tank(s) and enter liters to add via the airport fuel pipeline. Default tank capacity ≈ 19,000,000 L.</p>
      <div id="fillTanksBody"></div>
      <div id="fillTanksSummary" style="margin-top:12px;font-size:13px;color:var(--cyan)"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="fillTanksCancel">Cancel</button>
      <button class="btn primary" type="button" id="fillTanksSave">Fill selected</button>
    </div>
  </div>
</div>


<!-- Ground vehicle add/edit -->
<div class="modal-overlay" id="vehicleModal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header"><h2 id="vehModalTitle">Add vehicle</h2><button class="modal-close" id="vehModalClose" type="button">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="vehId"><input type="hidden" id="vehType"><input type="hidden" id="vehMfr"><input type="hidden" id="vehModelOnly">
      <div class="form-row">
        <div class="form-group"><label>Service class</label>
          <select id="vehClass">
            <option value="economy">Standard authorized taxi</option>
            <option value="minivan">Accessible / Minivan</option>
            <option value="standard">Standard</option>
          </select>
        </div>
        <div class="form-group"><label>Status</label>
          <select id="vehStatus">
            <option value="available">Available</option>
            <option value="on_trip">On trip</option>
            <option value="offline">Offline</option>
            <option value="maintenance">Maintenance</option>
          </select>
        </div>
      </div>
      <div class="form-group" style="position:relative">
        <label>Model (manufacturer + model)</label>
        <input type="text" id="vehModel" placeholder="Start typing e.g. Toyota or Sprinter" autocomplete="off">
        <div id="vehAcResults" class="ac-dropdown" style="position:relative;display:block;max-height:160px"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Capacity (pax)</label><input type="number" id="vehCap" min="1" max="80" value="4"></div>
        <div class="form-group"><label>Driver name</label><input type="text" id="vehDriver"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Vehicle code</label><input type="text" id="vehCode" maxlength="30"></div>
        <div class="form-group"><label>Plate number</label><input type="text" id="vehPlate" maxlength="20"></div>
      </div>
      <div class="form-group"><label>Company</label><input type="text" id="vehCompany"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="vehModalCancel">Cancel</button>
      <button class="btn primary" type="button" id="vehModalSave">Save</button>
    </div>
  </div>
</div>

<!-- Parking vehicle add/edit -->
<div class="modal-overlay" id="parkingModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-header"><h2 id="parkModalTitle">Add parking vehicle</h2><button class="modal-close" id="parkModalClose" type="button">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="parkId"><input type="hidden" id="parkLotId">
      <div class="form-row">
        <div class="form-group"><label>Plate number</label><input type="text" id="parkPlate" maxlength="20"></div>
        <div class="form-group"><label>Owner name</label><input type="text" id="parkOwner"></div>
      </div>
      <div class="form-group"><label>Model</label><input type="text" id="parkModel" placeholder="e.g. Toyota Camry"></div>
      <div class="form-row">
        <div class="form-group"><label>Status</label>
          <select id="parkStatus">
            <option value="parked">Parked</option>
            <option value="entering">Entering</option>
            <option value="exiting">Exiting</option>
          </select>
        </div>
        <div class="form-group"><label>Hours</label><input type="number" id="parkHours" min="0" step="0.5" placeholder="e.g. 4"></div>
      </div>
      <div class="form-group"><label>Days (optional)</label><input type="number" id="parkDays" min="0" step="0.5" placeholder="e.g. 2"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" type="button" id="parkModalCancel">Cancel</button>
      <button class="btn primary" type="button" id="parkModalSave">Save</button>
    </div>
  </div>
</div>

<script src="assets/js/app.js"></script>

<!-- Add Flight Modal -->
<div class="modal-overlay" id="addFlightModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h2>Add Flight</h2>
      <button class="modal-close" id="addFlightClose" type="button">&times;</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group"><label>Flight number</label><input type="text" id="afNum" placeholder="DL123" maxlength="10"></div>
        <div class="form-group">
          <label>Type</label>
          <div style="display:flex;gap:8px;margin-top:4px">
            <button type="button" class="btn sm af-type active" data-type="dep">TAKE OFF</button>
            <button type="button" class="btn sm af-type" data-type="arr">LANDING</button>
          </div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" style="position:relative">
          <label>Origin</label>
          <input type="text" id="afOrigin" value="ATL" autocomplete="off">
          <div class="ac-dropdown" id="afOriginAC"></div>
        </div>
        <div class="form-group" style="position:relative">
          <label>Destination</label>
          <input type="text" id="afDest" placeholder="FRA or Frankfurt" autocomplete="off">
          <div class="ac-dropdown" id="afDestAC"></div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Gate (filtered by continent)</label>
          <select id="afGate"><option value="">— select free gate —</option></select>
        </div>
        <div class="form-group">
          <label>Time</label>
          <input type="datetime-local" id="afTime">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Aircraft model</label>
          <select id="afAircraft"><option value="">— select —</option></select>
        </div>
        <div class="form-group">
          <label>International?</label>
          <select id="afIntl"><option value="0">Domestic</option><option value="1">International</option></select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Pilot</label><input type="text" id="afPilot" placeholder="Capt. Name"></div>
        <div class="form-group"><label>Co-pilot</label><input type="text" id="afCopilot" placeholder="F/O Name"></div>
      </div>
      <div class="form-group"><label>Cabin crew count</label><input type="number" id="afCrew" value="4" min="1" max="20"></div>
    </div>
    <div class="modal-footer">
      <button class="btn" id="addFlightCancel" type="button">Cancel</button>
      <button class="btn primary" id="afSubmit" type="button">Add Flight</button>
    </div>
  </div>
</div>

<!-- Flight Settings (inside drawer enhancement is JS-driven) -->

</body>
</html>
