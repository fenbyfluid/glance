<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// These are aligned with the Horizon TTLs
Schedule::command('queue:prune-failed --hours=168')->hourly();
Schedule::command('queue:prune-batches --hours=24 --unfinished=168 --cancelled=168')->hourly();
