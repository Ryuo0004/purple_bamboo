<?php
require_once __DIR__ . '/../database.php';

$resort_lat = defined('RESORT_LAT') ? RESORT_LAT : 16.173389;
$resort_lng = defined('RESORT_LNG') ? RESORT_LNG : 120.057846;
$resort_address = defined('RESORT_ADDRESS') ? RESORT_ADDRESS : "Zone 04 magamo-gamo, Alaminos, Philippines, 2404";
$resort_phone = defined('RESORT_PHONE') ? RESORT_PHONE : "0956 697 8679";
$gm_key = defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '';
?>
<div class="ride-guide-container rg-wrap">

    <!-- Header -->
    <div class="rg-head">
        <div style="flex: 1;">
            <div class="rg-kicker"><i class="fa-solid fa-compass"></i> Guest Travel &amp; Arrival Concierge</div>
            <h2 class="rg-title"><i class="fa-solid fa-map-location-dot"></i> Ride &amp; Arrival Guide</h2>
            <p class="rg-sub">
                Pinned at <strong><?= htmlspecialchars($resort_address) ?></strong>. 
                Type your location below to calculate distance, travel time, and preview the route in 2D or 3D.
            </p>
        </div>
        <div class="rg-badge-coords">
            <i class="fa-solid fa-thumbtack" style="color: #e0129c;"></i>
            <span><?= number_format($resort_lat, 6) ?>, <?= number_format($resort_lng, 6) ?></span>
        </div>
    </div>

    <!-- Search Input & Origin Controls -->
    <div class="rg-search">
        <div class="rg-input-wrap">
            <i class="fa-solid fa-location-dot"></i>
            <input type="text" id="rgOriginInput" placeholder="Enter your starting location (e.g. Cubao, Quezon City / Clark / Baguio)" autocomplete="off"
                   onkeydown="if(event.key==='Enter'){event.preventDefault();rgCalculate();}">
        </div>
        <div class="rg-search-btns">
            <button type="button" class="rg-btn rg-btn-ghost" id="rgGpsBtn" onclick="rgUseGps()" title="Use browser GPS">
                <i class="fa-solid fa-location-crosshairs"></i> My Location
            </button>
            <button type="button" class="rg-btn rg-btn-primary" onclick="rgCalculate()">
                <i class="fa-solid fa-clock"></i> Estimated Time
            </button>
        </div>
    </div>

    <!-- Travel Stats Banner (Appears after route calculated) -->
    <div class="rg-stats" id="rgStats" style="display:none;">
        <div class="rg-stat"><span><i class="fa-solid fa-road"></i> Distance</span><strong id="rgDist">—</strong></div>
        <div class="rg-stat"><span><i class="fa-solid fa-car-side"></i> Estimated Driving Time</span><strong id="rgTime">—</strong></div>
        <div class="rg-stat"><span><i class="fa-solid fa-bus"></i> Bus / Jeep + Tricycle</span><strong id="rgBus">—</strong></div>
    </div>
    <div id="rgMsg" class="rg-msg">
        <i class="fa-solid fa-circle-info"></i> Pinned at <strong><?= htmlspecialchars($resort_address) ?></strong>. Enter your starting location to calculate road distance and driving duration.
    </div>

    <!-- Map View Toolbar (Roadmap, 3D Satellite, 3D Tilt, Google Earth 3D) -->
    <div class="rg-map-toolbar">
        <div class="rg-view-buttons">
            <button type="button" class="rg-view-btn active" id="btnViewRoadmap" onclick="rgSetMapView('roadmap')">
                <i class="fa-solid fa-map"></i> Roadmap
            </button>
            <button type="button" class="rg-view-btn" id="btnViewSatellite" onclick="rgSetMapView('satellite')">
                <i class="fa-solid fa-satellite"></i> 3D Satellite / Earth
            </button>
            <button type="button" class="rg-view-btn" id="btnViewTilt" onclick="rgToggleTilt()" title="Tilt camera to 45° angle">
                <i class="fa-solid fa-cube"></i> Tilt 3D (45°)
            </button>
            <button type="button" class="rg-view-btn" onclick="rgRecenter()" title="Recenter on Purple Bamboo Resort">
                <i class="fa-solid fa-crosshairs"></i> Center Resort
            </button>
        </div>

        <div class="rg-3d-external-links">
            <a target="_blank" class="rg-pill-link" title="Open Google Earth in full 3D globe flyover"
               href="https://earth.google.com/web/@<?= $resort_lat ?>,<?= $resort_lng ?>,15a,900d,35y,0h,60t,0r">
                <i class="fa-solid fa-earth-asia" style="color: #38bdf8;"></i> Open Google Earth 3D
            </a>
            <a target="_blank" class="rg-pill-link" title="Open Google Maps 3D satellite photogrammetry"
               href="https://www.google.com/maps/@<?= $resort_lat ?>,<?= $resort_lng ?>,18a,35y,39.38t/data=!3m1!1e3">
                <i class="fa-solid fa-vr-cardboard" style="color: #a855f7;"></i> Maps 3D View
            </a>
        </div>
    </div>

    <!-- Interactive Map Box -->
    <div class="rg-map-box">
        <div id="rgMap" class="rg-map">
            <iframe id="rgMapFrame" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                    src="https://maps.google.com/maps?q=<?= $resort_lat ?>,<?= $resort_lng ?>(Purple+Bamboo+Resort)&z=16&t=m&output=embed"></iframe>
        </div>
    </div>


    <!-- Help & Driver Hotline -->
    <div class="rg-help">
        <div>
            <strong style="color: #fff; font-size: 0.98rem; display: block;">Lost or need phone directions while driving?</strong>
            <span style="font-size: 0.84rem; color: #e9d5ff;">Our front desk team is ready to guide you or talk to your driver.</span>
        </div>
        <a href="tel:<?= preg_replace('/[^0-9]/', '', $resort_phone) ?>" class="rg-btn rg-btn-gold">
            <i class="fa-solid fa-phone"></i> Call <?= htmlspecialchars($resort_phone) ?>
        </a>
    </div>
</div>

<style>
.rg-wrap{background:#fff;border:1.5px solid #e2e8f0;border-radius:16px;padding:2rem;box-shadow:0 4px 20px rgba(0,0,0,.04);margin-top:2rem;font-family:'Outfit',sans-serif}
.rg-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;border-bottom:1.5px solid #f1f5f9;padding-bottom:1rem}
.rg-kicker{display:inline-flex;gap:6px;align-items:center;background:rgba(107,33,168,.08);color:#6b21a8;padding:4px 12px;border-radius:20px;font-size:.75rem;font-weight:800;text-transform:uppercase;margin-bottom:.5rem}
.rg-title{font-size:1.5rem;color:#1e1b4b;margin:0 0 .3rem;display:flex;gap:8px;align-items:center}
.rg-title i{color:#e0129c}
.rg-sub{color:#64748b;font-size:.92rem;margin:0;line-height:1.5}
.rg-badge-coords{background:#fdf2f8;border:1px solid #fbcfe8;border-radius:999px;padding:6px 14px;font-size:.78rem;font-weight:800;color:#9d174d;display:inline-flex;align-items:center;gap:6px;align-self:flex-start}
.rg-search{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
.rg-input-wrap{flex:1;min-width:240px;position:relative}
.rg-input-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#e0129c}
.rg-input-wrap input{width:100%;box-sizing:border-box;padding:.75rem 1rem .75rem 2.4rem;border:1.5px solid #d8b4fe;border-radius:10px;font-size:.95rem;font-family:inherit;outline:none;transition:.2s}
.rg-input-wrap input:focus{border-color:#6b21a8;box-shadow:0 0 0 3px rgba(107,33,168,.12)}
.rg-search-btns{display:flex;gap:.5rem}
.rg-btn{display:inline-flex;align-items:center;gap:6px;padding:.7rem 1.1rem;border-radius:10px;font-weight:700;font-size:.88rem;border:none;cursor:pointer;text-decoration:none;transition:transform .15s,box-shadow .15s;font-family:inherit}
.rg-btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.1)}
.rg-btn-primary{background:linear-gradient(135deg,#6b21a8,#9333ea);color:#fff}
.rg-btn-ghost{background:#faf5ff;color:#6b21a8;border:1.5px solid #d8b4fe}
.rg-btn-google{background:#1a73e8;color:#fff}
.rg-btn-waze{background:#33ccff;color:#003366;font-weight:800}
.rg-btn-gold{background:#fbbf24;color:#1e1035;font-weight:800}
.rg-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem}
.rg-stat{background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:.85rem 1rem;animation:rgIn .35s ease}
.rg-stat span{display:block;font-size:.72rem;font-weight:800;text-transform:uppercase;color:#7e22ce;margin-bottom:.2rem}
.rg-stat strong{font-size:1.4rem;color:#1e1b4b}
.rg-msg{font-size:.86rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.65rem .95rem;margin-bottom:1rem}
.rg-map-toolbar{display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;background:#f8fafc;border:1.5px solid #e2e8f0;border-bottom:none;border-radius:14px 14px 0 0;padding:.6rem .85rem}
.rg-view-buttons{display:flex;gap:.35rem;flex-wrap:wrap}
.rg-view-btn{background:#fff;border:1px solid #cbd5e1;color:#334155;padding:.38rem .75rem;border-radius:8px;font-size:.78rem;font-weight:700;font-family:inherit;cursor:pointer;display:inline-flex;align-items:center;gap:5px;transition:.15s}
.rg-view-btn:hover{background:#f1f5f9;color:#0f172a;border-color:#94a3b8}
.rg-view-btn.active{background:#6b21a8;color:#fff;border-color:#6b21a8;box-shadow:0 2px 6px rgba(107,33,168,.25)}
.rg-3d-external-links{display:flex;gap:.4rem;flex-wrap:wrap}
.rg-pill-link{background:#fff;border:1px solid #e2e8f0;padding:.35rem .75rem;border-radius:999px;font-size:.76rem;font-weight:800;color:#334155;text-decoration:none;display:inline-flex;align-items:center;gap:5px;transition:.15s}
.rg-pill-link:hover{background:#f8fafc;border-color:#a855f7;color:#6b21a8}
.rg-map-box{border-radius:0 0 14px 14px;overflow:hidden;border:1.5px solid #e2e8f0;position:relative}
.rg-map{width:100%;height:430px}
.rg-map iframe{width:100%;height:100%;border:0}

.rg-tips{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-bottom:1.25rem}
.rg-tip{border:1.5px solid #e2e8f0;border-radius:12px;padding:1rem 1.25rem;background:#fff}
.rg-tip h4{margin:0 0 .5rem;color:#1e1b4b;display:flex;gap:8px;align-items:center}
.rg-tip h4 i{color:#6b21a8}
.rg-tip ol{margin:0;padding-left:1.2rem;font-size:.87rem;line-height:1.6;color:#334155}
.rg-help{background:linear-gradient(135deg,#1e1035,#3b0764);color:#e9d5ff;border-radius:12px;padding:1rem 1.25rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.75rem;margin-top:1.25rem}
.pac-container{z-index:100000 !important}
@keyframes rgIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}

/* Mobile Refinements (Screens <= 640px) */
@media (max-width: 640px) {
    .rg-wrap {
        padding: 1rem 0.85rem !important;
        border-radius: 14px !important;
        margin-top: 1.25rem !important;
    }
    .rg-head {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 0.65rem !important;
    }
    .rg-title {
        font-size: 1.25rem !important;
    }
    .rg-sub {
        font-size: 0.84rem !important;
    }
    .rg-badge-coords {
        width: 100% !important;
        box-sizing: border-box !important;
        justify-content: center !important;
        font-size: 0.72rem !important;
        padding: 5px 10px !important;
    }
    .rg-search {
        flex-direction: column !important;
        gap: 0.5rem !important;
    }
    .rg-input-wrap {
        width: 100% !important;
        min-width: 0 !important;
    }
    .rg-input-wrap input {
        font-size: 0.85rem !important;
        padding: 0.75rem 0.75rem 0.75rem 2.2rem !important;
    }
    .rg-search-btns {
        display: flex !important;
        width: 100% !important;
        gap: 0.5rem !important;
    }
    .rg-search-btns .rg-btn {
        flex: 1 !important;
        justify-content: center !important;
        min-height: 42px !important;
        font-size: 0.8rem !important;
        padding: 0.6rem 0.5rem !important;
        text-align: center !important;
    }
    .rg-stats {
        grid-template-columns: 1fr !important;
        gap: 0.5rem !important;
    }
    .rg-stat {
        padding: 0.65rem 0.85rem !important;
    }
    .rg-stat strong {
        font-size: 1.15rem !important;
    }
    .rg-map-toolbar {
        flex-direction: column !important;
        align-items: stretch !important;
        gap: 0.5rem !important;
        padding: 0.5rem !important;
    }
    .rg-view-buttons {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        width: 100% !important;
        gap: 0.35rem !important;
    }
    .rg-view-btn {
        justify-content: center !important;
        font-size: 0.72rem !important;
        padding: 0.45rem 0.4rem !important;
        min-height: 38px !important;
    }
    .rg-3d-external-links {
        display: grid !important;
        grid-template-columns: 1fr 1fr !important;
        width: 100% !important;
        gap: 0.4rem !important;
    }
    .rg-pill-link {
        justify-content: center !important;
        font-size: 0.72rem !important;
        padding: 0.4rem 0.4rem !important;
        min-height: 36px !important;
        text-align: center !important;
    }
    .rg-map {
        height: 280px !important;
    }
    .rg-help {
        flex-direction: column !important;
        align-items: stretch !important;
        text-align: center !important;
        gap: 0.75rem !important;
        padding: 0.85rem !important;
    }
    .rg-help a.rg-btn {
        width: 100% !important;
        justify-content: center !important;
        min-height: 44px !important;
    }
    .rg-tips {
        grid-template-columns: 1fr !important;
    }
}
</style>

<script>
(function(){
    const RESORT = { lat: <?= (float)$resort_lat ?>, lng: <?= (float)$resort_lng ?> };
    const RESORT_LABEL = <?= json_encode($resort_address) ?>;
    const GM_KEY = <?= json_encode($gm_key) ?>;
    let map, dirService, dirRenderer, autocomplete, resortMarker, infoWindow;
    let currentMapType = 'roadmap'; // 'roadmap' | 'satellite' | 'hybrid'
    let isTilted = false;
    let activeOriginQuery = null;

    function $(id){ return document.getElementById(id); }
    function fmtMins(m){ m = Math.round(m); const h = Math.floor(m/60), r = m%60; return (h ? h+' hr'+(h>1?'s ':' ') : '') + r + ' mins'; }
    function msg(html){ $('rgMsg').innerHTML = html; }

    function updateLinks(origin){
        const g = $('rgGmapsLink'), w = $('rgWazeLink');
        if (!g && !w) return;
        const destParam = RESORT.lat + ',' + RESORT.lng;
        if (g) g.href = 'https://www.google.com/maps/dir/?api=1&origin=' + encodeURIComponent(origin) +
            '&destination=' + destParam + '&travelmode=driving';
        if (w) w.href = 'https://waze.com/ul?ll=' + destParam + '&navigate=yes';
    }

    // ---------- Mode A: Google Maps JavaScript API (with API Key) ----------
    window.rgInitGoogleMap = function(){
        const box = $('rgMap');
        box.innerHTML = '';
        map = new google.maps.Map(box, { 
            center: RESORT, 
            zoom: 16, 
            mapTypeId: 'roadmap',
            mapTypeControl: true,
            mapTypeControlOptions: {
                style: google.maps.MapTypeControlStyle.DROPDOWN_MENU,
                position: google.maps.ControlPosition.TOP_RIGHT
            },
            streetViewControl: true,
            fullscreenControl: true
        });

        // Add 3D Tilt listener
        map.setTilt(isTilted ? 45 : 0);

        resortMarker = new google.maps.Marker({ 
            position: RESORT, 
            map, 
            title: 'Purple Bamboo Resort',
            animation: google.maps.Animation.DROP
        });

        infoWindow = new google.maps.InfoWindow({ 
            content: '<div style="padding:4px; font-family: Outfit, sans-serif;">' +
                     '<strong style="color:#6b21a8; font-size:14px;"><i class="fa-solid fa-hotel"></i> Purple Bamboo Resort</strong><br>' +
                     '<span style="font-size:12px; color:#475569;">' + RESORT_LABEL + '</span><br>' +
                     '<small style="color:#94a3b8;">Coordinates: ' + RESORT.lat.toFixed(6) + ', ' + RESORT.lng.toFixed(6) + '</small>' +
                     '</div>' 
        });
        infoWindow.open(map, resortMarker);
        resortMarker.addListener('click', () => infoWindow.open(map, resortMarker));

        dirService = new google.maps.DirectionsService();
        dirRenderer = new google.maps.DirectionsRenderer({ 
            map, 
            suppressMarkers: false, 
            polylineOptions: { strokeColor: '#9333ea', strokeWeight: 5 } 
        });

        autocomplete = new google.maps.places.Autocomplete($('rgOriginInput'), { 
            componentRestrictions: { country: 'ph' }, 
            fields: ['geometry','formatted_address','name'] 
        });
        autocomplete.addListener('place_changed', () => rgCalculate());
    };

    function routeWithGoogle(origin){
        msg('<i class="fa-solid fa-spinner fa-spin"></i> Calculating road route via Google Maps...');
        dirService.route({ 
            origin, 
            destination: RESORT, 
            travelMode: google.maps.TravelMode.DRIVING,
            drivingOptions: {
                departureTime: new Date(Date.now()), // Uses current traffic conditions
                trafficModel: 'bestguess'
            }   
        }, (res, status) => {
            if (status !== 'OK') { 
                msg('<i class="fa-solid fa-triangle-exclamation"></i> Could not find a driving route from that location. Please try a more specific address.'); 
                return; 
            }
            dirRenderer.setDirections(res);
            const leg = res.routes[0].legs[0];
            // Prefer live traffic duration if available, else fallback to standard duration
            const trafficMins = leg.duration_in_traffic ? (leg.duration_in_traffic.value / 60) : (leg.duration.value / 60);
            showStats(leg.distance.value / 1000, trafficMins, leg.start_address);
        });
    }

    // ---------- Mode B: Keyless Fallback (Google Maps Embed + OSRM Engine) ----------
    function routeKeyless(originText, coords){
        activeOriginQuery = coords ? (coords.lat + ',' + coords.lng) : originText;
        updateIframeEmbed();
        msg('<i class="fa-solid fa-spinner fa-spin"></i> Calculating distance and travel duration...');

        const getCoords = coords ? Promise.resolve(coords) :
            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ph&q=' + encodeURIComponent(originText))
                .then(r => r.json()).then(d => d && d[0] ? { lat: +d[0].lat, lng: +d[0].lon } : null);

        getCoords.then(c => {
            if (!c) throw new Error('nf');
            return fetch(`https://router.project-osrm.org/route/v1/driving/${c.lng},${c.lat};${RESORT.lng},${RESORT.lat}?overview=false`)
                .then(r => r.json());
        }).then(d => {
            if (!d.routes || !d.routes.length) throw new Error('nr');
            const distanceKm = d.routes[0].distance / 1000;
            const durationMins = d.routes[0].duration / 60;

            showStats(distanceKm, durationMins, originText);
        }).catch(() => {
            msg('<i class="fa-solid fa-circle-info"></i> Route shown on map above. See Google turn-by-turn panel for exact live traffic estimates.');
        });
    }

    function updateIframeEmbed(){
        const iframe = $('rgMapFrame');
        if (!iframe) return;
        const typeParam = (currentMapType === 'satellite' || currentMapType === 'hybrid') ? '&t=k' : '&t=m';
        if (activeOriginQuery) {
            iframe.src = 'https://maps.google.com/maps?saddr=' + encodeURIComponent(activeOriginQuery) +
                '&daddr=' + RESORT.lat + ',' + RESORT.lng + typeParam + '&output=embed';
        } else {
            iframe.src = 'https://maps.google.com/maps?q=' + RESORT.lat + ',' + RESORT.lng + '(Purple+Bamboo+Resort)&z=16' + typeParam + '&output=embed';
        }
    }

    function showStats(km, mins, label){
        $('rgStats').style.display = 'grid';
        $('rgDist').textContent = (km < 10 ? km.toFixed(1) : Math.round(km)) + ' km';
        $('rgTime').textContent = fmtMins(mins);
        if ($('rgBus')) {
            const commuteMins = km <= 5 ? (mins + 10) : (mins * 1.2 + 25);
            $('rgBus').textContent = fmtMins(commuteMins);
        }
        msg('<i class="fa-solid fa-circle-check" style="color:#059669"></i> Route calculated from <strong>' + label + '</strong> to Purple Bamboo Resort (' + RESORT_LABEL + ').');
    }

    // ---------- User Action Handlers ----------
    window.rgSetMapView = function(mode){
        currentMapType = mode;
        const btnRoad = $('btnViewRoadmap');
        const btnSat = $('btnViewSatellite');
        if (mode === 'satellite') {
            if (btnRoad) btnRoad.classList.remove('active');
            if (btnSat) btnSat.classList.add('active');
            if (window.google && map) {
                map.setMapTypeId('hybrid');
            } else {
                updateIframeEmbed();
            }
        } else {
            if (btnSat) btnSat.classList.remove('active');
            if (btnRoad) btnRoad.classList.add('active');
            if (window.google && map) {
                map.setMapTypeId('roadmap');
            } else {
                updateIframeEmbed();
            }
        }
    };

    window.rgToggleTilt = function(){
        isTilted = !isTilted;
        const btn = $('btnViewTilt');
        if (btn) {
            if (isTilted) btn.classList.add('active');
            else btn.classList.remove('active');
        }
        if (window.google && map) {
            map.setMapTypeId('hybrid');
            map.setTilt(isTilted ? 45 : 0);
            if (isTilted) map.setHeading(45);
        } else {
            // For iframe keyless, switch to satellite hybrid for rich aerial perspective
            rgSetMapView('satellite');
        }
    };

    window.rgRecenter = function(){
        if (window.google && map) {
            map.setCenter(RESORT);
            map.setZoom(16);
            if (infoWindow && resortMarker) infoWindow.open(map, resortMarker);
        } else {
            activeOriginQuery = null;
            updateIframeEmbed();
        }
    };

    window.rgCalculate = function(coords){
        const text = $('rgOriginInput').value.trim();
        if (!coords && !text) { 
            msg('<i class="fa-solid fa-triangle-exclamation"></i> Please enter your starting city, town, or address first.'); 
            return; 
        }
        const originLabel = coords ? (coords.lat.toFixed(5) + ',' + coords.lng.toFixed(5)) : text;
        updateLinks(originLabel);

        if (window.google && dirService) {
            const place = !coords && autocomplete ? autocomplete.getPlace() : null;
            const origin = coords ? coords : (place && place.geometry && place.formatted_address === text ? place.geometry.location : text);
            routeWithGoogle(origin);
        } else {
            routeKeyless(text, coords || null);
        }
    };

    window.rgUseGps = function(){
        if (!navigator.geolocation) { 
            msg('Geolocation is not supported by your browser.'); 
            return; 
        }
        const b = $('rgGpsBtn'), orig = b.innerHTML;
        b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Locating...';
        navigator.geolocation.getCurrentPosition(p => {
            b.innerHTML = orig;
            $('rgOriginInput').value = 'My current GPS location';
            rgCalculate({ lat: p.coords.latitude, lng: p.coords.longitude });
        }, () => { 
            b.innerHTML = orig; 
            msg('<i class="fa-solid fa-triangle-exclamation"></i> Location permission was denied. Please type your city or town name instead.'); 
        },
        { timeout: 10000, enableHighAccuracy: true });
    };

    // Load Google Maps JS API if key is present
    if (GM_KEY && !window.google) {
        const s = document.createElement('script');
        s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(GM_KEY) + '&libraries=places&callback=rgInitGoogleMap';
        s.async = true; s.defer = true;
        document.head.appendChild(s);
    } else if (GM_KEY && window.google) {
        rgInitGoogleMap();
    }
})();
</script>
