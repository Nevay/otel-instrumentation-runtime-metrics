<?php declare(strict_types=1);

/*
 * Exposes metrics in prometheus format on http://localhost:9464
 */

require_once __DIR__ . '/vendor/autoload.php';

Amp\trapSignal([SIGINT, SIGTERM]);
