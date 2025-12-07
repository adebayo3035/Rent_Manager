CREATE TABLE units (
id INT(11) NOT NULL AUTO_INCREMENT,
unit_code VARCHAR(100) NOT NULL,
property_id INT(11) NOT NULL,
agent_id INT(11) NOT NULL,
floor VARCHAR(50) DEFAULT NULL,
unit_type VARCHAR(50) DEFAULT 'apartment', -- e.g., studio, 1bed, 2bed
rent_amount DECIMAL(12,2) DEFAULT NULL,
available TINYINT(1) NOT NULL DEFAULT 1,
notes TEXT DEFAULT NULL,
created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
PRIMARY KEY (id),
INDEX (property_id),
UNIQUE KEY property_unit_unique (property_id, unit_code),
CONSTRAINT fk_units_property FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE
);