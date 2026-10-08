-- AHPC BOM & Quotation Management System - MySQL / MariaDB schema.
-- The app creates these tables by itself on first start; this file can also be
-- imported by hand (phpMyAdmin -> Import) into an empty database.

CREATE TABLE IF NOT EXISTS app_meta (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    role VARCHAR(20) NOT NULL,
    email VARCHAR(190),
    password_hash VARCHAR(255),
    active TINYINT(1) DEFAULT 1,
    KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remarks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(10) NOT NULL,          -- req / bom / quote
    item_id INT NOT NULL,
    author_id INT,
    author_name VARCHAR(120),
    author_role VARCHAR(20),
    rtype VARCHAR(20) DEFAULT 'REMARK', -- REMARK / DELAY / ASSIGNMENT / SYSTEM
    body TEXT,
    created_at DATETIME,
    KEY idx_remarks_item (kind, item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_at DATETIME,
    event VARCHAR(40),
    ref VARCHAR(100),
    recipients TEXT,
    subject TEXT,
    status VARCHAR(20),
    error TEXT,
    KEY idx_email_log_event (event, ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company VARCHAR(255) NOT NULL,
    contact_person VARCHAR(255),
    email VARCHAR(255),
    phone VARCHAR(100),
    address TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    req_number VARCHAR(40) UNIQUE,
    customer_id INT,
    sales_person VARCHAR(120),
    req_date DATE NULL,
    expected_delivery DATE NULL,
    title TEXT,
    description TEXT,
    status VARCHAR(30) DEFAULT 'OPEN',
    created_at DATETIME,
    remarks TEXT,
    assigned_to INT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NULL,
    due_date DATE NULL,
    CONSTRAINT fk_req_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS components (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(190) NOT NULL,
    manufacturer VARCHAR(255),
    part_number TEXT,
    description TEXT,
    supplier VARCHAR(255),
    cost DECIMAL(14,2) DEFAULT 0,
    selling_price DECIMAL(14,2) DEFAULT 0,
    stock INT DEFAULT 0,
    KEY idx_components_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS boms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bom_number VARCHAR(40),
    version INT DEFAULT 1,
    parent_bom_id INT,
    requirement_id INT,
    customer_id INT,
    title TEXT,
    status VARCHAR(30) DEFAULT 'DRAFT',
    pricing_mode VARCHAR(20) DEFAULT 'AUTOMATIC',
    margin_percent DECIMAL(7,2) DEFAULT 15,
    gst_percent DECIMAL(7,2) DEFAULT 18,
    review_comment TEXT,
    created_by VARCHAR(120),
    created_at DATETIME,
    remarks TEXT,
    assigned_to INT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NULL,
    due_date DATE NULL,
    KEY idx_boms_number (bom_number, version),
    CONSTRAINT fk_bom_requirement FOREIGN KEY (requirement_id) REFERENCES requirements(id),
    CONSTRAINT fk_bom_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bom_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bom_id INT NOT NULL,
    component_id INT,
    category VARCHAR(190),
    manufacturer VARCHAR(255),
    part_number TEXT,
    description TEXT,
    quantity INT DEFAULT 1,
    unit_price DECIMAL(14,2) DEFAULT 0,
    CONSTRAINT fk_item_bom FOREIGN KEY (bom_id) REFERENCES boms(id),
    CONSTRAINT fk_item_component FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quotations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_number VARCHAR(60) UNIQUE,
    bom_id INT,
    customer_id INT,
    subtotal DECIMAL(16,2),
    margin_amount DECIMAL(16,2),
    gst_amount DECIMAL(16,2),
    grand_total DECIMAL(16,2),
    terms TEXT,
    status VARCHAR(30) DEFAULT 'DRAFT',
    created_at DATETIME,
    remarks TEXT,
    to_company VARCHAR(255),
    to_address TEXT,
    subject TEXT,
    specs TEXT,
    total_price DECIMAL(16,2),
    gst_percent DECIMAL(7,2),
    quote_date DATE NULL,
    terms_json TEXT,
    updated_at DATETIME,
    assigned_to INT NULL,
    assigned_by INT NULL,
    assigned_at DATETIME NULL,
    due_date DATE NULL,
    CONSTRAINT fk_quote_bom FOREIGN KEY (bom_id) REFERENCES boms(id),
    CONSTRAINT fk_quote_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(40) UNIQUE,
    quote_id INT,
    po_number VARCHAR(100),
    status VARCHAR(30) DEFAULT 'AWAITING_PO',
    delivery_date DATE NULL,
    remarks TEXT,
    created_at DATETIME,
    CONSTRAINT fk_order_quote FOREIGN KEY (quote_id) REFERENCES quotations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS procurement (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT,
    component_id INT,
    description TEXT,
    required_qty DECIMAL(12,2),
    stock_qty DECIMAL(12,2),
    shortage DECIMAL(12,2),
    status VARCHAR(30) DEFAULT 'PENDING',
    CONSTRAINT fk_proc_order FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_proc_component FOREIGN KEY (component_id) REFERENCES components(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
