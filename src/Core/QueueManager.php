<?php

namespace P8P\Core;

use P8P\Core\Database;
use PDO;
use Exception;

class QueueManager
{
    private PDO $db;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/database.php';
        $this->db = Database::getConnection($config);
    }

    /**
     * Encola un nuevo trabajo de ejecución
     */
    public function enqueue(string $workflowId, array $payload): string
    {
        $jobId = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );

        $stmt = $this->db->prepare("
            INSERT INTO queue_jobs (id, workflow_id, payload, status)
            VALUES (:id, :workflow_id, :payload, 'pending')
        ");

        $stmt->execute([
            ':id' => $jobId,
            ':workflow_id' => $workflowId,
            ':payload' => json_encode($payload)
        ]);

        return $jobId;
    }

    /**
     * Toma los siguientes N trabajos en estado 'pending' marcándolos como 'processing'
     */
    public function getPendingJobs(int $limit = 5): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare("
                SELECT id, workflow_id, payload, attempts
                FROM queue_jobs
                WHERE status = 'pending'
                ORDER BY created_at ASC
                LIMIT :limit
                FOR UPDATE
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($jobs)) {
                $jobIds = array_column($jobs, 'id');
                $inClause = implode(',', array_fill(0, count($jobIds), '?'));

                $updateStmt = $this->db->prepare("
                    UPDATE queue_jobs
                    SET status = 'processing', attempts = attempts + 1, updated_at = NOW()
                    WHERE id IN ($inClause)
                ");
                $updateStmt->execute($jobIds);
            }

            $this->db->commit();
            return $jobs;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Actualiza el estado final del trabajo tras ejecutarse
     */
    public function markCompleted(string $jobId, array $result): void
    {
        $stmt = $this->db->prepare("
            UPDATE queue_jobs
            SET status = 'completed', result = :result, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $jobId,
            ':result' => json_encode($result)
        ]);
    }

    public function markFailed(string $jobId, string $errorMessage): void
    {
        $stmt = $this->db->prepare("
            UPDATE queue_jobs
            SET status = 'failed', error_message = :error, updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $jobId,
            ':error' => $errorMessage
        ]);
    }

    /**
     * Obtiene el estado y resultado de un trabajo por su ID
     */
    public function getJobStatus(string $jobId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, workflow_id, status, attempts, error_message, result, created_at, updated_at
            FROM queue_jobs
            WHERE id = :id
        ");
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        if (!empty($row['result'])) {
            $row['result'] = json_decode($row['result'], true);
        }

        return $row;
    }
}
