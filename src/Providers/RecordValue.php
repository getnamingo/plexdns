<?php

declare(strict_types=1);

namespace Namingo\Cardo\DNS\Providers;

/**
 * Canonical formatting for record types whose RDATA is split into fields by Service.
 *
 * Providers that expose separate priority/weight/port/CAA fields can keep using
 * those fields. Providers that accept textual RDATA can use this helper so the
 * same public Namingo\Cardo\DNS input shape produces valid wire-format content.
 */
final class RecordValue
{
    public static function content(
        string $type,
        string $value,
        array $data = [],
        bool $includeSrvPriority = false,
        bool $includeMxPriority = false
    ): string {
        $type = strtoupper(trim($type));
        $value = trim($value);

        if ($type === 'CAA') {
            return self::caa($value, $data);
        }

        if ($type === 'SRV') {
            return self::srv($value, $data, $includeSrvPriority);
        }

        if ($type === 'MX' && $includeMxPriority) {
            if (preg_match('/^\d+\s+\S+/', $value)) {
                return $value;
            }
            if (!isset($data['priority'])) {
                throw new \InvalidArgumentException('MX records require a priority.');
            }

            return (int)$data['priority'] . ' ' . $value;
        }

        return $value;
    }

    public static function caa(string $value, array $data = []): string
    {
        $value = trim($value);

        if (preg_match('/^(\d+)\s+([A-Za-z0-9-]+)\s+(.*)$/', $value, $matches)) {
            return (int)$matches[1]
                . ' ' . strtolower($matches[2])
                . ' ' . self::caaValue(trim($matches[3]));
        }

        $tag = strtolower(trim((string)($data['tag'] ?? '')));
        if ($tag === '') {
            throw new \InvalidArgumentException('CAA records require a tag.');
        }

        $flags = filter_var($data['flags'] ?? 0, FILTER_VALIDATE_INT);
        if ($flags === false || $flags < 0 || $flags > 255) {
            throw new \InvalidArgumentException('CAA flags must be between 0 and 255.');
        }

        return $flags . ' ' . $tag . ' ' . self::caaValue($value);
    }

    private static function caaValue(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2
            && $value[0] === '"'
            && $value[strlen($value) - 1] === '"') {
            return $value;
        }

        if ($value === '' || preg_match('/[;\s"\\\\]/', $value)) {
            $escaped = str_replace(
                ['\\', '"'],
                ['\\\\', '\\"'],
                $value
            );

            return '"' . $escaped . '"';
        }

        return $value;
    }

    public static function srv(
        string $value,
        array $data = [],
        bool $includePriority = false
    ): string {
        $value = trim($value);

        if (preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $value, $matches)) {
            if ($includePriority) {
                return (int)$matches[1]
                    . ' ' . (int)$matches[2]
                    . ' ' . (int)$matches[3]
                    . ' ' . trim($matches[4]);
            }

            return (int)$matches[2]
                . ' ' . (int)$matches[3]
                . ' ' . trim($matches[4]);
        }

        if (preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $value, $matches)) {
            if ($includePriority) {
                if (!isset($data['priority'])) {
                    throw new \InvalidArgumentException('SRV records require a priority.');
                }

                return (int)$data['priority']
                    . ' ' . (int)$matches[1]
                    . ' ' . (int)$matches[2]
                    . ' ' . trim($matches[3]);
            }

            return (int)$matches[1]
                . ' ' . (int)$matches[2]
                . ' ' . trim($matches[3]);
        }

        if (!isset($data['weight'], $data['port'])) {
            throw new \InvalidArgumentException(
                'SRV records require weight, port and target.'
            );
        }

        $prefix = '';
        if ($includePriority) {
            if (!isset($data['priority'])) {
                throw new \InvalidArgumentException('SRV records require a priority.');
            }
            $prefix = (int)$data['priority'] . ' ';
        }

        return $prefix
            . (int)$data['weight']
            . ' ' . (int)$data['port']
            . ' ' . $value;
    }
}
