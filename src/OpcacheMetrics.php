<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\RuntimeMetrics;

use Composer\InstalledVersions;
use Nevay\SPI\ServiceProviderDependency\ExtensionDependency;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Metrics\ObserverInterface;

#[ExtensionDependency('Zend OPcache', '*')]
final class OpcacheMetrics implements Instrumentation {

    public function register(?HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void {
        $meter = $context->meterProvider->getMeter(
            name: 'com.tobiasbachert.otel.metrics.opcache',
            version: InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-runtime-metrics'),
            schemaUrl: 'https://opentelemetry.io/schemas/1.37.0',
        );

        $meter->batchObserve(
            static function(
                ObserverInterface $opcacheEnabled,
                ObserverInterface $jitEnabled,
            ): void {
                /** @noinspection PhpComposerExtensionStubsInspection */
                $status = \opcache_get_status(false);

                $opcacheEnabled->observe(+($status['opcache_enabled'] ?? 0));
                $jitEnabled->observe(+($status['jit']['enabled'] ?? 0));
            },
            $meter->createObservableGauge(
                name: 'php.opcache.enabled',
                unit: '{enabled}',
                description: 'Whether OPcache is enabled',
            ),
            $meter->createObservableGauge(
                name: 'php.jit.enabled',
                unit: '{enabled}',
                description: 'Whether the JIT is enabled',
            ),
        );
    }
}
