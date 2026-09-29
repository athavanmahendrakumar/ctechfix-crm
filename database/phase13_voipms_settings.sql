-- ============================================================
-- Phase 13: Move VoipMS credentials to settings table
-- Run once via phpMyAdmin → Import
-- ============================================================

-- Seed VoipMS API credentials as global settings (location_id IS NULL)
-- updated_by = 1 (owner account)
-- ON DUPLICATE KEY UPDATE means safe to re-run
INSERT INTO settings (setting_key, location_id, value, updated_by)
VALUES
    ('voipms_api_username', NULL, 'athavan.mahen@hotmail.com', 1),
    ('voipms_api_password', NULL, '-kH.a98d1azfScY4]umd1{%YR&hN7wiQq_2TLZ81FQb', 1)
ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = 1;

-- Confirm seeded rows
SELECT setting_key,
       CASE setting_key WHEN 'voipms_api_password' THEN '••••••••' ELSE value END AS value,
       location_id
FROM settings
WHERE setting_key IN ('voipms_api_username', 'voipms_api_password');
