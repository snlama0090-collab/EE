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

// ===== HAMBURGER MENU (guarded: index.php may not use this element) =====
if (hamburger && navMenu) {
    hamburger.addEventListener('click', () => {
        navMenu.classList.toggle('active');
    });

    // Close menu when clicking on a link
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
        
        // Remove active class from all buttons and contents
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(content => content.classList.remove('active'));
        
        // Add active class to clicked button and corresponding content
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
            
            console.log('Location detected:', userLocation);
            
            // Get place name via reverse geocoding
            getPlaceNameFromCoordinates(userLocation.lat, userLocation.lng);
            
            // Initialize map
            initMap();
            
            // Fetch nearby stations
            fetchNearbyStations();
            
            getLocationBtn.disabled = false;
            getLocationBtn.innerHTML = '<i class="fas fa-check-circle"></i> Location Updated';
        },
        (error) => {
            let message = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    message = 'Location permission denied';
                    break;
                case error.POSITION_UNAVAILABLE:
                    message = 'Location information unavailable';
                    break;
                case error.TIMEOUT:
                    message = 'Location request timed out';
                    break;
                default:
                    message = 'Unable to get location';
            }
            
            showLocationError(message);
            getLocationBtn.disabled = false;
            getLocationBtn.innerHTML = '<i class="fas fa-location-dot"></i> Use My Location';
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
});

// ===== SHOW LOCATION ERROR =====
function showLocationError(message) {
    locationStatus.innerHTML = `<i class="fas fa-exclamation-circle" style="color:#FF3B30;"></i> ${message}`;
    locationStatus.style.color = '#FF3B30';
    
    // Use default location (Kathmandu)
    userLocation = {
        lat: 27.7172,
        lng: 85.3240,
        accuracy: 5000
    };
    
    console.log('Using default location (Kathmandu)');
    getPlaceNameFromCoordinates(userLocation.lat, userLocation.lng);
    initMap();
    fetchNearbyStations();
}

// ===== GET PLACE NAME FROM COORDINATES =====
function getPlaceNameFromCoordinates(lat, lng) {
    // Use Nominatim API for reverse geocoding
    fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
        .then(response => response.json())
        .then(data => {
            const placeName = data.address?.city || data.address?.town || data.address?.village || data.address?.county || 'Unknown Location';
            userLocation.placeName = placeName;
            locationStatus.innerHTML = `<i class="fas fa-check-circle" style="color:#34C759;"></i> ${placeName}`;
            locationStatus.style.color = '#34C759';
            console.log('Place name:', placeName);
            
            // Update marker popup with place name
            updateMapMarkerPopup(placeName);
        })
        .catch(error => {
            // Fallback to just showing "Unknown Location" without coordinates
            userLocation.placeName = 'Unknown Location';
            locationStatus.textContent = `✅ Unknown Location`;
            locationStatus.style.color = '#34C759';
            console.log('Reverse geocoding failed:', error);
            updateMapMarkerPopup('Unknown Location');
        });
}

// ===== UPDATE MAP MARKER POPUP =====
function updateMapMarkerPopup(placeName) {
    if (map && map._layers) {
        // Find and update the user location circle marker
        Object.values(map._layers).forEach(layer => {
            if (layer instanceof L.CircleMarker && layer._latlng.lat === userLocation.lat) {
                layer.setPopupContent(`📍 ${placeName}`);
                layer.openPopup();
            }
        });
    }
}

// ===== INITIALIZE MAP =====
function initMap() {
    if (map) {
        map.setView([userLocation.lat, userLocation.lng], 13);
        return;
    }
    
    const mapContainer = document.getElementById('map');
    
    map = L.map('map').setView([userLocation.lat, userLocation.lng], 13);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
    }).addTo(map);
    
    // Add user location marker
    L.circleMarker([userLocation.lat, userLocation.lng], {
        radius: 8,
        fillColor: '#007AFF',
        color: '#0051D5',
        weight: 2,
        opacity: 1,
        fillOpacity: 0.8
    }).addTo(map).bindPopup(`📍 ${userLocation.placeName || 'Your Location'}`).openPopup();
}

// ===== FETCH NEARBY STATIONS =====
async function fetchNearbyStations() {
    if (!userLocation) return;
    
    stationsList.innerHTML = '<div class="station-card loading"><div class="skeleton"></div><div class="skeleton"></div></div>';
    
    try {
        // Simulated API response (in production, replace with actual API call)
        const stations = generateMockStations(userLocation);
        
        // Display stations
        displayStations(stations);
        
        // Show on map
        showStationsOnMap(stations);
        
    } catch (error) {
        console.error('Error fetching stations:', error);
        stationsList.innerHTML = '<p style="grid-column: 1/-1; text-align: center; color: #FF3B30;"><i class="fas fa-exclamation-circle"></i> Failed to load stations</p>';
    }
}

// ===== GENERATE MOCK STATIONS (for demo) =====
function generateMockStations(center) {
    const stations = [
        {
            id: 1,
            name: 'Kathmandu Central Station',
            type: 'DC Fast',
            wattage: 50,
            chargers: 5,
            available: 3,
            lat: center.lat + 0.01,
            lng: center.lng - 0.01,
            distance: 1.2,
            rating: 4.8
        },
        {
            id: 2,
            name: 'ThamelPark Charging Hub',
            type: 'AC 22kW',
            wattage: 22,
            chargers: 8,
            available: 2,
            lat: center.lat - 0.015,
            lng: center.lng + 0.02,
            distance: 2.1,
            rating: 4.5
        },
        {
            id: 3,
            name: 'Green Energy Station',
            type: 'DC 30kW',
            wattage: 30,
            chargers: 3,
            available: 1,
            lat: center.lat + 0.02,
            lng: center.lng + 0.015,
            distance: 3.5,
            rating: 4.9
        },
        {
            id: 4,
            name: 'Bhaktapur EV Hub',
            type: 'AC 7kW',
            wattage: 7,
            chargers: 4,
            available: 4,
            lat: center.lat - 0.025,
            lng: center.lng - 0.02,
            distance: 4.2,
            rating: 4.3
        },
        {
            id: 5,
            name: 'Lalitpur Charging Station',
            type: 'DC Fast',
            wattage: 50,
            chargers: 6,
            available: 0,
            lat: center.lat + 0.025,
            lng: center.lng - 0.025,
            distance: 5.1,
            rating: 4.7
        },
        {
            id: 6,
            name: 'Express Charging Network',
            type: 'AC 11kW',
            wattage: 11,
            chargers: 2,
            available: 2,
            lat: center.lat - 0.02,
            lng: center.lng + 0.025,
            distance: 5.8,
            rating: 4.6
        }
    ];
    
    return stations.sort((a, b) => a.distance - b.distance);
}

// ===== DISPLAY STATIONS IN LIST =====
function displayStations(stations) {
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
                <span class="distance-badge">${station.distance} km</span>
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
    // Remove old markers
    markers.forEach(marker => map.removeLayer(marker));
    markers = [];
    
    // Add new markers
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
        
        const popupContent = `
            <div style="font-size: 12px;">
                <strong>${station.name}</strong><br>
                ${station.type} • ${station.wattage}kW<br>
                <span style="color: ${color};">
                    ${station.available > 0 ? `✅ ${station.available} Available` : '❌ Full'}
                </span>
            </div>
        `;
        
        marker.bindPopup(popupContent);
        markers.push(marker);
    });
    
    // Fit map to show all markers
    if (markers.length > 0) {
        const group = new L.featureGroup(markers);
        map.fitBounds(group.getBounds(), { padding: [50, 50] });
    }
}

// ===== BOOK STATION =====
function bookStation(stationId, stationName) {
    if (!userLocation) {
        alert('Please enable location first');
        getLocationBtn.click();
        return;
    }
    
    // Redirect to login/register
    const loginUrl = `login.php?redirect=booking&station=${stationId}`;
    window.location.href = loginUrl;
}

// ===== INITIALIZE ON PAGE LOAD =====
document.addEventListener('DOMContentLoaded', () => {
    console.log('Landing page loaded');
    
    // Try to get location automatically
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                userLocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude,
                    accuracy: position.coords.accuracy
                };
                
                locationStatus.textContent = `✅ Using your location`;
                locationStatus.style.color = '#34C759';
                
                initMap();
                fetchNearbyStations();
            },
            () => {
                // Use default location silently
                userLocation = {
                    lat: 27.7172,
                    lng: 85.3240,
                    accuracy: 5000
                };
                
                locationStatus.textContent = 'Showing Kathmandu area (enable location for precise results)';
                locationStatus.style.color = '#666';
                
                initMap();
                fetchNearbyStations();
            }
        );
    } else {
        // Fallback to default location
        userLocation = {
            lat: 27.7172,
            lng: 85.3240,
            accuracy: 5000
        };
        
        locationStatus.textContent = 'Location unavailable (showing Kathmandu)';
        locationStatus.style.color = '#FF9500';
        
        initMap();
        fetchNearbyStations();
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
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    });
});

// ===== HANDLE WINDOW RESIZE =====
window.addEventListener('resize', () => {
    if (map) {
        map.invalidateSize();
    }
});

// ===== EXPORT FUNCTIONS FOR TESTING =====
window.bookStation = bookStation;
window.fetchNearbyStations = fetchNearbyStations;