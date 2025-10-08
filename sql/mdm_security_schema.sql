-- MDM Security and Authentication Schema

-- Users table for authentication
CREATE TABLE mdm_users (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(200),
    status ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Roles table for authorization
CREATE TABLE mdm_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    description TEXT,
    permissions JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User roles mapping
CREATE TABLE mdm_user_roles (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    role_id INT NOT NULL,
    assigned_by BIGINT,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES mdm_users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES mdm_roles(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES mdm_users(id),
    UNIQUE KEY unique_user_role (user_id, role_id)
);

-- Sessions table for session management
CREATE TABLE mdm_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id BIGINT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES mdm_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires_at (expires_at)
);

-- User activity log for audit
CREATE TABLE mdm_user_activity_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT,
    session_id VARCHAR(128),
    action VARCHAR(100) NOT NULL,
    resource VARCHAR(200),
    resource_id VARCHAR(100),
    details JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES mdm_users(id) ON DELETE SET NULL,
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    INDEX idx_resource (resource, resource_id)
);

-- Insert default roles
INSERT INTO mdm_roles (role_name, display_name, description, permissions) VALUES
('data_admin', 'Data Administrator', 'Full access to all MDM system functions', JSON_ARRAY(
    'users.manage', 'roles.manage', 'products.create', 'products.edit', 'products.delete',
    'verification.approve', 'verification.reject', 'reports.view', 'reports.export',
    'system.configure', 'audit.view', 'backup.manage'
)),
('data_manager', 'Data Manager', 'Manage master data and verification processes', JSON_ARRAY(
    'products.create', 'products.edit', 'verification.approve', 'verification.reject',
    'reports.view', 'reports.export', 'audit.view'
)),
('data_analyst', 'Data Analyst', 'Read-only access to data and reports', JSON_ARRAY(
    'products.view', 'reports.view', 'reports.export'
)),
('system_user', 'System User', 'API access for system integrations', JSON_ARRAY(
    'api.read', 'api.write'
));

-- Create default admin user (password: admin123 - should be changed in production)
INSERT INTO mdm_users (username, email, password_hash, full_name, status) VALUES
('admin', 'admin@company.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'active');

-- Assign admin role to default user
INSERT INTO mdm_user_roles (user_id, role_id, assigned_by) VALUES
(1, 1, 1);