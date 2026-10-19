<?php

namespace P8P\Core;

use P8P\Interfaces\NodeInterface;
use ReflectionClass;

class NodeRegistry
{
    private Engine $engine;
    private array $discoveredNodes = [];

    public function __construct(Engine $engine)
    {
        $this->engine = $engine;
    }

    public function autoDiscover(): void
    {
        $nodesDir = realpath(__DIR__ . '/../Nodes');
        if (!$nodesDir || !is_dir($nodesDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($nodesDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace([$nodesDir . DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $parts = explode(DIRECTORY_SEPARATOR, $relativePath);

            // Detectar subcarpeta primaria (Core, Custom, Premium, etc.)
            $folderCategory = count($parts) > 1 ? $parts[0] : 'Core';
            if (strtolower($folderCategory) === 'custom') {
                $folderCategory = 'Community';
            }

            $className = "P8P\\Nodes\\" . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);

            if (class_exists($className)) {
                $reflector = new ReflectionClass($className);
                if ($reflector->implementsInterface(NodeInterface::class) && !$reflector->isAbstract()) {
                    $nodeInstance = new $className();
                    $this->engine->registerNode($nodeInstance);

                    $schema = $nodeInstance->getSchema();

                    // Si no tiene categoría explícita, usar la de la carpeta
                    if (empty($schema['category'])) {
                        $schema['category'] = ucfirst($folderCategory);
                    }

                    $this->discoveredNodes[$schema['type']] = $schema;
                }
            }
        }
    }

    public function getDiscoveredSchemas(): array
    {
        return array_values($this->discoveredNodes);
    }
}
