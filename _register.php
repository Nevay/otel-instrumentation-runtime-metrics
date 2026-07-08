<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\RuntimeMetrics;

use Nevay\SPI\ServiceLoader;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use function class_exists;

if (!class_exists(ServiceLoader::class)) {
    return;
}

ServiceLoader::register(Instrumentation::class, OpcacheMetrics::class);
ServiceLoader::register(Instrumentation::class, ProcessMetrics::class);
ServiceLoader::register(Instrumentation::class, RevoltEventLoopMetrics::class);
