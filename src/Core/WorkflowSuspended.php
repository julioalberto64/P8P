<?php

namespace P8P\Core;

/** Señal interna del motor; no representa un fallo del workflow. */
class WorkflowSuspended extends \RuntimeException
{
    public function __construct(public readonly int $resumeAt)
    {
        parent::__construct('Ejecución suspendida hasta su continuación programada.');
    }
}
