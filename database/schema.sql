-- Essenza Beauty Studio | MySQL 8.0.16+ | UTF8MB4 | InnoDB
-- Execute after creating the database; see README.md.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  salon_name VARCHAR(120) NOT NULL,
  subtitle VARCHAR(160) NOT NULL,
  hero_title VARCHAR(220) NOT NULL,
  hero_description TEXT NOT NULL,
  about_text TEXT NOT NULL,
  address VARCHAR(255) NOT NULL DEFAULT '',
  city VARCHAR(120) NOT NULL DEFAULT '',
  phone VARCHAR(30) NOT NULL DEFAULT '',
  whatsapp VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  instagram VARCHAR(255) NOT NULL DEFAULT '',
  logo VARCHAR(255) NOT NULL DEFAULT '',
  brand_color CHAR(7) NOT NULL DEFAULT '#b9897d',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  booking_lead_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  booking_horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  cancellation_hours SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_settings_one CHECK (id = 1)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','manager','reception') NOT NULL DEFAULT 'reception',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS clients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(20) NOT NULL UNIQUE,
  email VARCHAR(190) NULL,
  password_hash VARCHAR(255) NOT NULL,
  consent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_clients_name (name),
  INDEX idx_clients_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(90) NOT NULL UNIQUE,
  icon VARCHAR(50) NOT NULL DEFAULT 'sparkles',
  sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS services (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id SMALLINT UNSIGNED NOT NULL,
  name VARCHAR(130) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  image VARCHAR(255) NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id),
  INDEX idx_services_active_category (active, category_id),
  CONSTRAINT chk_service_price CHECK (price >= 0),
  CONSTRAINT chk_service_duration CHECK (duration_minutes BETWEEN 15 AND 480)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(130) NOT NULL,
  specialty VARCHAR(150) NOT NULL DEFAULT '',
  bio VARCHAR(350) NOT NULL DEFAULT '',
  photo VARCHAR(255) NOT NULL DEFAULT '',
  active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_services (
  staff_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (staff_id, service_id),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- WEEKDAY: 0=Sunday, 1=Monday ... 6=Saturday
CREATE TABLE IF NOT EXISTS salon_hours (
  weekday TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  opens TIME NULL,
  closes TIME NULL,
  closed BOOLEAN NOT NULL DEFAULT FALSE,
  CONSTRAINT chk_salon_weekday CHECK (weekday BETWEEN 0 AND 6)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_hours (
  staff_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  starts TIME NOT NULL,
  ends TIME NOT NULL,
  break_starts TIME NULL,
  break_ends TIME NULL,
  enabled BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (staff_id, weekday),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  CONSTRAINT chk_staff_weekday CHECK (weekday BETWEEN 0 AND 6),
  CONSTRAINT chk_staff_hours CHECK (starts < ends)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS salon_closures (
  closed_date DATE PRIMARY KEY,
  reason VARCHAR(160) NOT NULL DEFAULT 'Fechado'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS staff_days_off (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id BIGINT UNSIGNED NOT NULL,
  off_date DATE NOT NULL,
  reason VARCHAR(160) NOT NULL DEFAULT '',
  UNIQUE KEY uq_day_off (staff_id, off_date),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS booking_day_locks (
  staff_id BIGINT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  PRIMARY KEY (staff_id, work_date),
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS schedule_blocks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  staff_id BIGINT UNSIGNED NOT NULL,
  block_date DATE NOT NULL,
  starts TIME NOT NULL,
  ends TIME NOT NULL,
  reason VARCHAR(180) NOT NULL DEFAULT '',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE,
  INDEX idx_blocks_date_staff (block_date, staff_id),
  CONSTRAINT chk_blocks_time CHECK (starts < ends)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  confirmation_code VARCHAR(24) NOT NULL UNIQUE,
  client_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  scheduled_date DATE NOT NULL,
  starts TIME NOT NULL,
  ends TIME NOT NULL,
  price_snapshot DECIMAL(10,2) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  client_name_snapshot VARCHAR(150) NOT NULL,
  client_phone_snapshot VARCHAR(20) NOT NULL,
  client_email_snapshot VARCHAR(190) NULL,
  notes VARCHAR(700) NULL,
  status ENUM('pending','confirmed','completed','cancelled','no_show') NOT NULL DEFAULT 'confirmed',
  cancelled_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id),
  FOREIGN KEY (staff_id) REFERENCES staff(id),
  FOREIGN KEY (service_id) REFERENCES services(id),
  INDEX idx_appointments_staff_date (staff_id, scheduled_date, status, starts, ends),
  INDEX idx_appointments_client (client_id, scheduled_date, starts),
  INDEX idx_appointments_day (scheduled_date, status),
  CONSTRAINT chk_appointments_time CHECK (starts < ends)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS appointment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  appointment_id BIGINT UNSIGNED NOT NULL,
  actor_kind ENUM('client','admin','system') NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  details JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
  INDEX idx_appointment_events (appointment_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS client_notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(700) NOT NULL,
  client_authorization_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (admin_id) REFERENCES admins(id),
  INDEX idx_notes_client (client_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorite_services (
  client_id BIGINT UNSIGNED NOT NULL,
  service_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (client_id, service_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS favorite_staff (
  client_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (client_id, staff_id),
  FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
  FOREIGN KEY (staff_id) REFERENCES staff(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gallery (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  image VARCHAR(255) NOT NULL,
  caption VARCHAR(150) NOT NULL DEFAULT '',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS testimonials (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_name VARCHAR(120) NOT NULL,
  content VARCHAR(400) NOT NULL,
  stars TINYINT UNSIGNED NOT NULL DEFAULT 5,
  active BOOLEAN NOT NULL DEFAULT TRUE,
  CONSTRAINT chk_stars CHECK (stars BETWEEN 1 AND 5)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  fingerprint CHAR(64) PRIMARY KEY,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_attempt TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
