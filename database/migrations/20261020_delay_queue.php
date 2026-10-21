<?php

// Actualización aditiva e idempotente. Nunca se ejecuta desde el instalador ni un endpoint.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}
if (!in_array('--apply', $argv, true)) {
    echo "Detén todos los workers y las entradas que encolan trabajos; respalda la BD.\n";
    echo "Después ejecuta: php database/migrations/20261020_delay_queue.php --apply\n";
    echo "Sin --apply no se abre conexión ni se modifica la BD.\n";
    exit(0);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$db = null;
$locked = false;
$exitCode = 0;
try {
    $db = \P8P\Core\Database::getConnection(require dirname(__DIR__, 2) . '/config/database.php');
    $locked = (int) $db->query("SELECT GET_LOCK('p8p_delay_queue_migration', 0)")->fetchColumn() === 1;
    if (!$locked) {
        throw new RuntimeException('Otra actualización está en curso.');
    }
    if ((int) $db->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'processing'")->fetchColumn() !== 0) {
        throw new RuntimeException('Hay trabajos processing. Revisa sus efectos y detén los workers antes de actualizar.');
    }
    foreach (['available_at' => 'DATETIME NULL', 'claim_token' => 'VARCHAR(64) NULL'] as $column => $definition) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([':table_name' => 'queue_jobs', ':column_name' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $db->exec("ALTER TABLE queue_jobs ADD COLUMN {$column} {$definition}");
        }
    }
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'queue_jobs' AND INDEX_NAME = 'idx_queue_available'");
    if ((int) $stmt->fetchColumn() === 0) {
        $db->exec('ALTER TABLE queue_jobs ADD INDEX idx_queue_available (status, available_at, created_at)');
    }
    echo "Cola actualizada. No se borraron ni procesaron trabajos. Ya puedes reactivar las entradas y los workers.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "No se completó la actualización. Revisa el esquema y los trabajos con los workers detenidos; el comando admite repetición.\n");
    $exitCode = 1;
} finally {
    if ($locked) {
        $db->query("SELECT RELEASE_LOCK('p8p_delay_queue_migration')");
    }
}
exit($exitCode);
