-- ============================================================
-- C Tech Fix CRM — Complete Database Schema
-- Version: Phase 0
-- Run this once in cPanel > phpMyAdmin > Import
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================================
-- 1. LOCATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `locations` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`       VARCHAR(10)  NOT NULL UNIQUE,        -- OS, PF
  `name`       VARCHAR(100) NOT NULL,               -- Oshawa, Pickering
  `did`        VARCHAR(20)  NOT NULL,               -- 905-233-2596
  `address`    VARCHAR(255) DEFAULT NULL,
  `phone`      VARCHAR(20)  DEFAULT NULL,
  `email`      VARCHAR(100) DEFAULT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `locations` (`code`, `name`, `did`) VALUES
  ('OS', 'Oshawa',    '905-233-2596'),
  ('PF', 'Pickering', '905-752-0343');

-- ============================================================
-- 2. ROLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(50) NOT NULL UNIQUE,   -- Owner, Manager, Staff
  `label`       VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `roles` (`name`, `label`, `description`) VALUES
  ('owner',   'Owner',   'Full access to everything. No restrictions.'),
  ('manager', 'Manager', 'Both locations. All operational modules. Limited admin.'),
  ('staff',   'Staff',   'Assigned location only. Day-to-day operations.');

-- ============================================================
-- 3. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`            VARCHAR(50)  NOT NULL UNIQUE,
  `password_hash`       VARCHAR(255) NOT NULL,
  `first_name`          VARCHAR(100) NOT NULL,
  `last_name`           VARCHAR(100) NOT NULL,
  `email`               VARCHAR(150) DEFAULT NULL,
  `phone`               VARCHAR(20)  DEFAULT NULL,
  `role_id`             INT UNSIGNED NOT NULL,
  `primary_location_id` INT UNSIGNED DEFAULT NULL,   -- NULL = all locations (Owner/Manager)
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `is_training`         TINYINT(1)   NOT NULL DEFAULT 0,  -- training mode flag
  `last_login_at`       DATETIME     DEFAULT NULL,
  `created_by`          INT UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`),
  FOREIGN KEY (`primary_location_id`) REFERENCES `locations`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Owner account: athavan / 324973130Aa@
INSERT INTO `users` (`username`, `password_hash`, `first_name`, `last_name`, `role_id`, `primary_location_id`, `is_active`)
VALUES (
  'athavan',
  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uDutXYsiO',
  'Athavan',
  'Mahendrakumar',
  (SELECT `id` FROM `roles` WHERE `name` = 'owner'),
  NULL,
  1
);
-- NOTE: The password hash above is a placeholder. The install script will set the real hash.
-- Real password will be hashed by install.php on first run.

-- ============================================================
-- 4. USER LOCATION ACCESS (for staff assigned to multiple locations)
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_locations` (
  `user_id`     INT UNSIGNED NOT NULL,
  `location_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `location_id`),
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. SESSIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`         INT UNSIGNED NOT NULL,
  `session_token`   VARCHAR(128) NOT NULL UNIQUE,
  `ip_address`      VARCHAR(45)  DEFAULT NULL,
  `user_agent`      VARCHAR(255) DEFAULT NULL,
  `is_training`     TINYINT(1)   NOT NULL DEFAULT 0,
  `last_active_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`      DATETIME     NOT NULL,
  `is_revoked`      TINYINT(1)   NOT NULL DEFAULT 0,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. LOGIN ATTEMPT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`    VARCHAR(50)  NOT NULL,
  `ip_address`  VARCHAR(45)  DEFAULT NULL,
  `user_agent`  VARCHAR(255) DEFAULT NULL,
  `success`     TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 7. AUDIT LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_log` (
  `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`     INT UNSIGNED  DEFAULT NULL,
  `location_id` INT UNSIGNED  DEFAULT NULL,
  `action`      VARCHAR(100)  NOT NULL,      -- e.g. user.created, repair.status_changed
  `module`      VARCHAR(50)   NOT NULL,      -- users, repairs, inventory, cash, etc.
  `record_id`   INT UNSIGNED  DEFAULT NULL,  -- ID of the affected record
  `old_value`   JSON          DEFAULT NULL,
  `new_value`   JSON          DEFAULT NULL,
  `notes`       TEXT          DEFAULT NULL,
  `ip_address`  VARCHAR(45)   DEFAULT NULL,
  `is_training` TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 8. RECORD NUMBER SEQUENCES
-- ============================================================
CREATE TABLE IF NOT EXISTS `record_sequences` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `type`         VARCHAR(20)  NOT NULL,   -- REPAIR, SALE, PO, TRANSFER, ACT, INC, CMP, LOAN
  `location_code` VARCHAR(10) DEFAULT NULL,  -- OS, PF, or NULL for company-wide
  `year`         YEAR        NOT NULL,
  `last_seq`     INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_sequence` (`type`, `location_code`, `year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Pre-seed sequences for 2026
INSERT INTO `record_sequences` (`type`, `location_code`, `year`) VALUES
  ('REPAIR',   'OS', 2026),
  ('REPAIR',   'PF', 2026),
  ('SALE',     'OS', 2026),
  ('SALE',     'PF', 2026),
  ('PO',       NULL, 2026),
  ('TRANSFER', NULL, 2026),
  ('ACT',      NULL, 2026),
  ('INC',      NULL, 2026),
  ('CMP',      NULL, 2026),
  ('LOAN',     NULL, 2026);

-- ============================================================
-- 9. SYSTEM SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`         VARCHAR(100) NOT NULL UNIQUE,
  `value`       TEXT         DEFAULT NULL,
  `label`       VARCHAR(150) DEFAULT NULL,   -- human-readable label
  `group`       VARCHAR(50)  DEFAULT NULL,   -- general, voip, sms, cash, etc.
  `updated_by`  INT UNSIGNED DEFAULT NULL,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `settings` (`key`, `value`, `label`, `group`) VALUES
  ('app_name',                'C Tech Fix CRM',   'Application Name',             'general'),
  ('app_timezone',            'America/Toronto',   'Timezone',                     'general'),
  ('call_cost_default',       '5.00',              'Default Call Cost ($)',         'calls'),
  ('callback_slo_minutes',    '30',                'Missed Call Callback SLO (min)','calls'),
  ('callback_max_attempts',   '3',                 'Max Callback Attempts',         'calls'),
  ('callback_attempt_days',   '2',                 'Callback Window (business days)','calls'),
  ('cash_variance_threshold', '5.00',              'Cash Variance Alert Threshold ($)','cash'),
  ('expense_threshold_manager','50.00',            'Expense Manager Approval Limit ($)','cash'),
  ('expense_threshold_owner', '50.01',             'Expense Owner Approval Limit ($)', 'cash'),
  ('store_credit_expiry_days','30',                'Store Credit Expiry (days)',    'sales'),
  ('reservation_deposit_pct', '50',                'Reservation Deposit %',         'sales'),
  ('reservation_hold_days',   '7',                 'Reservation Hold Period (days)','sales'),
  ('abandonment_day_schedule','0,2,5,10,15',       'Abandonment SMS Days',          'repairs'),
  ('diagnostic_fee',          '40.00',             'Diagnostic Fee ($)',             'repairs'),
  ('voipms_api_username',     '',                  'VoIP.ms API Username',          'voip'),
  ('voipms_api_password',     '',                  'VoIP.ms API Password',          'voip'),
  ('owner_sms_number',        '',                  'Owner Mobile (for SMS alerts)', 'general'),
  ('session_timeout_hours',   '8',                 'Session Timeout (hours)',        'general');

-- ============================================================
-- 10. STORE HOURS
-- ============================================================
CREATE TABLE IF NOT EXISTS `store_hours` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id` INT UNSIGNED NOT NULL,
  `day_of_week` TINYINT(1)   NOT NULL,   -- 0=Sunday, 1=Monday ... 6=Saturday
  `open_time`   TIME         DEFAULT NULL,
  `close_time`  TIME         DEFAULT NULL,
  `is_closed`   TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_store_hours` (`location_id`, `day_of_week`),
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default hours: Mon-Sat 10am-7pm, Sunday closed — Owner can change in settings
INSERT INTO `store_hours` (`location_id`, `day_of_week`, `open_time`, `close_time`, `is_closed`)
SELECT l.id, d.day, '10:00:00', '19:00:00', IF(d.day = 0, 1, 0)
FROM `locations` l
JOIN (SELECT 0 AS day UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6) d;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE — All Phase 0 tables created.
-- ============================================================
