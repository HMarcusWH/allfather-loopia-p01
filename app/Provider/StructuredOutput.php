<?php
declare(strict_types=1);

namespace Allfather\Loopia\Provider;

/** Closed JSON-schema subset for bounded product candidates. Unknown keywords fail closed. */
final class StructuredOutput
{
    public static function schemaSupported(array $schema, int $depth = 0): bool
    {
        if ($depth > 8) return false;
        $allowed = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'minimum', 'maximum', 'description'];
        if (array_diff(array_keys($schema), $allowed) !== []) return false;
        $type = $schema['type'] ?? null;
        $types = is_array($type) ? $type : [$type];
        if ($types === [] || array_diff($types, ['object','array','string','integer','number','boolean','null']) !== []) return false;
        if (in_array('object', $types, true)) {
            if (($schema['additionalProperties'] ?? null) !== false || !isset($schema['properties'], $schema['required']) || !is_array($schema['properties']) || !is_array($schema['required'])) return false;
            if (array_diff(array_keys($schema['properties']), $schema['required']) !== [] || array_diff($schema['required'], array_keys($schema['properties'])) !== []) return false;
            foreach ($schema['properties'] as $name => $child) {
                if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D', $name) !== 1 || !is_array($child) || !self::schemaSupported($child, $depth + 1)) return false;
            }
        }
        if (in_array('array', $types, true) && (!isset($schema['items']) || !is_array($schema['items']) || !self::schemaSupported($schema['items'], $depth + 1))) return false;
        return true;
    }

    public static function matches(mixed $value, array $schema, int $depth = 0): bool
    {
        if ($depth > 12) return false;
        $types = is_array($schema['type'] ?? null) ? $schema['type'] : [$schema['type'] ?? null];
        $validType = false;
        foreach ($types as $type) {
            $validType = $validType || match ($type) {
                'object' => $value instanceof \stdClass,
                'array' => is_array($value) && array_is_list($value),
                'string' => is_string($value), 'integer' => is_int($value),
                'number' => is_int($value) || (is_float($value) && is_finite($value)),
                'boolean' => is_bool($value), 'null' => $value === null, default => false,
            };
        }
        if (!$validType || (isset($schema['enum']) && !in_array($value, $schema['enum'], true))) return false;
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            if (array_diff($schema['required'] ?? [], array_keys($properties)) !== []) return false;
            if (($schema['additionalProperties'] ?? true) === false && array_diff(array_keys($properties), array_keys($schema['properties'] ?? [])) !== []) return false;
            foreach ($properties as $key => $child) {
                if (!isset($schema['properties'][$key]) || !self::matches($child, $schema['properties'][$key], $depth + 1)) return false;
            }
        } elseif (is_array($value)) {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 128)) return false;
            foreach ($value as $child) if (!self::matches($child, $schema['items'], $depth + 1)) return false;
        } elseif (is_string($value)) {
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value) < ($schema['minLength'] ?? 0) || mb_strlen($value) > ($schema['maxLength'] ?? 16000)) return false;
        } elseif (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) return false;
            if (isset($schema['maximum']) && $value > $schema['maximum']) return false;
        }
        return true;
    }
}
