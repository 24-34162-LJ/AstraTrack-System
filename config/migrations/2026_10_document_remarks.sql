-- 2026_10_document_remarks.sql
-- Shipment Details: each uploaded document can carry a short remark written by the uploader.
-- Safe to run more than once (MariaDB 10.3+; on MySQL 5.7/8 run the ALTER inside the
-- COLUMN check below by hand if the column already exists).

ALTER TABLE shipment_documents
    ADD COLUMN IF NOT EXISTS remarks VARCHAR(255) NULL AFTER file_path;

-- Optional: make "newest first" lookups on the Documents card cheaper on large installs.
-- (The foreign key already provides an index on shipment_id, so this is normally not needed.)
-- ALTER TABLE shipment_documents ADD INDEX idx_doc_ship_uploaded (shipment_id, uploaded_at);
