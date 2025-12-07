CREATE TABLE rent_payments (
id INT(11) NOT NULL AUTO_INCREMENT,
payment_ref VARCHAR(150) NOT NULL UNIQUE,
tenant_id VARCHAR(255) NOT NULL, -- references tenant_customers.tenant_id
property_id INT(11) NOT NULL,
unit_id INT(11) NOT NULL,
amount DECIMAL(12,2) NOT NULL,
currency VARCHAR(10) DEFAULT 'NGN',
paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
payment_method VARCHAR(50) DEFAULT 'card', -- card, bank_transfer, cash, etc.
status VARCHAR(20) DEFAULT 'completed', -- pending, completed, failed, refunded
note TEXT DEFAULT NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id),
INDEX (tenant_id),
INDEX (property_id),
INDEX (unit_id),
CONSTRAINT fk_rentpayments_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
CONSTRAINT fk_rentpayments_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE CASCADE
);