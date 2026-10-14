-- Drop the old 'breakpoint' database first (the structure has changed completely).
CREATE DATABASE IF NOT EXISTS breakpoint CHARACTER SET utf8mb4;
USE breakpoint;

-- 1. Everyone who logs in
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(12) NOT NULL UNIQUE,            -- 2547XXXXXXXX, used for login + M-Pesa
  role ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Patient profile (1:1 with users)
CREATE TABLE patients (
  id INT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  id_number VARCHAR(20) NOT NULL UNIQUE,
  dob DATE NOT NULL,
  gender ENUM('male','female','other') NOT NULL,
  county VARCHAR(60) NOT NULL,
  village VARCHAR(80) NULL,
  chronic_conditions JSON NULL,                 -- ["asthma","diabetes"]
  allergies TEXT NULL,
  FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. Doctor profile (1:1 with users)
CREATE TABLE doctors (
  id INT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  kmpdc_number VARCHAR(30) NOT NULL UNIQUE,     -- required by law
  specialty VARCHAR(80) NOT NULL DEFAULT 'General',
  license_doc_url VARCHAR(255) NULL,
  is_available BOOLEAN NOT NULL DEFAULT FALSE,
  rating DECIMAL(2,1) NOT NULL DEFAULT 0.0,
  FOREIGN KEY (id) REFERENCES users(id) ON DELETE CASCADE
);

-- Needed because prescriptions.pharmacy_id is a FK
CREATE TABLE pharmacies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  location VARCHAR(150) NULL
);

-- 4. THE CORE TABLE
CREATE TABLE consultations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  patient_id INT NOT NULL,
  doctor_id INT NULL,                           -- null until assigned
  status ENUM('waiting_triage','waiting_doctor','in_progress','completed','emergency') NOT NULL DEFAULT 'waiting_triage',
  chief_complaint VARCHAR(255) NOT NULL,
  triage_level ENUM('RED','YELLOW','GREEN') NULL,
  triage_notes JSON NULL,
  consult_type ENUM('video','audio','chat') NOT NULL DEFAULT 'video',
  agora_channel_id VARCHAR(64) NULL,
  started_at DATETIME NULL,
  ended_at DATETIME NULL,
  consultation_fee DECIMAL(10,2) NOT NULL DEFAULT 300,
  payment_status ENUM('unpaid','paid_via_mpesa') NOT NULL DEFAULT 'unpaid',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (patient_id) REFERENCES patients(id),
  FOREIGN KEY (doctor_id) REFERENCES doctors(id)
);

-- 5. SOAP notes (1:1 with consultations)
CREATE TABLE consultation_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consultation_id INT NOT NULL UNIQUE,
  subjective TEXT NULL,
  objective TEXT NULL,
  assessment TEXT NULL,
  `plan` TEXT NULL,
  created_by_doctor_id INT NOT NULL,
  FOREIGN KEY (consultation_id) REFERENCES consultations(id),
  FOREIGN KEY (created_by_doctor_id) REFERENCES doctors(id)
);

-- 7. Prescriptions (1:N with consultations)
CREATE TABLE prescriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consultation_id INT NOT NULL,
  medicine_name VARCHAR(150) NOT NULL,
  dosage VARCHAR(100) NOT NULL,
  frequency VARCHAR(100) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  instructions_swahili TEXT NULL,
  is_dispensed BOOLEAN NOT NULL DEFAULT FALSE,
  pharmacy_id INT NULL,
  FOREIGN KEY (consultation_id) REFERENCES consultations(id),
  FOREIGN KEY (pharmacy_id) REFERENCES pharmacies(id)
);

-- 8. Lab requests + results
CREATE TABLE lab_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consultation_id INT NOT NULL,
  test_name VARCHAR(120) NOT NULL,
  status ENUM('requested','completed') NOT NULL DEFAULT 'requested',
  FOREIGN KEY (consultation_id) REFERENCES consultations(id)
);

CREATE TABLE lab_results (
  id INT AUTO_INCREMENT PRIMARY KEY,
  lab_request_id INT NOT NULL UNIQUE,
  result_value TEXT NULL,
  result_pdf_url VARCHAR(255) NULL,
  uploaded_by INT NOT NULL,
  FOREIGN KEY (lab_request_id) REFERENCES lab_requests(id),
  FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- 9. Payments
CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  consultation_id INT NOT NULL,
  mpesa_code VARCHAR(15) NOT NULL UNIQUE,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','confirmed','failed') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (consultation_id) REFERENCES consultations(id)
);
