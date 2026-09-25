-- Run once on the Oracle 11g database. VARCHAR2 keeps dates independent of
-- the session NLS_DATE_FORMAT and CLOB preserves the full NIC response/QR code.
ALTER TABLE docinvmas ADD (
	einv_irn VARCHAR2(64),
	einv_ack_no VARCHAR2(32),
	einv_ack_date VARCHAR2(32),
	einv_qr_code CLOB,
	eway_bill_no VARCHAR2(32),
	eway_bill_date VARCHAR2(32),
	eway_valid_upto VARCHAR2(32),
	einv_status VARCHAR2(20),
	einv_response CLOB,
	einv_updated_at VARCHAR2(32)
);
