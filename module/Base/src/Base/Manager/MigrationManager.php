<?php

namespace Base\Manager;

use Zend\Db\Adapter\Adapter;

class MigrationManager
{
    protected $dbAdapter;
    protected $basePath;

    public function __construct(Adapter $dbAdapter, $basePath)
    {
        $this->dbAdapter = $dbAdapter;
        $this->basePath = $basePath;
    }

    /**
     * Run all pending migrations.
     *
     * Reads current schema version from bs_options, then executes
     * any migrations with a higher version number whose check query
     * returns no rows (i.e. migration not yet applied).
     */
    public function runPendingMigrations()
    {
        $migrations = include $this->basePath . '/data/db/migrations.php';

        if (! is_array($migrations) || empty($migrations)) {
            return;
        }

        $currentVersion = $this->getSchemaVersion();

        foreach ($migrations as $version => $migration) {
            if ($version <= $currentVersion) {
                continue;
            }

            if ($this->isMigrationApplied($migration['check'])) {
                $this->setSchemaVersion($version);
                continue;
            }

            $this->executeMigrationFile($migration);
            $this->setSchemaVersion($version);
        }
    }

    protected function getSchemaVersion()
    {
        try {
            $result = $this->dbAdapter->query(
                "SELECT `value` FROM bs_options WHERE `key` = 'schema.version' AND locale IS NULL LIMIT 1",
                Adapter::QUERY_MODE_EXECUTE
            );

            $row = $result->current();

            if ($row) {
                return (int) $row['value'];
            }
        } catch (\Exception $e) {
            // bs_options table may not exist yet (fresh install before setup wizard)
            return 0;
        }

        return 0;
    }

    protected function setSchemaVersion($version)
    {
        try {
            $current = $this->getSchemaVersion();

            if ($current > 0 || $this->schemaVersionExists()) {
                $this->dbAdapter->query(
                    "UPDATE bs_options SET `value` = ? WHERE `key` = 'schema.version' AND locale IS NULL",
                    [$version]
                );
            } else {
                $this->dbAdapter->query(
                    "INSERT INTO bs_options (`key`, `value`, locale) VALUES ('schema.version', ?, NULL)",
                    [$version]
                );
            }
        } catch (\Exception $e) {
            error_log('MigrationManager: Failed to set schema version: ' . $e->getMessage());
        }
    }

    protected function schemaVersionExists()
    {
        $result = $this->dbAdapter->query(
            "SELECT COUNT(*) as cnt FROM bs_options WHERE `key` = 'schema.version' AND locale IS NULL",
            Adapter::QUERY_MODE_EXECUTE
        );

        $row = $result->current();

        return $row && (int) $row['cnt'] > 0;
    }

    protected function isMigrationApplied($checkSql)
    {
        try {
            $result = $this->dbAdapter->query($checkSql, Adapter::QUERY_MODE_EXECUTE);
            return $result->count() > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function executeMigrationFile(array $migration)
    {
        $filePath = $this->basePath . '/' . $migration['file'];

        if (! file_exists($filePath)) {
            error_log('MigrationManager: Migration file not found: ' . $filePath);
            return;
        }

        $sql = file_get_contents($filePath);

        if (! $sql) {
            error_log('MigrationManager: Empty migration file: ' . $filePath);
            return;
        }

        // Remove SQL comment lines, then split by semicolons
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);

        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            function ($s) {
                return $s !== '';
            }
        );

        $connection = $this->dbAdapter->getDriver()->getConnection();

        foreach ($statements as $statement) {
            try {
                $connection->execute($statement);
            } catch (\Exception $e) {
                // Log but continue — e.g. "index already exists" should not block
                error_log('MigrationManager: Statement failed for ' . $migration['name'] . ': ' . $e->getMessage());
            }
        }

        error_log('MigrationManager: Applied migration ' . $migration['name']);
    }
}