<?php

use BIM\ActionLogger\Services\ActionLoggerService;
use BIM\ActionLogger\Contracts\ActionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Spatie\Activitylog\Facades\LogBatch;
use Spatie\Activitylog\Models\Activity;
use BIM\ActionLogger\Resources\ActionLogResource;

if (! function_exists('action_logger')) {
    /**
     * Get the action logger service instance.
     */
    function action_logger(): ActionLoggerService
    {
        return App::make('action-logger');
    }
}

if (! function_exists('log_action')) {
    /**
     * Quickly log an action.
     *
     * @param Model|array|Collection $subjects The subject(s) of the action
     * @param ActionInterface $action The action to log
     * @param Model|null $causer The causer of the action
     * @param array $properties Additional properties to log
     * @param string|null $logName Custom log name
     */
    function log_action(
        Model|array|Collection $subjects,
        ActionInterface $action,
        ?Model $causer = null,
        array $properties = [],
        ?string $logName = null
    ): void {
        action_logger()->logAction(
            $subjects,
            $action->value(),
            $action->getTranslationKey(),
            $causer,
            $properties,
            $logName
        );
    }
}

if (! function_exists('get_action_logs')) {
    /**
     * Get activity logs with optional query modifications.
     *
     * @param callable|null $queryCallback Optional callback to modify the query
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    function get_action_logs(?callable $queryCallback = null)
    {
        $query = Activity::query();
        
        if ($queryCallback) {
            $query = $queryCallback($query);
        }

        return ActionLogResource::collection($query->get());
    }
}

if (! function_exists('start_batch')) {
    /**
     * Start a new batch of activities.
     */
    function start_batch(): void
    {
        LogBatch::startBatch();
    }
}

if (! function_exists('end_batch')) {
    /**
     * End the current batch of activities.
     */
    function end_batch(): void
    {
        LogBatch::endBatch();
    }
}

if (! function_exists('get_batch_uuid')) {
    /**
     * Get the UUID of the current batch.
     */
    function get_batch_uuid(): ?string
    {
        return LogBatch::getUuid();
    }
}

if (! function_exists('is_batch_open')) {
    /**
     * Check if a batch is currently open.
     */
    function is_batch_open(): bool
    {
        return LogBatch::isOpen();
    }
}

if (! function_exists('set_batch')) {
    /**
     * Set the UUID for the current batch.
     */
    function set_batch(string $uuid): void
    {
        LogBatch::setBatch($uuid);
    }
}

if (! function_exists('within_batch')) {
    /**
     * Execute a callback within a batch context.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    function within_batch(\Closure $callback): mixed
    {
        return LogBatch::withinBatch($callback);
    }
}
