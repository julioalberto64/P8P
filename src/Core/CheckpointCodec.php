<?php

namespace P8P\Core;

/** JSON tipado para conservar arrays y objetos de datos sin unserialize ni clases ejecutables. */
class CheckpointCodec
{
    public static function encode(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 200) {
            throw new \RuntimeException('El contexto supera la profundidad persistible de Delay.');
        }
        if (is_array($value) || $value instanceof \stdClass) {
            $entries = [];
            foreach ($value as $key => $child) {
                $entries[] = [$key, self::encode($child, $depth + 1)];
            }
            return ['kind' => is_array($value) ? 'array' : 'object', 'entries' => $entries];
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \RuntimeException('Delay no puede persistir números no finitos.');
        }
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        throw new \RuntimeException('Delay solo puede persistir datos escalares, arrays y objetos JSON.');
    }

    public static function decode(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 200) {
            throw new \RuntimeException('Checkpoint demasiado profundo.');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!in_array($value['kind'] ?? null, ['array', 'object'], true) || !is_array($value['entries'] ?? null)) {
            throw new \RuntimeException('Datos de checkpoint inválidos.');
        }
        $result = $value['kind'] === 'array' ? [] : new \stdClass();
        foreach ($value['entries'] as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || (!is_int($entry[0]) && !is_string($entry[0]))) {
                throw new \RuntimeException('Entrada de checkpoint inválida.');
            }
            if (is_array($result)) {
                $result[$entry[0]] = self::decode($entry[1], $depth + 1);
            } else {
                $result->{(string) $entry[0]} = self::decode($entry[1], $depth + 1);
            }
        }
        return $result;
    }
}
