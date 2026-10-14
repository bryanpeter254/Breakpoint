USE breakpoint;

-- Conversation state per WhatsApp number
CREATE TABLE whatsapp_sessions (
  phone VARCHAR(12) PRIMARY KEY,                       -- 2547XXXXXXXX (matches users.phone)
  state ENUM('consent','complaint','interview','done') NOT NULL DEFAULT 'consent',
  transcript JSON NULL,
  questions_asked TINYINT NOT NULL DEFAULT 0,
  last_msg_id VARCHAR(120) NULL,                       -- de-duplicates Meta webhook retries
  consultation_id INT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (consultation_id) REFERENCES consultations(id)
);

-- Where the consultation came from
ALTER TABLE consultations ADD COLUMN source ENUM('web','whatsapp') NOT NULL DEFAULT 'web';
