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
    workflow_id VARCHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, processing, completed, failed
    payload JSON DEFAULT NULL,                     -- Datos de contexto enviados al trabajo
    error_message TEXT DEFAULT NULL,
    attempts INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_queue_status (status),
    KEY idx_queue_workflow_id (workflow_id),
    CONSTRAINT fk_queue_workflows FOREIGN KEY (workflow_id) REFERENCES workflows (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
