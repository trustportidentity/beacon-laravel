<?php

namespace TrustPortIdentity\Beacon\Watchers;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobExceptionOccurred;
use TrustPortIdentity\Beacon\ActiveTrace;
use TrustPortIdentity\Beacon\BeaconClient;
use TrustPortIdentity\Beacon\BeaconManager;

class JobWatcher
{
    /** @var array<string, float> */
    private array $jobStarts = [];

    public function __construct(
        private readonly BeaconClient $client,
        private readonly BeaconManager $manager
    ) {
    }

    public function recordJobProcessing(JobProcessing $event): void
    {
        $jobId = $this->getJobId($event->job);
        $this->jobStarts[$jobId] = microtime(true);

        // If running in a CLI worker without an active trace, initialize one for this job
        $currentTrace = $this->manager->getCurrentTrace();
        if ($currentTrace === null) {
            $trace = new ActiveTrace();
            $this->manager->setCurrentTrace($trace);
        }
    }

    public function recordJobProcessed(JobProcessed $event): void
    {
        $jobId = $this->getJobId($event->job);
        $start = $this->jobStarts[$jobId] ?? microtime(true);
        unset($this->jobStarts[$jobId]);

        $durationMs = (microtime(true) - $start) * 1000;
        $jobName = $event->job->resolveName();
        $queue = $event->job->getQueue();
        $connection = $event->connectionName;

        $trace = $this->manager->getCurrentTrace();
        if ($trace !== null) {
            // Add as span
            $trace->addSpan([
                'type' => 'job',
                'name' => sprintf('JOB %s', $jobName),
                'start_ms' => 0.0,
                'duration_ms' => round($durationMs, 2),
                'metadata' => [
                    'queue' => $queue,
                    'connection' => $connection,
                    'attempts' => $event->job->attempts(),
                    'status' => 'completed',
                ],
                'tags' => [
                    'job' => $jobName,
                    'queue' => $queue,
                ],
            ]);

            // If this was an async queue job running standalone, report its trace
            if (empty($trace->user) && count($trace->spans) <= 5) {
                $this->client->report(
                    $trace,
                    [
                        'method' => 'QUEUE',
                        'route' => sprintf('queue:%s:%s', $queue, class_basename($jobName)),
                        'url' => sprintf('job://%s/%s', $connection, $jobName),
                        'status_code' => 200,
                    ],
                    $durationMs,
                    null
                );
                $this->manager->setCurrentTrace(null);
            }
        }
    }

    public function recordJobFailed(JobFailed $event): void
    {
        $jobId = $this->getJobId($event->job);
        $start = $this->jobStarts[$jobId] ?? microtime(true);
        unset($this->jobStarts[$jobId]);

        $durationMs = (microtime(true) - $start) * 1000;
        $jobName = $event->job->resolveName();
        $queue = $event->job->getQueue();
        $connection = $event->connectionName;
        $e = $event->exception;

        $trace = $this->manager->getCurrentTrace() ?? new ActiveTrace();

        $exceptionData = [
            'type' => get_class($e),
            'message' => $e->getMessage(),
            'handled' => false,
            'stacktrace' => array_map(
                fn (array $frame) => [
                    'file' => $frame['file'] ?? 'unknown',
                    'line' => $frame['line'] ?? 0,
                    'function' => $frame['function'] ?? 'unknown',
                ],
                $e->getTrace()
            ),
        ];

        $trace->addSpan([
            'type' => 'job',
            'name' => sprintf('JOB FAILED %s', $jobName),
            'start_ms' => 0.0,
            'duration_ms' => round($durationMs, 2),
            'metadata' => [
                'queue' => $queue,
                'connection' => $connection,
                'attempts' => $event->job->attempts(),
                'status' => 'failed',
                'error' => $e->getMessage(),
            ],
            'tags' => [
                'job' => $jobName,
                'queue' => $queue,
                'failed' => 'true',
            ],
        ]);

        $this->client->report(
            $trace,
            [
                'method' => 'QUEUE',
                'route' => sprintf('queue:%s:%s', $queue, class_basename($jobName)),
                'url' => sprintf('job://%s/%s', $connection, $jobName),
                'status_code' => 500,
            ],
            $durationMs,
            $exceptionData
        );

        $this->manager->setCurrentTrace(null);
    }

    private function getJobId(mixed $job): string
    {
        if (method_exists($job, 'getJobId')) {
            $id = $job->getJobId();
            if ($id) {
                return (string) $id;
            }
        }
        return spl_object_hash($job);
    }
}
