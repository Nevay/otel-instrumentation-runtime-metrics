# OpenTelemetry runtime metrics

Provides general PHP runtime metrics such as process uptime and memory usage.

## Installation

```shell
composer require tbachert/otel-instrumentation-runtime-metrics
```

## Usage

Metrics are automatically registered using the [`Instrumentation`] hook.

[`Instrumentation`]: https://github.com/open-telemetry/opentelemetry-php/blob/main/src/API/Instrumentation/AutoInstrumentation/Instrumentation.php
