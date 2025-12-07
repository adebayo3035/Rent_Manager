CREATE TABLE tenant_customers (
    id INT(11) NOT NULL AUTO_INCREMENT,
    tenant_id VARCHAR(255) NOT NULL UNIQUE,
    firstname VARCHAR(255) NOT NULL,
    lastname VARCHAR(255) NOT NULL,
    gender VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    mobile_number VARCHAR(20) NOT NULL,
    address VARCHAR(255) NOT NULL,
    secret_question VARCHAR(255) NOT NULL,
    secret_answer VARCHAR(255) NOT NULL,
    photo VARCHAR(255) DEFAULT NULL,

    -- Property management fields
    property_id INT(11) NOT NULL,
    unit_id INT(11) NOT NULL,
    tenant_status VARCHAR(20) NOT NULL DEFAULT 'active',

    lease_start DATE DEFAULT NULL,
    lease_end DATE DEFAULT NULL,
    rent_amount DECIMAL(12,2) DEFAULT NULL,
    security_deposit DECIMAL(12,2) DEFAULT NULL,
    next_rent_due DATE DEFAULT NULL,

    restriction TINYINT(1) NOT NULL DEFAULT 0,
    delete_status VARCHAR(11) DEFAULT NULL,
    date_created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    INDEX (tenant_id),
    INDEX (email),
    INDEX (property_id),
    INDEX (unit_id)
);
