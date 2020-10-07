<?php

namespace Voice\Stomp\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Queue\Jobs\JobName;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Stomp\Transport\Frame;
use Voice\Stomp\Queue\Stomp\ConfigWrapper;
use Voice\Stomp\Queue\StompQueue;

class StompJob extends Job implements JobContract
{
    protected const DEFAULT_TRIES = 3;

    /**
     * The Stomp instance.
     */
    private StompQueue $stompQueue;

    /**
     * The Stomp frame instance.
     * Message is just Frame with SEND command
     */
    protected Frame $frame;

    public function __construct(Container $container, StompQueue $stompQueue, Frame $frame, string $queue)
    {
        $this->container = $container;
        $this->stompQueue = $stompQueue;
        $this->frame = $frame;
        $this->connectionName = 'stomp';
        $this->queue = $queue;
    }

    public function maxTries()
    {
        if (ConfigWrapper::get('auto_tries')) {
            return self::DEFAULT_TRIES;
        }

        return parent::maxTries();
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        $jobId = Arr::get($this->payload(), 'id', null);

        // Assemble JobId for non-Laravel events
        if (!$jobId) {
            $jobId = Arr::get($this->frame->getHeaders(), 'message-id', null);
        }

        return $jobId;
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->frame->body;
    }

    /**
     * Fire the job.
     *
     * @return void
     */
    public function fire()
    {
        $payload = $this->payload();

        if (array_key_exists('job', $payload)) {
            $this->handleLaravelJobs($payload);
            return;
        }

        $this->handleOutsideJobs($payload);
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return Arr::get($this->payload(), 'attempts', 1);
    }

    /**
     * Delete the job from the queue.
     *
     * @return void
     */
    public function delete()
    {
        parent::delete();

        Log::info('[STOMP] Deleting a message from queue: ' . print_r([
                'queue'   => $this->queue,
                'message' => $this->frame,
            ], true));
    }

    /**
     * Release the job back into the queue.
     *
     * @param int $delay
     * @return void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        $payload = $this->payload();

        $attempts = Arr::get($payload, 'attempts', 1) + 1;
        Arr::set($payload, 'attempts', $attempts);

        $payload = $this->calculateAutoBackoff($attempts, $payload);

        $this->stompQueue->recreate(json_encode($payload), $this->queue, $delay);
    }

    /**
     * Get the name of the queued job class.
     *
     * @return string
     */
    public function getName()
    {
        $jobName = Arr::get($this->payload(), 'job');

        // Assemble the name for non-Laravel events
        if (!$jobName) {
            $jobName = 'event';
            $subscribedTo = $this->stompQueue->client->getSubscriptions()->getSubscription($this->frame)->getDestination();

            if ($subscribedTo) {
                $jobName = 'stomp.' . str_replace('::', '.', $subscribedTo);
            }
        }

        return $jobName;
    }

    /**
     * Process an exception that caused the job to fail.
     *
     * @param \Throwable|null $e
     * @return void
     */
    protected function failed($e)
    {
        $payload = $this->payload();

        // Handle plain Laravel jobs
        if ($payload && array_key_exists('job', $payload)) {
            [$class, $method] = JobName::parse($payload['job']);

            if (method_exists($this->instance = $this->resolve($class), 'failed')) {
                $this->instance->failed($payload['data'], $e, $payload['id']);
            }
        }
    }

    protected function handleLaravelJobs(array $payload): void
    {
        [$class, $method] = JobName::parse($payload['job']);
        ($this->instance = $this->resolve($class))->{$method}($this, $payload['data']);
    }

    protected function handleOutsideJobs(array $payload): void
    {
        Event::dispatch($this->getName(), [
            'headers' => $this->frame->getHeaders(),
            'body'    => $payload
        ]);
    }

    /**
     * @param int $attempts
     * @param array $payload
     * @return array
     */
    protected function calculateAutoBackoff(int $attempts, array $payload): array
    {
        if (!ConfigWrapper::get('auto_backoff')) {
            return $payload;
        }

        $backoff = pow($attempts, ConfigWrapper::get('backoff_multiplier'));
        Arr::set($payload, 'backoff', $backoff);

        return $payload;
    }
}
