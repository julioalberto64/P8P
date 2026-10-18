-- ===================================================
-- Esquema de Base de Datos para P8P Workflow Engine
-- Archivo: database/schema.sql
-- Compatible con: MariaDB 10.2+ / MySQL 8.0+
-- ===================================================

-- 1. Tabla para almacenar la definición y el lienzo de los Workflows
CREATE TABLE IF NOT EXISTS workflows (
                                         id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    structure JSON NOT NULL, -- Matriz de nodos y conexiones enviadas desde React Flow
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabla para la cola de ejecuciones asíncronas en segundo plano
CREATE TABLE IF NOT EXISTS queue_jobs (
    id VARCHAR(36) NOT NULL,
    workflow_id VARCHAR(36) NULL,
    payload LONGTEXT NOT NULL,
    status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    result LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabla para almacenar credenciales encriptadas (API Keys, OAuth Tokens, Basic Auth)
CREATE TABLE IF NOT EXISTS credentials (
                                           id VARCHAR(36) NOT NULL,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(100) NOT NULL,    -- ej: 'openai_api', 'bearer_token', 'header_auth'
    data TEXT NOT NULL,             -- JSON encriptado en AES-256-GCM
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
