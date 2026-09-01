<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Models;

use BlueFission\Arr;
use BlueFission\BlueCore\Model\ModelSql;
use BlueFission\Val;

class ApplicationIntakeModel extends ModelSql
{
    protected $_table = 'application_intakes';

    protected $_fields = [
        'application_intake_id',
        'session_key',
        'tenant_id',
        'application_slug',
        'prompt_version',
        'status',
        'answers',
        'defaults',
        'skipped',
        'actor',
        'correlation_id',
        'revision',
        'session_created_at',
        'session_updated_at',
        'completed_at',
    ];

    protected $_ignore_null = false;

    public function insertIfAbsent(array $record): bool
    {
        $columns = $this->persistedColumns($record);
        $names = Arr::make($columns)
            ->map(static fn (mixed $value, string $column): string => '`' . $column . '`')
            ->values()
            ->join(', ');
        $values = Arr::make($columns)
            ->map(fn (mixed $value): string => $this->sqlLiteral($value))
            ->values()
            ->join(', ');

        return $this->executeMutation(
            "INSERT INTO `{$this->_table}` ({$names}) VALUES ({$values}) "
            . 'ON DUPLICATE KEY UPDATE `session_key` = `session_key`'
        );
    }

    public function updateIfRevision(array $record, int $expectedRevision): bool
    {
        $columns = $this->persistedColumns($record);
        $assignments = Arr::make($columns)
            ->filter(static fn (mixed $value, string $column): bool => $column !== 'session_key')
            ->map(fn (mixed $value, string $column): string => (
                '`' . $column . '` = ' . $this->sqlLiteral($value)
            ))
            ->values()
            ->join(', ');
        $sessionKey = $this->sqlLiteral($columns['session_key']);

        return $this->executeMutation(
            "UPDATE `{$this->_table}` SET {$assignments} "
            . "WHERE `session_key` = {$sessionKey} AND `revision` = {$expectedRevision}"
        );
    }

    private function persistedColumns(array $record): array
    {
        return Arr::make($record)
            ->filter(fn (mixed $value, string $column): bool => Arr::has($this->_fields, $column))
            ->toArray();
    }

    private function executeMutation(string $query): bool
    {
        $this->_dataObject->run($query);
        $this->_dataObject->run('SELECT ROW_COUNT() AS affected_rows');
        $result = Arr::toArray($this->_dataObject->contents(), true);

        return (int) ($result['affected_rows'] ?? 0) === 1;
    }

    private function sqlLiteral(mixed $value): string
    {
        if (Val::isNull($value)) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $hex = bin2hex((string) $value);

        return $hex === '' ? "''" : "CONVERT(0x{$hex} USING utf8mb4)";
    }
}
