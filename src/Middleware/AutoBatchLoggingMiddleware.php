<?php

namespace BIM\ActionLogger\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use BIM\ActionLogger\Facades\ActionLogger;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Facades\Activity as ActivityLog;

class AutoBatchLoggingMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip if the route is excluded
        if ($this->shouldSkipRoute($request)) {
            return $next($request);
        }

        // Start tracking request time
        $startTime = microtime(true);
        
        // Generate batch ID
        $batchId = $this->generateBatchId($request);
        
        // Get route information for processor selection
        $routeInfo = $this->getRouteInfo($request);
        
        // Start batch logging with route information
        ActionLogger::startBatch($batchId, $routeInfo);
        
        // Process the request
        $response = $next($request);
        
        // Calculate request duration
        $durationMs = (microtime(true) - $startTime) * 1000;
        
        // Get request & response data for tracking
        $requestData = $this->getRequestDataForTracking($request);
        $responseData = $this->getResponseDataForTracking($response);
        $responseStatus = method_exists($response, 'status') ? $response->status() : 200;
        
        // Log request tracking
        $this->logRequestTracking($requestData, $responseData, $responseStatus, $durationMs);
        
        // End batch logging if auto-end is enabled
        if ($this->shouldAutoEnd()) {
            ActionLogger::commitBatch();
        }
        
        return $response;
    }
    
    /**
     * Get request data for tracking.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function getRequestDataForTracking(Request $request): array
    {
        // Check if the request contains sensitive data
        if ($this->containsSensitiveData($request)) {
            return ['sensitive_data' => true];
        }

        // Get request data excluding sensitive fields
        $requestData = $request->except(['password', 'password_confirmation', 'token']);

        // Filter out uploaded files to prevent serialization errors
        return $this->filterUploadedFiles($requestData);
    }
    
    /**
     * Check if request contains sensitive data.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function containsSensitiveData(Request $request): bool
    {
        $sensitiveRoutes = config('action-logger.sensitive_routes', []);

        foreach ($sensitiveRoutes as $pattern) {
            if (Str::is($pattern, $request->path())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter uploaded files from request data.
     *
     * Replaces UploadedFile instances with safe metadata to prevent
     * serialization errors when temporary files are deleted.
     *
     * @param  array  $data
     * @return array
     */
    protected function filterUploadedFiles(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                // Replace file object with metadata
                $data[$key] = [
                    'uploaded_file' => true,
                    'original_name' => $value->getClientOriginalName(),
                    'size' => $value->getSize(),
                    'mime_type' => $value->getMimeType(),
                ];
            } elseif (is_array($value)) {
                // Recursively handle nested arrays (for multiple file uploads)
                $data[$key] = $this->filterUploadedFiles($value);
            }
        }

        return $data;
    }

    /**
     * Get response data for tracking.
     *
     * @param  mixed  $response
     * @return array
     */
    protected function getResponseDataForTracking($response): array
    {
        // Don't try to serialize the entire response
        if (method_exists($response, 'getData')) {
            return $response->getData(true);
        }
        
        return [];
    }

    /**
     * Check if the route should be skipped.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function shouldSkipRoute(Request $request): bool
    {
        $excludedRoutes = config('action-logger.excluded_routes', []);
        
        foreach ($excludedRoutes as $pattern) {
            if (Str::is($pattern, $request->path())) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate a batch ID for the request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    protected function generateBatchId(Request $request): string
    {
        // Check if a custom resolver is defined
        $resolver = config('action-logger.batch_name_resolver');
        
        if ($resolver && is_callable($resolver)) {
            return call_user_func($resolver, $request);
        }
        
        // Use route name as batch ID if available
        if ($request->route() && $request->route()->getName()) {
            return $request->route()->getName() . '_' . time();
        }
        
        // Use method and path as batch ID
        return strtolower($request->method()) . '_' . str_replace('/', '_', $request->path()) . '_' . time();
    }
    
    /**
     * Extract route information for processor selection.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function getRouteInfo(Request $request): array
    {
        $routeInfo = [
            'method' => $request->method(),
            'path' => $request->path(),
            'uri' => $request->route() ? $request->route()->uri() : null,
        ];

        // Add route name if available
        if ($request->route() && $request->route()->getName()) {
            $routeInfo['name'] = $request->route()->getName();
        }
        
        // Add action information if available
        if ($request->route() && isset($request->route()->getAction()['controller'])) {
            $controller = $request->route()->getAction()['controller'];
            // Extract class and method names
            if (is_string($controller) && str_contains($controller, '@')) {
                [$class, $method] = explode('@', $controller);
                $routeInfo['controller'] = $class;
                $routeInfo['action'] = $method;
            }
        }
        
        return $routeInfo;
    }

    /**
     * Determine if the batch should be automatically ended.
     *
     * @return bool
     */
    protected function shouldAutoEnd(): bool
    {
        return config('action-logger.batch.auto_end', true);
    }

    /**
     * Log request data
     * 
     * This logs basic request information as a regular activity
     * 
     * @param array $requestData Request data to track
     * @param array $responseData Response data to track
     * @param int|null $responseStatus HTTP response status
     * @param float|null $durationMs Request duration in milliseconds
     * @param string|null $logName Custom log name
     * @return Activity The created activity
     */
    public function logRequestTracking(
        array $requestData = [],
        array $responseData = [],
        ?int $responseStatus = null,
        ?float $durationMs = null,
        ?string $logName = null
    ): Activity {
        // Get request information
        $request = request();
        
        // Prepare properties 
        $properties = [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'request_data' => $requestData,
            'response_data' => $responseData,
            'response_status' => $responseStatus,
            'duration_ms' => $durationMs,
            'server' => $_SERVER['SERVER_NAME'] ?? null,
        ];
        
        // Determine if we're in a batch
        if (ActionLogger::hasBatch()) {
            $properties['batch_metadata'] = ActionLogger::getBatchMetadata();
        }
        
        // Create activity log
        $logger = \activity()->event('api_request') // Changed from request_tracking to a more descriptive name
            ->by(auth()->user())
            ->withProperties($properties);
            
        if ($logName) {
            $logger->useLog($logName);
        } else {
            $logger->useLog('api_activity');
        }
        
        // Create description
        $description = "HTTP {$request->method()} {$request->path()}";
        if ($responseStatus) {
            $description .= " [Status: {$responseStatus}]";
        }
        
        return $logger->log($description);
    }
} 