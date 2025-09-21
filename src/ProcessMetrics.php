<?php declare(strict_types=1);
namespace Nevay\OTelInstrumentation\RuntimeMetrics;

use Composer\InstalledVersions;
use OpenTelemetry\API\Configuration\ConfigProperties;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Context;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\HookManagerInterface;
use OpenTelemetry\API\Instrumentation\AutoInstrumentation\Instrumentation;
use OpenTelemetry\API\Metrics\ObserverInterface;
use function function_exists;
use function gc_enabled;
use function gc_status;
use function getrusage;
use function ini_get;
use function ini_parse_quantity;
use function memory_get_usage;
use const PHP_VERSION_ID;

final class ProcessMetrics implements Instrumentation {

    public function register(HookManagerInterface $hookManager, ConfigProperties $configuration, Context $context): void {
        $meter = $context->meterProvider->getMeter(
            name: 'com.tobiasbachert.otel.metrics.process',
            version: InstalledVersions::getPrettyVersion('tbachert/otel-instrumentation-runtime-metrics'),
            schemaUrl: 'https://opentelemetry.io/schemas/1.37.0',
        );

        $meter->batchObserve(
            static function(
                ObserverInterface $cpuTime,
                ObserverInterface $pagingFaults,
                ObserverInterface $contextSwitches,
            ): void {
                $d = getrusage();

                $cpuTime->observe($d['ru_utime.tv_sec'] + $d['ru_utime.tv_usec'] / 1e6, ['cpu.mode' => 'user']);
                $cpuTime->observe($d['ru_stime.tv_sec'] + $d['ru_stime.tv_usec'] / 1e6, ['cpu.mode' => 'system']);

                if (isset($d['ru_minflt'])) {
                    $pagingFaults->observe($d['ru_minflt'], ['process.paging.fault_type' => 'minor']);
                }
                if (isset($d['ru_majflt'])) {
                    $pagingFaults->observe($d['ru_majflt'], ['process.paging.fault_type' => 'major']);
                }
                if (isset($d['ru_nvcsw'])) {
                    $contextSwitches->observe($d['ru_nvcsw'], ['process.context_switch_type' => 'voluntary']);
                }
                if (isset($d['ru_nivcsw'])) {
                    $contextSwitches->observe($d['ru_nivcsw'], ['process.context_switch_type' => 'involuntary']);
                }
            },
            $meter->createObservableCounter(
                name: 'process.cpu.time',
                unit: 's',
                description: 'Total CPU seconds broken down by different states',
            ),
            $meter->createObservableCounter(
                name: 'process.paging.faults',
                unit: '{fault}',
                description: 'Number of page faults the process has made',
            ),
            $meter->createObservableCounter(
                name: 'process.context_switches',
                unit: '{context_switch}',
                description: 'Number of times the process has been context switched',
            ),
        );
        $meter->batchObserve(
            static function(
                ObserverInterface $usage,
                ObserverInterface $limit,
            ): void {
                $used = memory_get_usage();
                $real = memory_get_usage(true);

                $usage->observe($used, ['php.memory.state' => 'used']);
                $usage->observe($real - $used, ['php.memory.state' => 'reserved']);

                if (!function_exists('ini_parse_quantity')) {
                    return;
                }
                if (!($m = ini_get('memory_limit')) || $m === '-1') {
                    return;
                }

                $max = ini_parse_quantity($m);
                $usage->observe($max - $real, ['php.memory.state' => 'free']);
                $limit->observe($max);
            },
            $meter->createObservableUpDownCounter(
                name: 'php.memory.usage',
                unit: 'By',
                description: 'The amount of physical memory in use',
            ),
            $meter->createObservableUpDownCounter(
                name: 'php.memory.limit',
                unit: 'By',
                description: 'The runtime memory limit configured by the user',
            ),
        );
        $meter->createObservableGauge(
            name: 'php.gc.enabled',
            unit: '{enabled}',
            description: 'Whether the garbage collector is enabled',
            _: static function(ObserverInterface $observer): void {
                $observer->observe(+gc_enabled());
            }
        );
        $meter->batchObserve(
            static function (
                ObserverInterface $running,
                ObserverInterface $protected,
                ObserverInterface $full,
                ObserverInterface $runs,
                ObserverInterface $collected,
                ObserverInterface $threshold,
                ObserverInterface $roots,
                ObserverInterface $bufferSize,
                ObserverInterface $gcTime,
                ObserverInterface $uptime,
            ): void {
                $d = gc_status();

                $runs->observe($d['runs']);
                $collected->observe($d['collected']);
                $threshold->observe($d['threshold']);
                $roots->observe($d['roots']);

                if (PHP_VERSION_ID < 80300) {
                    return;
                }

                $running->observe(+$d['running']);
                $protected->observe(+$d['protected']);
                $full->observe(+$d['full']);

                $bufferSize->observe($d['buffer_size']);

                $f = $d['free_time'];
                $t = $d['destructor_time'];
                $c = $d['collector_time'] - $t - $f;
                $gcTime->observe($c, ['php.gc.action' => 'collect']);
                $gcTime->observe($t, ['php.gc.action' => 'destruct']);
                $gcTime->observe($f, ['php.gc.action' => 'free']);

                $uptime->observe($d['application_time']);
            },
            $meter->createObservableGauge(
                name: 'php.gc.running',
                unit: '{running}',
                description: 'Whether the garbage collector is running',
            ),
            $meter->createObservableGauge(
                name: 'php.gc.protected',
                unit: '{protected}',
                description: 'Whether the garbage collector is protected'
            ),
            $meter->createObservableGauge(
                name: 'php.gc.full',
                unit: '{full}',
                description: 'Whether the garbage collector buffer exceeds GC_MAX_BUF_SIZE'
            ),
            $meter->createObservableCounter(
                name: 'php.gc.runs',
                unit: '{gc}',
                description: 'The number of times the garbage collector has run',
            ),
            $meter->createObservableCounter(
                name: 'php.gc.collected',
                unit: '{object}',
                description: 'The number of objects collected by the garbage collector',
            ),
            $meter->createObservableGauge(
                name: 'php.gc.threshold',
                unit: '{root}',
                description: 'The number of roots in the buffer which will trigger garbage collection',
            ),
            $meter->createObservableUpDownCounter(
                name: 'php.gc.roots',
                unit: '{root}',
                description: 'The number of roots in the garbage collector buffer',
            ),
            $meter->createObservableUpDownCounter(
                name: 'php.gc.buffer.size',
                unit: '{root}',
                description: 'The garbage collector buffer size',
            ),
            $meter->createObservableCounter(
                name: 'php.gc.time',
                unit: 's',
                description: 'The time spent collecting cycles',
            ),
            $meter->createObservableGauge(
                name: 'process.uptime',
                unit: 's',
                description: 'The time the process has been running',
            ),
        );
    }
}
