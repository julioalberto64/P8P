<?php

namespace P8P\Core;

class ExpressionEvaluator
{
    /**
     * Evalúa recursivamente parámetros (strings, arrays) reemplazando la sintaxis {{variable}}
     */
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
        // Si el texto es EXACTAMENTE una sola expresión (ej: "{{node_1.output_text}}")
        if (preg_match('/^{{\s*([a-zA-Z0-9_\.]+)\s*}}$/', trim($text), $matches)) {
            $path = $matches[1];
            $found = false;
            $resolved = self::getValueFromPath($path, $context, $found);
            return $found ? $resolved : $text;
        }

        // Si la expresión está embebida dentro de una cadena o JSON (ej: "{\"response\": \"{{node_1.output_text}}\"}")
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($matches) use ($context) {
            $path = $matches[1];
            $found = false;
            $val = self::getValueFromPath($path, $context, $found);

            if (is_array($val) || is_object($val)) {
                return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($found) {
                // Escapar caracteres especiales para mantener la sintaxis del JSON intacta
                $jsonEscaped = json_encode((string)$val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return substr($jsonEscaped, 1, -1);
            }

            return $matches[0];
        }, $text);
    }

    private static function getValueFromPath(string $path, array $context, bool &$found): mixed
    {
        $keys = explode('.', $path);
        $first = $keys[0];
        $found = false;
        if (isset($context['_deleted_variables'][$first])) {
            return null;
        }
        if (array_key_exists($first, $context['_variables'] ?? [])) {
            return self::walk($context['_variables'][$first], array_slice($keys, 1), $found);
        }
        if (array_key_exists($first, $context)) {
            $subKeys = array_slice($keys, 1);
            $current = $context[$first];
            if (($subKeys[0] ?? null) === 'output' && is_array($current) && !array_key_exists('output', $current)) {
                array_shift($subKeys);
            }
            $value = self::walk($current, $subKeys, $found);
            if ($found) {
                return $value;
            }
        }
        if (count($keys) === 1) {
            foreach (array_reverse($context, true) as $id => $output) {
                if (str_starts_with((string) $id, '_') || !is_array($output)) {
                    continue;
                }
                if (array_key_exists($first, $output)) {
                    $found = true;
                    return $output[$first];
                }
                if (($output['variable'] ?? null) === $first && array_key_exists('value', $output)) {
                    $found = true;
                    return $output['value'];
                }
            }
        }
        return null;
    }

    private static function walk(mixed $value, array $keys, bool &$found): mixed
    {
        foreach ($keys as $key) {
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif ($value instanceof \stdClass && property_exists($value, $key)) {
                $value = $value->{$key};
            } else {
                $found = false;
                return null;
            }
        }
        $found = true;
        return $value;
    }
}
