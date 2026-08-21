<?php

namespace Jelite;

class SmsRepository
{
    public function __construct(private \PDO $db)
    {
    }

    /**
     * @return array{created:bool,message:array}
     */
    public function enqueue(int $apiKeyId, string $toE164, string $body, ?string $clientRef): array
    {
        if ($clientRef !== null) {
            $existing = $this->findByClientRef($apiKeyId, $clientRef);
            if ($existing !== null) {
                return ['created' => false, 'message' => $existing];
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO sms_messages (api_key_id, to_e164, body, client_ref) VALUES (?, ?, ?, ?)'
        );
        try {
            $stmt->execute([$apiKeyId, $toE164, $body, $clientRef]);
        } catch (\PDOException $e) {
            // Race on the unique (api_key_id, client_ref) index → treat as idempotent replay.
            if ($clientRef !== null && (int) ($e->errorInfo[1] ?? 0) === 1062) {
                $existing = $this->findByClientRef($apiKeyId, $clientRef);
                if ($existing !== null) {
                    return ['created' => false, 'message' => $existing];
                }
            }
            throw $e;
        }

        $message = $this->find((int) $this->db->lastInsertId());
        return ['created' => true, 'message' => $message];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sms_messages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByClientRef(int $apiKeyId, string $clientRef): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sms_messages WHERE api_key_id = ? AND client_ref = ? LIMIT 1');
        $stmt->execute([$apiKeyId, $clientRef]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function countRecentFor(int $apiKeyId, int $seconds = 60): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM sms_messages WHERE api_key_id = ? AND created_at >= NOW() - INTERVAL ? SECOND'
        );
        $stmt->execute([$apiKeyId, $seconds]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Claim queued messages for sending (marks them `sending`).
     *
     * @return list<array>
     */
    public function claimQueued(int $limit, int $maxAttempts): array
    {
        $ids = $this->db
            ->prepare('SELECT id FROM sms_messages WHERE status = "queued" AND attempts < ? ORDER BY id LIMIT ?');
        $ids->execute([$maxAttempts, $limit]);
        $ids = array_column($ids->fetchAll(), 'id');
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $this->db->prepare(
            'UPDATE sms_messages SET status = "sending", attempts = attempts + 1 WHERE id IN (' . $placeholders . ')'
        );
        $update->execute($ids);

        $select = $this->db->prepare('SELECT * FROM sms_messages WHERE id IN (' . $placeholders . ') ORDER BY id');
        $select->execute($ids);
        return $select->fetchAll();
    }

    public function markSent(int $id, ?string $gatewayMessageId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sms_messages SET status = "sent", gateway_message_id = COALESCE(?, gateway_message_id), error = NULL, sent_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$gatewayMessageId, $id]);
    }

    public function markFailed(int $id, string $error, int $maxAttempts): void
    {
        $status = 'failed';
        $stmt = $this->db->prepare('SELECT attempts FROM sms_messages WHERE id = ?');
        $stmt->execute([$id]);
        $attempts = (int) $stmt->fetchColumn();

        // Soft-fail: retry later while attempts remain.
        if ($attempts < $maxAttempts) {
            $status = 'queued';
        }

        $stmt = $this->db->prepare('UPDATE sms_messages SET status = ?, error = ? WHERE id = ?');
        $stmt->execute([$status, substr($error, 0, 500), $id]);
    }
}
