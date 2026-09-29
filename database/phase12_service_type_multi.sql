-- Phase 12: Allow multiple service types per repair
ALTER TABLE repairs MODIFY COLUMN service_type TEXT NULL;
