-- 1) Orders (ถ้ายังไม่มีให้สร้าง)
CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  session_id CHAR(32) NOT NULL UNIQUE,
  citizen_id VARCHAR(20) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  vin VARCHAR(50) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  brand VARCHAR(50) NOT NULL,
  desired_plates JSON NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1.1) เพิ่มคอลัมน์เสริมแบบ idempotent
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER phone;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL DEFAULT NULL
  ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- 1.2) ดัชนี (มีอยู่แล้วจะข้าม)
ALTER TABLE orders
  ADD INDEX IF NOT EXISTS idx_orders_session (session_id);

ALTER TABLE orders
  ADD INDEX IF NOT EXISTS idx_orders_created (created_at);

-- 2) Admin users (สำหรับหน้า login)
CREATE TABLE IF NOT EXISTS admin_users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
