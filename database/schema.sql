-- ===== EV CHARGING STATION DATABASE SCHEMA =====
-- Created for: EV Charging Station Finder
-- Description: Complete database structure for all tables

-- ===== CREATE DATABASE =====
CREATE DATABASE IF NOT EXISTS ev_charging_db;
USE ev_charging_db;

-- ===== 1. USERS TABLE (EV Drivers) =====
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20),
    profile_pic VARCHAR(255),
    
    -- EV Details
    car_model VARCHAR(100),
    car_full_capacity_kwh DECIMAL(5, 2),
    current_battery_percent INT DEFAULT 50,
    charger_preference VARCHAR(50),
    
    -- Account Status
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    verification_expires_at TIMESTAMP,
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- ===== 2. OWNERS TABLE (Station Owners) =====
CREATE TABLE owners (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100),
    company_name VARCHAR(150) NOT NULL,
    phone VARCHAR(20),
    description TEXT,
    logo VARCHAR(255),
    
    -- Bank Details
    bank_account_number VARCHAR(50),
    bank_name VARCHAR(100),
    account_holder_name VARCHAR(100),
    
    -- Account Status
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(255),
    status ENUM('active', 'inactive', 'banned') DEFAULT 'active',
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_status (status),
    INDEX idx_approval_status (approval_status),
    INDEX idx_created_at (created_at)
);

-- ===== 3. ADMIN TABLE =====
CREATE TABLE admins (
    id INT PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100),
    phone VARCHAR(20),
    role ENUM('super_admin', 'moderator') DEFAULT 'moderator',
    
    -- Permissions
    can_approve_stations BOOLEAN DEFAULT TRUE,
    can_manage_users BOOLEAN DEFAULT TRUE,
    can_moderate_reviews BOOLEAN DEFAULT TRUE,
    
    -- Account Status
    status ENUM('active', 'inactive') DEFAULT 'active',
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_role (role)
);

-- ===== 4. STATIONS TABLE =====
CREATE TABLE stations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    owner_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    
    -- Location
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    address VARCHAR(255),
    city VARCHAR(50),
    
    -- Station Details
    num_chargers INT DEFAULT 1,
    total_capacity INT,
    opening_time TIME,
    closing_time TIME,
    
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    rejection_reason TEXT,
    
    -- Analytics
    total_bookings INT DEFAULT 0,
    total_revenue DECIMAL(10, 2) DEFAULT 0,
    total_kwh_consumed DECIMAL(10, 2) DEFAULT 0,
    average_rating DECIMAL(3, 2) DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    INDEX idx_owner_id (owner_id),
    INDEX idx_approval_status (approval_status),
    INDEX idx_location (latitude, longitude),
    INDEX idx_city (city),
    INDEX idx_is_active (is_active),
    INDEX idx_created_at (created_at)
);

-- ===== 5. CHARGERS TABLE =====
CREATE TABLE chargers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    station_id INT NOT NULL,
    charger_number INT NOT NULL,
    
    -- Charger Specifications
    charger_type VARCHAR(50) NOT NULL, -- DC Fast, AC 22kW, AC 11kW, AC 7kW, etc
    wattage_kw DECIMAL(5, 2) NOT NULL,
    
    -- Status
    status ENUM('available', 'charging', 'maintenance', 'offline') DEFAULT 'available',
    
    -- Analytics
    total_sessions INT DEFAULT 0,
    total_kwh_delivered DECIMAL(10, 2) DEFAULT 0,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    INDEX idx_station_id (station_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_charger (station_id, charger_number)
);

-- ===== 6. BOOKINGS TABLE =====
CREATE TABLE bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    charger_id INT NOT NULL,
    
    -- Booking Details
    booking_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    arrival_deadline TIMESTAMP,
    
    -- Calculated Values
    car_current_battery_percent INT,
    car_full_capacity_kwh DECIMAL(5, 2),
    calculated_charge_time_minutes INT,
    
    -- Status
    status ENUM('booked', 'charging', 'completed', 'cancelled') DEFAULT 'booked',
    
    -- Payments
    base_fee DECIMAL(8, 2) DEFAULT 20,
    estimated_total_cost DECIMAL(10, 2),
    payment_amount DECIMAL(10, 2),
    payment_status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    
    -- Extensions
    extended BOOLEAN DEFAULT FALSE,
    extension_cost DECIMAL(10, 2),
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (charger_id) REFERENCES chargers(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_charger_id (charger_id),
    INDEX idx_status (status),
    INDEX idx_booking_time (booking_time),
    INDEX idx_created_at (created_at)
);

-- ===== 7. CHARGING SESSIONS TABLE =====
CREATE TABLE charging_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT NOT NULL UNIQUE,
    
    -- Session Timing
    start_time TIMESTAMP,
    end_time TIMESTAMP,
    
    -- Battery Information
    battery_start_percent INT,
    battery_end_percent INT,
    
    -- Energy Consumption
    kwh_consumed DECIMAL(10, 2),
    actual_charge_time_minutes INT,
    
    -- Cost Calculation
    per_kwh_rate DECIMAL(5, 2) DEFAULT 10,
    electricity_cost DECIMAL(10, 2),
    total_payment DECIMAL(10, 2),
    payment_status ENUM('pending', 'completed', 'refunded') DEFAULT 'pending',
    
    -- Additional Notes
    notes TEXT,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
    INDEX idx_booking_id (booking_id),
    INDEX idx_start_time (start_time),
    INDEX idx_payment_status (payment_status)
);

-- ===== 8. RATINGS & REVIEWS TABLE =====
CREATE TABLE ratings_reviews (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    station_id INT NOT NULL,
    booking_id INT,
    
    -- Review Details
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    
    -- Review Status
    is_flagged BOOLEAN DEFAULT FALSE,
    flag_reason VARCHAR(255),
    is_deleted BOOLEAN DEFAULT FALSE,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_station_id (station_id),
    INDEX idx_rating (rating),
    INDEX idx_is_flagged (is_flagged),
    UNIQUE KEY unique_review (user_id, station_id, booking_id)
);

-- ===== 9. FAVORITES TABLE =====
CREATE TABLE favorites (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    station_id INT NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES stations(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_station_id (station_id),
    UNIQUE KEY unique_favorite (user_id, station_id)
);

-- ===== 10. OWNER REPLIES TABLE (for review replies) =====
CREATE TABLE owner_replies (
    id INT PRIMARY KEY AUTO_INCREMENT,
    review_id INT NOT NULL,
    owner_id INT NOT NULL,
    reply_text TEXT NOT NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (review_id) REFERENCES ratings_reviews(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    INDEX idx_review_id (review_id),
    INDEX idx_owner_id (owner_id)
);

-- ===== 11. PAYMENT TRANSACTIONS TABLE =====
CREATE TABLE payment_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    booking_id INT,
    charging_session_id INT,
    
    -- Transaction Details
    transaction_id VARCHAR(100) UNIQUE,
    payment_method VARCHAR(50), -- card, wallet, upi, etc
    amount DECIMAL(10, 2),
    currency VARCHAR(3) DEFAULT 'NPR',
    
    -- Razorpay Integration
    razorpay_order_id VARCHAR(100),
    razorpay_payment_id VARCHAR(100),
    razorpay_signature VARCHAR(255),
    
    -- Status
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    failure_reason TEXT,
    
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (charging_session_id) REFERENCES charging_sessions(id) ON DELETE SET NULL,
    INDEX idx_booking_id (booking_id),
    INDEX idx_charging_session_id (charging_session_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
);

-- ===== 12. VERIFICATION TOKENS TABLE =====
CREATE TABLE verification_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    owner_id INT,
    token VARCHAR(255) UNIQUE NOT NULL,
    token_type ENUM('email', 'password_reset') DEFAULT 'email',
    is_used BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE CASCADE,
    INDEX idx_token (token),
    INDEX idx_expires_at (expires_at)
);

-- ===== 13. LOGS TABLE (for admin audit trail) =====
CREATE TABLE activity_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT,
    user_id INT,
    owner_id INT,
    
    -- Action Details
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50), -- station, booking, user, etc
    resource_id INT,
    details JSON,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES owners(id) ON DELETE SET NULL,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);

-- ===== 14. REMEMBER TOKENS TABLE =====
CREATE TABLE remember_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) UNIQUE NOT NULL,
    user_type VARCHAR(20) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_token (token)
);

-- ===== SAMPLE DATA (for testing) =====

-- Insert sample users
INSERT INTO users (email, password, name, phone, car_model, car_full_capacity_kwh, charger_preference) VALUES
('driver1@example.com', '$2y$10$abc123', 'Raj Patel', '+977 9801234567', 'Tesla Model 3', 75, 'dc_fast'),
('driver2@example.com', '$2y$10$def456', 'Priya Singh', '+977 9809876543', 'Nissan Leaf', 62, 'ac_22kw');

-- Insert sample owners
INSERT INTO owners (email, password, company_name, name, phone, approval_status) VALUES
('owner1@example.com', '$2y$10$ghi789', 'Green Energy Ltd', 'Ram Enterprise', '+977 9876543210', 'approved'),
('owner2@example.com', '$2y$10$jkl012', 'Eco Charging', 'Bishnu Energy', '+977 9843216543', 'approved');

-- Insert sample admin
INSERT INTO admins (email, password, name, role) VALUES
('admin@example.com', '$2y$10$mno345', 'Admin User', 'super_admin');

-- Insert sample stations
INSERT INTO stations (owner_id, name, latitude, longitude, address, city, num_chargers, approval_status) VALUES
(1, 'Kathmandu Central Station', 27.7172, 85.3240, 'New Road, Kathmandu', 'Kathmandu', 5, 'approved'),
(1, 'ThamelPark Charging Hub', 27.7165, 85.3128, 'Thamel, Kathmandu', 'Kathmandu', 8, 'approved'),
(2, 'Bhaktapur EV Hub', 27.6721, 85.4304, 'Durbar Square, Bhaktapur', 'Bhaktapur', 4, 'approved');

-- Insert sample chargers
INSERT INTO chargers (station_id, charger_number, charger_type, wattage_kw) VALUES
(1, 1, 'DC Fast', 50),
(1, 2, 'DC Fast', 50),
(1, 3, 'AC 22kW', 22),
(1, 4, 'AC 22kW', 22),
(1, 5, 'AC 7kW', 7);

