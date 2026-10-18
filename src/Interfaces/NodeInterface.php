<?php

namespace P8P\Interfaces;

interface NodeInterface
{
    /**
     * Retorna los metadatos y esquema del nodo (icono, entradas, salidas, campos configurables)
     */
    public function getSchema(): array;

    /**
     * Valida los parámetros requeridos antes de ejecutar el nodo
     */
    public function validate(array $parameters): bool;

    /**
     * Ejecuta la lógica propia del nodo con el contexto de variables actual
     */
    public function execute(array $parameters, array $context): array;
}
