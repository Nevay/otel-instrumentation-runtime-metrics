<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\RuntimeMetrics;

use Composer\InstalledVersions;
use Nevay\SPI\ServiceProviderDependency\PackageDependency;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Metrics\ObserverInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use function strtolower;

#[PackageDependency('revolt/event-loop', '^1.0')]
final class RevoltEventLoopMetrics implements Instrumentation {

    public function register(?HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void {
        $meter = $context->meterProvider->getMeter(
            name: 'com.tobiasbachert.otel.metrics.revolt',
            version: InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-runtime-metrics'),
            schemaUrl: 'https://opentelemetry.io/schemas/1.37.0',
        );

        $meter->createObservableUpDownCounter(
            name: 'php.revolt.eventloop.callback.count',
            unit: '{callback}',
            description: 'The number of registered event loop callbacks',
            _: static function(ObserverInterface $observer): void {
                $callbacks = [];
                foreach (EventLoop::getIdentifiers() as $identifier) {
                    $type = EventLoop::getType($identifier);
                    $enabled = EventLoop::isEnabled($identifier);
                    $referenced = EventLoop::isReferenced($identifier);

                    $callbacks[$type->name][$enabled][$referenced] ??= 0;
                    $callbacks[$type->name][$enabled][$referenced]++;
                }

                foreach (CallbackType::cases() as $type) {
                    $er = $callbacks[$type->name][true][true] ?? 0;
                    $eu = $callbacks[$type->name][true][false] ?? 0;
                    $dr = $callbacks[$type->name][false][true] ?? 0;
                    $du = $callbacks[$type->name][false][false] ?? 0;

                    $t = strtolower($type->name);
                    $observer->observe($er, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'referenced']);
                    $observer->observe($eu, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'unreferenced']);
                    $observer->observe($dr + $du, ['php.revolt.eventloop.callback.type' => $t, 'php.revolt.eventloop.callback.state' => 'disabled']);
                }
            },
        );
    }
}
