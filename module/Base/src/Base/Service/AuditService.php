<?php

namespace Base\Service;

use Base\Table\AuditLogTable;

class AuditService
{
    protected $auditLogTable;
    protected $logFilePath;

    public function __construct(AuditLogTable $auditLogTable, $logFilePath)
    {
        $this->auditLogTable = $auditLogTable;
        $this->logFilePath = $logFilePath;
    }

    /**
     * Log an audit event to DB and file.
     *
     * @param string $category   booking|payment|user|admin|config|system
     * @param string $action     create|cancel|reactivate|delete|login|register|edit|payment_success|...
     * @param string $message    Human-readable summary (German)
     * @param array  $options    Optional: user_id, user_name, entity_type, entity_id, detail (array)
     */
    public function log($category, $action, $message, array $options = [])
    {
        $row = [
            'category'    => $category,
            'action'      => $action,
            'message'     => mb_substr($message, 0, 512),
            'user_id'     => $options['user_id'] ?? null,
            'user_name'   => $options['user_name'] ?? null,
            'entity_type' => $options['entity_type'] ?? null,
            'entity_id'   => $options['entity_id'] ?? null,
            'detail'      => isset($options['detail']) ? json_encode($options['detail'], JSON_UNESCAPED_UNICODE) : null,
            'ip'          => $this->getClientIp(),
            'created'     => date('Y-m-d H:i:s'),
        ];

        // Write to DB
        try {
            $this->auditLogTable->insert($row);
        } catch (\Exception $e) {
            error_log('AuditService DB error: ' . $e->getMessage());
        }

        // Write to file (JSON-per-line) with rotation
        try {
            $this->rotateLogIfNeeded();
            $row['created'] = date('Y-m-d H:i:s');
            $line = json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
            file_put_contents($this->logFilePath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            error_log('AuditService file error: ' . $e->getMessage());
        }
    }

    protected function rotateLogIfNeeded()
    {
        if (! file_exists($this->logFilePath)) {
            return;
        }
        $maxSize = 5 * 1024 * 1024; // 5 MB
        $maxFiles = 3;
        if (filesize($this->logFilePath) < $maxSize) {
            return;
        }
        // Rotate: .3 delete, .2→.3, .1→.2, current→.1
        $old = $this->logFilePath . '.' . $maxFiles;
        if (file_exists($old)) {
            @unlink($old);
        }
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $from = $this->logFilePath . '.' . $i;
            $to = $this->logFilePath . '.' . ($i + 1);
            if (file_exists($from)) {
                @rename($from, $to);
            }
        }
        @rename($this->logFilePath, $this->logFilePath . '.1');
    }

    protected function getClientIp()
    {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }
}
