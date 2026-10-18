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
            $resolved = self::getValueFromPath($path, $context);
            return $resolved !== null ? $resolved : $text;
        }

        // Si la expresión está embebida dentro de una cadena o JSON (ej: "{\"response\": \"{{node_1.output_text}}\"}")
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_\.]+)\s*}}/', function ($matches) use ($context) {
            $path = $matches[1];
            $val = self::getValueFromPath($path, $context);

            if (is_array($val) || is_object($val)) {
                return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            if ($val !== null) {
                // Escapar caracteres especiales para mantener la sintaxis del JSON intacta
                $jsonEscaped = json_encode((string)$val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                return substr($jsonEscaped, 1, -1);
            }

            return $matches[0];
        }, $text);
    }

    private static function getValueFromPath(string $path, array $context): mixed
    {
        $keys = explode('.', $path);

        // 1. Búsqueda si la ruta empieza por el ID de un nodo
        $nodeId = $keys[0];
        if (isset($context[$nodeId]) && is_array($context[$nodeId])) {
            $current = $context[$nodeId];
            $subKeys = array_slice($keys, 1);

            // Permitir sintaxis con o sin la clave '.output.'
            if (!empty($subKeys) && $subKeys[0] === 'output' && !array_key_exists('output', $current)) {
                array_shift($subKeys);
            }

            $found = true;
            foreach ($subKeys as $key) {
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
        }

        // 2. Búsqueda por ruta absoluta general
        $current = $context;
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

        // 3. Búsqueda directa por nombre de propiedad (ej. {{output_text}} o {{output}})
        if (count($keys) === 1) {
            $targetKey = $keys[0];
            foreach (array_reverse($context) as $nodeOutput) {
                if (is_array($nodeOutput)) {
                    if (array_key_exists($targetKey, $nodeOutput)) {
                        return $nodeOutput[$targetKey];
                    }
                    if (isset($nodeOutput['variable']) && $nodeOutput['variable'] === $targetKey) {
                        return $nodeOutput['value'];
                    }
                }
            }
        }

        return null;
    }
}
