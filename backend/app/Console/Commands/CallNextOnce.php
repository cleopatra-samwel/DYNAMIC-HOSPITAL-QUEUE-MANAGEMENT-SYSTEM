<?php

namespace App\Console\Commands;

use App\Services\PriorityEngine;
use Illuminate\Console\Command;

/**
 * Runs a single PriorityEngine::callNext() and prints the resulting ticket
 * id (or "null"). Exists so the Phase 4 concurrency test can spawn two real,
 * separate OS processes — each with its own DB connection — to genuinely
 * exercise the row-level lock under concurrent execution, rather than
 * simulating concurrency within a single PHP process (which can't produce
 * true overlapping transactions).
 */
class CallNextOnce extends Command
{
    protected $signature = 'queue:call-next-once {department} {user}';

    protected $description = 'Calls PriorityEngine::callNext() once and prints the resulting ticket id (or "null").';

    public function handle(PriorityEngine $engine): int
    {
        $ticket = $engine->callNext((int) $this->argument('department'), (int) $this->argument('user'));

        $this->output->write($ticket?->id ?? 'null');

        return self::SUCCESS;
    }
}
