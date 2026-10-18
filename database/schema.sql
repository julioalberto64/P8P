-- Tabla para guardar los flujos creados en el frontend
CREATE TABLE IF NOT EXISTS workflows (
                                         id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    structure JSON NOT NULL, -- Guarda el JSON del mapa de React Flow
    is_active TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

-- Tabla de colas para ejecuciones en segundo plano
CREATE TABLE IF NOT EXISTS queue_jobs (
                                          id VARCHAR(36) PRIMARY KEY,
    workflow_id VARCHAR(36) NOT NULL,
    status VARCHAR(20) DEFAULT 'pending', -- pending, processing, completed, failed
    payload JSON,
    error_message TEXT,
    attempts INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    executed_at DATETIME NULL
    );
