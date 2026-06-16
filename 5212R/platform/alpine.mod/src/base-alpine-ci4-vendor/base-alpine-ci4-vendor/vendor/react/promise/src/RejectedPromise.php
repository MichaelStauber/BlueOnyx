<?php

namespace React\Promise;

class RejectedPromise implements PromiseInterface
{
    private $reason;

    public function __construct($reason)
    {
        $this->reason = $reason;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
    {
        if ($onRejected) {
            try {
                return resolve($onRejected($this->reason));
            } catch (\Throwable $exception) {
                return new RejectedPromise($exception);
            } catch (\Exception $exception) {
                return new RejectedPromise($exception);
            }
        }

        return $this;
    }

    public function otherwise(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function catch(callable $onRejected): PromiseInterface
    {
        return $this->then(null, $onRejected);
    }

    public function finally(callable $onFulfilledOrRejected): PromiseInterface
    {
        return $this->then($onFulfilledOrRejected, function ($reason) use ($onFulfilledOrRejected) {
            return resolve($onFulfilledOrRejected())->then(function () use ($reason) {
                return new RejectedPromise($reason);
            });
        });
    }

    public function done(callable $onFulfilled = null, callable $onRejected = null, callable $onProgress = null): void
    {
        $this->then($onFulfilled, $onRejected, $onProgress)->otherwise(function ($reason) {
            \React\Promise\queue()->run();
            throw $reason;
        });
    }

    public function always(callable $onFulfilledOrRejected): PromiseInterface
    {
        return $this->finally($onFulfilledOrRejected);
    }

    public function cancel(): void
    {
        // no-op
    }
}

