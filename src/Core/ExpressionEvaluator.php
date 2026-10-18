<?php

namespace P8P\Core;

class ExpressionEvaluator
{
    public static function evaluate(mixed $value, array $context): mixed
    {
        if (is_string($value)) {
            return self::interpolateString($value, $context);
        }

        if (is_array($value)) {
            $evaluatedArray = [];
            foreach ($value as $key => $subValue) {
                $evaluatedArray[$key] = self::evaluate($subValue, $context);
            }
            return $evaluatedArray;
        }

        return $value;
    }

    private static function interpolateString(string $text, array $context): mixed
    {
        if (preg_match('/^{{\s*([a-zA-Z0-9_\.]+)\s*}}$/', trim($text), $matches)) {
            $path = $matches[1];
            $resolved = self::getValueFromPath($path, $context);
            return $resolved !== null ? $resolved : $text;
        }

        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($matches) use ($context) {
            $path = $matches[1];
            $val = self::getValueFromPath($path, $context);

            if (is_array($val) || is_object($val)) {
                return json_encode($val);
            }

            return $val !== null ? (string)$val : $matches[0];
        }, $text);
    }

    private static function getValueFromPath(string $path, array $context): mixed
    {
        $keys = explode('.', $path);
        $current = $context;

        // 1. Buscar por ruta absoluta de nodo (ej: node_123.data.title)
        $found = true;
        foreach ($keys as $key) {
            if (is_array($current) && array_key_exists($key, $current)) {
                $current = $current[$key];
            } else {
                $found = false;
                break;
            }
        }

        if ($found) {
            return $current;
        }

        // 2. Si es un nombre de variable simple (ej: {{url}} o {{title}}), buscar en las salidas de nodos Set Variable
        if (count($keys) === 1) {
            $varName = $keys[0];
            foreach ($context as $nodeOutput) {
                if (is_array($nodeOutput) && isset($nodeOutput['variable']) && $nodeOutput['variable'] === $varName) {
                    return $nodeOutput['value'];
                }
            }
        }

        return null;
    }
}
