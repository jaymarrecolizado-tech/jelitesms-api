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

    /**
     * Recent queue rows with consumer key names (admin Messages page).
     *
     * @return list<array>
     */
    public function recentWithKeyNames(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT m.*, k.name AS key_name FROM sms_messages m
             JOIN api_keys k ON k.id = m.api_key_id
             ORDER BY m.id DESC LIMIT ?'
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Per-API-key send counts for the admin Usage page (inclusive date range).
     *
     * @return list<array{id:int,name:string,key_prefix:string,active:int|string,total:int|string,queued:int|string,sending:int|string,sent:int|string,failed:int|string,last_used:?string}>
     */
    public function usageByKey(string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT k.id, k.name, k.key_prefix, k.active,
                    COUNT(m.id) AS total,
                    COALESCE(SUM(m.status = "queued"), 0) AS queued,
                    COALESCE(SUM(m.status = "sending"), 0) AS sending,
                    COALESCE(SUM(m.status = "sent"), 0) AS sent,
                    COALESCE(SUM(m.status = "delivered"), 0) AS delivered,
                    COALESCE(SUM(m.status = "failed"), 0) AS failed,
                    MAX(m.created_at) AS last_used
             FROM api_keys k
             LEFT JOIN sms_messages m
               ON m.api_key_id = k.id
              AND DATE(m.created_at) >= ?
              AND DATE(m.created_at) <= ?
             GROUP BY k.id, k.name, k.key_prefix, k.active
             ORDER BY total DESC, k.name ASC'
        );
        $stmt->execute([$fromDate, $toDate]);
        return $stmt->fetchAll();
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

    /**
     * Sent messages with a gateway id whose delivery state is still unknown,
     * oldest first (delivery-state sync).
     *
     * @return list<array{id:string,gateway_message_id:string}>
     */
    public function pendingDeliverySync(int $limit, int $days): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, gateway_message_id FROM sms_messages
             WHERE status = "sent" AND gateway_message_id IS NOT NULL AND delivered_at IS NULL
               AND created_at >= NOW() - INTERVAL ? DAY
             ORDER BY id LIMIT ?'
        );
        $stmt->execute([$days, $limit]);
        return $stmt->fetchAll();
    }

    public function markDelivered(int $id, ?string $gatewayState = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sms_messages SET status = "delivered", error = NULL, delivered_at = NOW(),
                    gateway_state = COALESCE(?, gateway_state)
             WHERE id = ?'
        );
        $stmt->execute([$gatewayState, $id]);
    }

    /** Terminal delivery failure reported by the gateway (no retries). */
    public function markDeliveryFailed(int $id, string $error, ?string $gatewayState = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE sms_messages SET status = "failed", error = ?, delivered_at = NOW(),
                    gateway_state = COALESCE(?, gateway_state)
             WHERE id = ?'
        );
        $stmt->execute([$error, $gatewayState, $id]);
    }

    /** Record the raw upstream state without changing local status. */
    public function recordGatewayState(int $id, string $gatewayState): void
    {
        $stmt = $this->db->prepare('UPDATE sms_messages SET gateway_state = ? WHERE id = ?');
        $stmt->execute([substr($gatewayState, 0, 50), $id]);
    }

    /**
     * Shared WHERE for the admin Reports page (inclusive date range + optional
     * key/status filters). Returns [sql, params].
     *
     * @return array{0:string,1:list<mixed>}
     */
    private function reportFilter(string $fromDate, string $toDate, ?int $keyId, ?string $status): array
    {
        $sql = 'WHERE DATE(m.created_at) >= ? AND DATE(m.created_at) <= ?';
        $params = [$fromDate, $toDate];
        if ($keyId !== null) {
            $sql .= ' AND m.api_key_id = ?';
            $params[] = $keyId;
        }
        if ($status !== null && in_array($status, ['queued', 'sending', 'sent', 'delivered', 'failed'], true)) {
            $sql .= ' AND m.status = ?';
            $params[] = $status;
        }
        return [$sql, $params];
    }

    /**
     * Report totals for the selected filters.
     *
     * @return array{total:int,queued:int,sending:int,sent:int,delivered:int,failed:int}
     */
    public function reportTotals(string $fromDate, string $toDate, ?int $keyId = null, ?string $status = null): array
    {
        [$where, $params] = $this->reportFilter($fromDate, $toDate, $keyId, $status);
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(m.status = "queued"), 0) AS queued,
                    COALESCE(SUM(m.status = "sending"), 0) AS sending,
                    COALESCE(SUM(m.status = "sent"), 0) AS sent,
                    COALESCE(SUM(m.status = "delivered"), 0) AS delivered,
                    COALESCE(SUM(m.status = "failed"), 0) AS failed
             FROM sms_messages m ' . $where
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'sending' => (int) ($row['sending'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * Per-app (API key) breakdown for the selected filters.
     *
     * @return list<array>
     */
    public function reportByKey(string $fromDate, string $toDate, ?int $keyId = null, ?string $status = null): array
    {
        [$where, $params] = $this->reportFilter($fromDate, $toDate, $keyId, $status);
        $stmt = $this->db->prepare(
            'SELECT k.id, k.name, k.key_prefix,
                    COUNT(m.id) AS total,
                    COALESCE(SUM(m.status = "queued"), 0) AS queued,
                    COALESCE(SUM(m.status = "sending"), 0) AS sending,
                    COALESCE(SUM(m.status = "sent"), 0) AS sent,
                    COALESCE(SUM(m.status = "delivered"), 0) AS delivered,
                    COALESCE(SUM(m.status = "failed"), 0) AS failed
             FROM api_keys k
             JOIN sms_messages m ON m.api_key_id = k.id
             ' . $where . '
             GROUP BY k.id, k.name, k.key_prefix
             ORDER BY total DESC, k.name ASC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Message drill-down rows for the selected filters (newest first).
     *
     * @return list<array>
     */
    public function reportMessages(string $fromDate, string $toDate, ?int $keyId = null, ?string $status = null, int $limit = 200): array
    {
        [$where, $params] = $this->reportFilter($fromDate, $toDate, $keyId, $status);
        $params[] = $limit;
        $stmt = $this->db->prepare(
            'SELECT m.*, k.name AS key_name FROM sms_messages m
             JOIN api_keys k ON k.id = m.api_key_id
             ' . $where . '
             ORDER BY m.id DESC LIMIT ?'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
