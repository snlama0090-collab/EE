// ===== DOM ELEMENTS =====
const hamburger = document.querySelector('.hamburger');
const navMenu = document.querySelector('.nav-menu');
const getLocationBtn = document.getElementById('get-location-btn');
const locationStatus = document.getElementById('location-status');
const stationsList = document.getElementById('stations-list');
const tabButtons = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');

// ===== GLOBAL VARIABLES =====
let userLocation = null;
let map = null;
let markers = [];
let currentStations = [];
const DEFAULT_LOCATION = { lat: 27.7172, lng: 85.3240, accuracy: 5000 };

// ===== DEFAULT STATION DATA (instant render, no geolocation needed) =====
const DEFAULT_STATIONS = [
    { id: 1, name: 'Kathmandu Central Station', type: 'DC Fast', wattage: 50, chargers: 5, available: 3, lat: 27.7272, lng: 85.3140, distance: 1.2, rating: 4.8 },
    { id: 2, name: 'ThamelPark Charging Hub', type: 'AC 22kW', wattage: 22, chargers: 8, available: 2, lat: 27.7022, lng: 85.3440, distance: 2.1, rating: 4.5 },
    { id: 3, name: 'Green Energy Station', type: 'DC 30kW', wattage: 30, chargers: 3, available: 1, lat: 27.7372, lng: 85.3390, distance: 3.5, rating: 4.9 },
    { id: 4, name: 'Bhaktapur EV Hub', type: 'AC 7kW', wattage: 7, chargers: 4, available: 4, lat: 27.6922, lng: 85.3040, distance: 4.2, rating: 4.3 },
    { id: 5, name: 'Lalitpur Charging Station', type: 'DC Fast', wattage: 50, chargers: 6, available: 0, lat: 27.7422, lng: 85.2990, distance: 5.1, rating: 4.7 },
    { id: 6, name: 'Express Charging Network', type: 'AC 11kW', wattage: 11, chargers: 2, available: 2, lat: 27.6972, lng: 85.3490, distance: 5.8, rating: 4.6 }
];

// ponytail: all listeners guarded — landing.js must never throw on dashboard pages

// ===== HAMBURGER MENU (guarded: index.php may not use this element) =====
if (hamburger && navMenu) {
    hamburger.addEventListener('click', () => {
        navMenu.classList.toggle('active');
    });

    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (navMenu) navMenu.classList.remove('active');
        });
    });
}

// ===== TAB SWITCHING =====
tabButtons.forEach(button => {
    button.addEventListener('click', () => {
        const tabName = button.getAttribute('data-tab');
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(content => content.classList.remove('active'));
        button.classList.add('active');
        document.getElementById(`${tabName}-tab`).classList.add('active');
    });
});

// ===== LOCATION DETECTION =====
getLocationBtn.addEventListener('click', () => {
    getLocationBtn.disabled = true;
    getLocationBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';

    if (!navigator.geolocation) {
        showLocationError('Geolocation is not supported');
        getLocationBtn.disabled = false;
        getLocationBtn.innerHTML = '<i class="fas fa-location-dot"></i> Use My Location';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude,
                accuracy: position.coords.accuracy
            };

            getPlaceNameFromCoordinates(userLocation.lat, userLocation.lng);
            updateMapAndCardsForLocation(userLocation.lat, userLocation.lng);

            getLocationBtn.disabled = false;
            getLocationBtn.innerHTML = '<i class="fas fa-check-circle"></i> Location Updated';
        },
        (error) => {
            let message = '';
            switch(error.code) {
                case error.PERMISSION_DENIED: message = 'Location permission denied'; break;
                case error.POSITION_UNAVAILABLE: message = 'Location information unavailable'; break;
                case error.TIMEOUT: message = 'Location request timed out'; break;
                default: message = 'Unable to get location';
            }
            showLocationError(message);
            getLocationBtn.disabled = false;
            getLocationBtn.innerHTML = '<i class="fas fa-location-dot"></i> Use My Location';
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
});

// ===== UPDATE MAP AND CARDS FOR A GIVEN LOCATION =====
function updateMapAndCardsForLocation(lat, lng) {
    userLocation = { lat, lng, accuracy: userLocation?.accuracy || 5000 };

    // Recalculate distances for existing cards
    currentStations.forEach(s => {
        s.distance = calculateDistance(lat, lng, s.lat, s.lng);
    });
    currentStations.sort((a, b) => a.distance - b.distance);

    // Re-render cards with updated distances
    displayStations(currentStations);

    // Update map
    initMap();
    showStationsOnMap(currentStations);
}

// ===== SHOW LOCATION ERROR =====
function showLocationError(message) {
    locationStatus.innerHTML = `<i class="fas fa-exclamation-circle" style="color:#FF3B30;"></i> ${message}`;
    locationStatus.style.color = '#FF3B30';
    // Preserve existing cards, just update status text
}

// ===== GET PLACE NAME FROM COORDINATES =====
function getPlaceNameFromCoordinates(lat, lng) {
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
        .then(response => response.json())
        .then(data => {
            const placeName = data.address?.city || data.address?.town || data.address?.village || data.address?.county || 'Unknown Location';
            userLocation.placeName = placeName;
            locationStatus.innerHTML = `<i class="fas fa-check-circle" style="color:#34C759;"></i> ${placeName}`;
            locationStatus.style.color = '#34C759';
            updateMapMarkerPopup(placeName);
        })
        .catch(() => {
            userLocation.placeName = 'Unknown Location';
            locationStatus.textContent = '✅ Unknown Location';
            locationStatus.style.color = '#34C759';
            updateMapMarkerPopup('Unknown Location');
        });
}

// ===== UPDATE MAP MARKER POPUP =====
function updateMapMarkerPopup(placeName) {
    if (!map || !map._layers) return;
    Object.values(map._layers).forEach(layer => {
        if (layer instanceof L.CircleMarker && layer._latlng.lat === userLocation.lat) {
            layer.setPopupContent(`📍 ${placeName}`);
            layer.openPopup();
        }
    });
}

// ===== INITIALIZE MAP =====
function initMap() {
    const loc = userLocation || DEFAULT_LOCATION;

    if (map) {
        map.setView([loc.lat, loc.lng], 12);
        return;
    }

    map = L.map('map').setView([loc.lat, loc.lng], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);

    L.circleMarker([loc.lat, loc.lng], {
        radius: 8,
        fillColor: '#007AFF',
        color: '#0051D5',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.8
    }).addTo(map).bindPopup(`📍 ${loc.placeName || 'Your Location'}`).openPopup();
}

// ===== FETCH NEARBY STATIONS (kept for button click, but no skeleton flash) =====
async function fetchNearbyStations() {
    if (!userLocation) return;

    // If we already have cards rendered, update them; otherwise render
    if (currentStations.length > 0) {
        updateMapAndCardsForLocation(userLocation.lat, userLocation.lng);
        return;
    }

    const stations = generateMockStations(userLocation);
    displayStations(stations);
    showStationsOnMap(stations);
}

// ===== GENERATE MOCK STATIONS (for demo) =====
function generateMockStations(center) {
    return DEFAULT_STATIONS.map(s => ({
        ...s,
        lat: center.lat + (s.lat - DEFAULT_LOCATION.lat),
        lng: center.lng + (s.lng - DEFAULT_LOCATION.lng),
        distance: calculateDistance(center.lat, center.lng,
            center.lat + (s.lat - DEFAULT_LOCATION.lat),
            center.lng + (s.lng - DEFAULT_LOCATION.lng))
    })).sort((a, b) => a.distance - b.distance);
}

// ===== DISPLAY STATIONS IN LIST =====
function displayStations(stations) {
    currentStations = stations;
    stationsList.innerHTML = '';

    if (stations.length === 0) {
        stationsList.innerHTML = '<p style="grid-column: 1/-1; text-align: center;">No stations found nearby</p>';
        return;
    }

    stations.forEach(station => {
        const card = createStationCard(station);
        stationsList.appendChild(card);
    });
}

// ===== CREATE STATION CARD =====
function createStationCard(station) {
    const card = document.createElement('div');
    card.className = 'station-card';

    const statusText = station.available > 0 ? `${station.available} Available` : 'Full';
    const statusClass = station.available > 0 ? 'badge-success' : 'badge-danger';

    card.innerHTML = `
        <div class="station-card-inner">
            <div class="station-card-header">
                <div class="station-name"><i class="fas fa-map-pin" style="margin-right:4px;color:var(--muted-foreground);"></i> ${station.name}</div>
                <span class="distance-badge">${station.distance.toFixed(1)} km</span>
            </div>
            <div class="station-card-specs">
                <span class="charger-badge"><i class="fas fa-bolt" style="margin-right:3px;"></i> ${station.type}</span>
                <span class="charger-badge">${station.wattage} kW</span>
                <span class="charger-badge" style="background:var(--muted);">${station.chargers} chargers</span>
                <span class="badge ${statusClass}">${statusText}</span>
            </div>
            <div class="station-card-footer">
                <div class="station-rating">
                    ${Array(5).fill().map((_, i) =>
                        `<i class="fas fa-star" style="color:${i < Math.floor(station.rating) ? '#FFD700' : '#DDD'};font-size:12px;"></i>`
                    ).join('')}
                    <span style="font-size:12px;color:var(--muted-foreground);margin-left:4px;">${station.rating}</span>
                </div>
                <button class="btn btn-primary btn-sm station-book-btn" onclick="bookStation(${station.id}, '${station.name}')">
                    <i class="fas fa-plug"></i> Book Now
                </button>
            </div>
        </div>
    `;

    return card;
}

// ===== SHOW STATIONS ON MAP =====
function showStationsOnMap(stations) {
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];

    stations.forEach(station => {
        const color = station.available > 0 ? '#34C759' : '#FF3B30';
        const marker = L.circleMarker([station.lat, station.lng], {
            radius: 8,
            fillColor: color,
            color: '#fff',
            weight: 2,
            opacity: 1,
            fillOpacity: 0.8
        }).addTo(map);

        marker.bindPopup(`
            <div style="font-size: 12px;">
                <strong>${station.name}</strong><br>
                ${station.type} • ${station.wattage}kW<br>
                <span style="color: ${color};">
                    ${station.available > 0 ? `✅ ${station.available} Available` : '❌ Full'}
                </span>
            </div>
        `);
        markers.push(marker);
    });

    if (markers.length > 0) {
        const group = new L.featureGroup(markers);
        map.fitBounds(group.getBounds(), { padding: [50, 50] });
    }
}

// ===== HAVERSINE DISTANCE =====
function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// ===== BOOK STATION =====
function bookStation(stationId, stationName) {
    const loginUrl = `login.php?redirect=booking&station=${stationId}`;
    window.location.href = loginUrl;
}

// ===== INITIALIZE ON PAGE LOAD =====
document.addEventListener('DOMContentLoaded', () => {
    console.log('Landing page loaded');

    // Prevent dark mode bleed from dashboard — landing always starts light
    document.documentElement.removeAttribute('data-theme');

    // ── Step 1: Render default cards and map instantly (no geolocation wait) ──
    userLocation = { ...DEFAULT_LOCATION, placeName: 'Kathmandu' };
    displayStations(DEFAULT_STATIONS);
    initMap();
    showStationsOnMap(DEFAULT_STATIONS);

    // ── Step 2: Background geolocation with strict timeout ──
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                userLocation = { lat, lng, accuracy: position.coords.accuracy };

                locationStatus.textContent = 'Using your location';
                locationStatus.style.color = '#34C759';

                // Update distances and re-sort existing cards
                currentStations.forEach(s => {
                    s.distance = calculateDistance(lat, lng, s.lat, s.lng);
                });
                currentStations.sort((a, b) => a.distance - b.distance);
                displayStations(currentStations);

                // Update map
                initMap();
                showStationsOnMap(currentStations);

                // Get place name
                getPlaceNameFromCoordinates(lat, lng);
            },
            () => {
                // Geolocation failed/timed out — leave default cards, update status
                locationStatus.textContent = 'Location not detected — showing Kathmandu region';
                locationStatus.style.color = '#FF9500';
            },
            { timeout: 2000, maximumAge: 60000, enableHighAccuracy: false }
        );
    } else {
        locationStatus.textContent = 'Geolocation not supported — showing Kathmandu region';
        locationStatus.style.color = '#FF9500';
    }
});

// ===== SMOOTH SCROLL BEHAVIOR =====
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        const href = this.getAttribute('href');
        if (href !== '#') {
            e.preventDefault();
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    });
});

// ===== HANDLE WINDOW RESIZE =====
window.addEventListener('resize', () => {
    if (map) map.invalidateSize();
});

// ===== EXPORT FUNCTIONS FOR TESTING =====
window.bookStation = bookStation;
window.fetchNearbyStations = fetchNearbyStations;