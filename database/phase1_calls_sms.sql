-- ============================================================
-- C Tech Fix CRM — Phase 1: Calls & SMS Tables
-- Import this in cPanel > phpMyAdmin > Import
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. CUSTOMERS (needed before calls/SMS can reference them)
-- ============================================================
CREATE TABLE IF NOT EXISTS `customers` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `first_name`          VARCHAR(100) NOT NULL,
  `last_name`           VARCHAR(100) DEFAULT NULL,
  `company`             VARCHAR(150) DEFAULT NULL,
  `email`               VARCHAR(150) DEFAULT NULL,
  `phone_primary`       VARCHAR(20)  NOT NULL,
  `phone_secondary`     VARCHAR(20)  DEFAULT NULL,
  `phone_normalized`    VARCHAR(15)  NOT NULL,   -- 10-digit, no formatting
  `location_id`         INT UNSIGNED DEFAULT NULL,  -- home location
  `do_not_call`         TINYINT(1)   NOT NULL DEFAULT 0,
  `do_not_sms`          TINYINT(1)   NOT NULL DEFAULT 0,
  `no_marketing`        TINYINT(1)   NOT NULL DEFAULT 0,
  `notes`               TEXT         DEFAULT NULL,
  `is_active`           TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`          INT UNSIGNED DEFAULT NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_phone_normalized` (`phone_normalized`),
  INDEX `idx_phone_primary`    (`phone_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 2. CALL LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `call_logs` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `voipms_id`       VARCHAR(50)  DEFAULT NULL UNIQUE,  -- VoIP.ms call ID (prevents duplicates)
  `location_id`     INT UNSIGNED NOT NULL,
  `customer_id`     INT UNSIGNED DEFAULT NULL,
  `direction`       ENUM('inbound','outbound') NOT NULL,
  `did`             VARCHAR(20)  NOT NULL,              -- which DID received/made the call
  `caller_number`   VARCHAR(20)  NOT NULL,              -- normalized 10-digit
  `caller_name`     VARCHAR(100) DEFAULT NULL,
  `duration_seconds`INT UNSIGNED NOT NULL DEFAULT 0,
  `status`          ENUM('answered','missed','voicemail','busy','failed') NOT NULL DEFAULT 'answered',
  `call_at`         DATETIME     NOT NULL,
  -- Classification (filled in by staff after the call)
  `classification`  ENUM(
                      'new_repair_lead',
                      'existing_repair_status',
                      'product_sales_inquiry',
                      'wireless_activation_inquiry',
                      'warranty_complaint',
                      'supplier_general',
                      'wrong_number_spam',
                      'unclassified'
                    ) NOT NULL DEFAULT 'unclassified',
  `reason`          VARCHAR(255) DEFAULT NULL,
  `summary`         TEXT         DEFAULT NULL,
  `quoted_amount`   DECIMAL(8,2) DEFAULT NULL,
  `outcome`         VARCHAR(255) DEFAULT NULL,
  `next_action`     VARCHAR(255) DEFAULT NULL,
  `classified_by`   INT UNSIGNED DEFAULT NULL,
  `classified_at`   DATETIME     DEFAULT NULL,
  -- Callback tracking
  `needs_callback`  TINYINT(1)   NOT NULL DEFAULT 0,
  `callback_status` ENUM('pending','in_progress','completed','no_response','cancelled') DEFAULT NULL,
  `callback_due_at` DATETIME     DEFAULT NULL,
  `callback_attempts` TINYINT(1) NOT NULL DEFAULT 0,
  `callback_completed_at` DATETIME DEFAULT NULL,
  -- Meta
  `is_training`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`)   REFERENCES `locations`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`customer_id`)   REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`classified_by`) REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_call_at`           (`call_at`),
  INDEX `idx_caller_number`     (`caller_number`),
  INDEX `idx_status`            (`status`),
  INDEX `idx_needs_callback`    (`needs_callback`, `callback_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 3. CALLBACK ATTEMPTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `callback_attempts` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `call_log_id` INT UNSIGNED NOT NULL,
  `attempted_by`INT UNSIGNED NOT NULL,
  `attempted_at`DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `outcome`     ENUM('reached','no_answer','voicemail','busy','wrong_number') NOT NULL,
  `notes`       TEXT         DEFAULT NULL,
  FOREIGN KEY (`call_log_id`)  REFERENCES `call_logs`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`attempted_by`) REFERENCES `users`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 4. SMS MESSAGES
-- ============================================================
CREATE TABLE IF NOT EXISTS `sms_messages` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `voipms_id`     VARCHAR(50)  DEFAULT NULL UNIQUE,   -- prevents duplicate inbound
  `location_id`   INT UNSIGNED NOT NULL,
  `customer_id`   INT UNSIGNED DEFAULT NULL,
  `direction`     ENUM('inbound','outbound') NOT NULL,
  `did`           VARCHAR(20)  NOT NULL,               -- store DID used
  `contact_number`VARCHAR(20)  NOT NULL,               -- normalized customer number
  `message`       TEXT         NOT NULL,
  `status`        ENUM('sent','delivered','failed','received') NOT NULL DEFAULT 'received',
  `error_message` VARCHAR(255) DEFAULT NULL,           -- if failed
  `retry_count`   TINYINT(1)   NOT NULL DEFAULT 0,
  `sent_manually` TINYINT(1)   NOT NULL DEFAULT 0,     -- marked sent manually after failure
  `needs_reply`   TINYINT(1)   NOT NULL DEFAULT 0,     -- inbound that needs follow-up
  `replied_at`    DATETIME     DEFAULT NULL,
  `sent_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_by`       INT UNSIGNED DEFAULT NULL,           -- NULL = system/inbound
  `is_training`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`sent_by`)     REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_contact_number`  (`contact_number`),
  INDEX `idx_status`          (`status`),
  INDEX `idx_needs_reply`     (`needs_reply`),
  INDEX `idx_sent_at`         (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 5. FOLLOW-UPS
-- ============================================================
CREATE TABLE IF NOT EXISTS `follow_ups` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id`   INT UNSIGNED NOT NULL,
  `customer_id`   INT UNSIGNED DEFAULT NULL,
  `call_log_id`   INT UNSIGNED DEFAULT NULL,
  `sms_id`        INT UNSIGNED DEFAULT NULL,
  `assigned_to`   INT UNSIGNED DEFAULT NULL,
  `type`          ENUM('callback','sms_reply','general','appointment','estimate') NOT NULL DEFAULT 'general',
  `priority`      ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  `title`         VARCHAR(255) NOT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `due_at`        DATETIME     NOT NULL,
  `status`        ENUM('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `completed_at`  DATETIME     DEFAULT NULL,
  `completed_by`  INT UNSIGNED DEFAULT NULL,
  `resolution`    TEXT         DEFAULT NULL,
  `is_training`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`call_log_id`) REFERENCES `call_logs`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`sms_id`)      REFERENCES `sms_messages`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`completed_by`)REFERENCES `users`(`id`)     ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL,
  INDEX `idx_due_at`          (`due_at`),
  INDEX `idx_status`          (`status`),
  INDEX `idx_assigned_to`     (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- 6. MANUAL COMMUNICATIONS LOG
-- ============================================================
CREATE TABLE IF NOT EXISTS `manual_comms` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `location_id`   INT UNSIGNED NOT NULL,
  `customer_id`   INT UNSIGNED DEFAULT NULL,
  `call_log_id`   INT UNSIGNED DEFAULT NULL,
  `method`        ENUM('whatsapp','email','in_person','other_phone','facebook_messenger','other') NOT NULL,
  `direction`     ENUM('inbound','outbound') NOT NULL,
  `summary`       TEXT         NOT NULL,
  `outcome`       VARCHAR(255) DEFAULT NULL,
  `next_action`   VARCHAR(255) DEFAULT NULL,
  `comm_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_training`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`location_id`) REFERENCES `locations`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`call_log_id`) REFERENCES `call_logs`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE — Phase 1 tables created.
-- ============================================================
