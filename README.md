# BIM ActionLogger

BIM ActionLogger is a powerful Laravel package that extends Spatie's Activity Log package to provide route-based batch activity logging. It allows you to automatically log user actions during HTTP requests and process them with custom processors.

## Features

- **Automatic Batch Logging**: Logs all activities in a single HTTP request as a batch
- **Route-Based Processing**: Select custom activity processors based on route names or patterns
- **Controller-Based Processing**: Select processors based on controller action names
- **Clean Activity Display**: Format activity logs in a human-readable way
- **Middleware Integration**: Simple integration with Laravel's middleware system

## Installation

You can install the package via composer:

```bash
composer require bimventures/action-logger
```

After installing, publish the configuration file:

```bash
php artisan vendor:publish --provider="BIM\ActionLogger\ActionLoggerServiceProvider" --tag="config"
```

You can also publish the language files:

```bash
php artisan vendor:publish --provider="BIM\ActionLogger\ActionLoggerServiceProvider" --tag="lang"
```

This will publish the translation files to your application. The package includes English and Arabic (RTL) languages by default.

Or publish all assets at once:

```bash
php artisan vendor:publish --provider="BIM\ActionLogger\ActionLoggerServiceProvider" --tag="action-logger"
```

## Configuration

After publishing the configuration, you can find it at `config/action-logger.php`. The configuration allows you to:

- Define default processors for activities
- Configure route-based processors
- Configure controller-based processors
- Set batch handling options

### Example Configuration

```php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Processors
    |--------------------------------------------------------------------------
    |
    | This is the default processor class that will be used when no specific
    | processor is found for an activity.
    |
    */
    'default_processors' => [
        'default' => \BIM\ActionLogger\Processors\BatchActionProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Processors
    |--------------------------------------------------------------------------
    |
    | Here you may define processors for specific routes.
    | The key should be the route name or pattern, and the value should be
    | the fully qualified class name of the processor.
    |
    | Example:
    | 'users.create' => \App\Processors\UserCreateProcessor::class,
    | 'users.*' => \App\Processors\UserProcessor::class,
    |
    */
    'route_processors' => [
        'users.update' => \App\Processors\UserUpdateProcessor::class,
        'projects.*' => \App\Processors\ProjectActionProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Controller Processors
    |--------------------------------------------------------------------------
    |
    | Here you may define processors for specific controller actions.
    | The key should be the controller@action, and the value should be
    | the fully qualified class name of the processor.
    |
    | Example:
    | 'App\Http\Controllers\UserController@store' => \App\Processors\UserCreateProcessor::class,
    |
    */
    'controller_processors' => [
        'App\Http\Controllers\UserController@update' => \App\Processors\UserUpdateProcessor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure batch logging behavior.
    |
    */
    'batch' => [
        'delete_discarded' => false,
        'auto_end' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Routes
    |--------------------------------------------------------------------------
    |
    | Routes that should be excluded from automatic batch logging.
    |
    */
    'excluded_routes' => [
        'horizon*',
        'telescope*',
        'debugbar*',
    ],
];
```

## Usage

### Middleware Setup

To enable automatic batch logging for HTTP requests, add the middleware to your HTTP kernel:

```php
// app/Http/Kernel.php

protected $middlewareGroups = [
    'web' => [
        // ... other middleware
        \BIM\ActionLogger\Middleware\AutoBatchLoggingMiddleware::class,
    ],
];
```

Or add it to specific routes:

```php
Route::middleware('auth', \BIM\ActionLogger\Middleware\AutoBatchLoggingMiddleware::class)
    ->group(function () {
        // Your routes here
    });
```

### Controller Registration

As of version 2.0, BIM ActionLogger no longer automatically registers API routes. This gives you more flexibility to register controllers according to your application's needs.

You can register the controllers in your application's route file:

```php
use BIM\ActionLogger\Http\Controllers\ActionLogController;
use BIM\ActionLogger\Http\Controllers\CauserActivityController;
use BIM\ActionLogger\Http\Controllers\ModelActivityController;
use BIM\ActionLogger\Http\Controllers\RouteActivityController;

// Define your preferred prefix and middleware
Route::prefix('api/activities')->middleware(['api', 'auth'])->group(function () {
    // Batch activity logs
    Route::get('/', [ActionLogController::class, 'index']);
    Route::get('/{batchUuid}', [ActionLogController::class, 'show']);

    // More routes...
});
```

For more details about controller registration, see the [Manual Controller Registration](docs/manual-controller-registration.md) documentation.

### Manual Batch Control

You can also manually control batches in your code:

```php
use BIM\ActionLogger\Facades\ActionLogger;

// Start a batch
ActionLogger::startBatch();

// Log activities inside the batch
activity()
    ->performedOn($user)
    ->log('updated');

activity()
    ->performedOn($profile)
    ->log('created');

// Commit the batch (saves all activities with the batch UUID)
ActionLogger::commitBatch();

// Or discard the batch if needed
ActionLogger::discardBatch();
```

### Creating Custom Processors

Create custom processors by extending the `BaseActionProcessor` class:

```php
namespace App\Processors;

use BIM\ActionLogger\Processors\BaseActionProcessor;
use Spatie\Activitylog\Models\Activity;

class UserActionProcessor extends BaseActionProcessor
{
    /**
     * Process the activities
     *
     * @return array
     */
    protected function processActivities(): array
    {
        // Get common data for all activities
        $user = $this->getCommonCauser();
        $action = $this->getCommonAction();
        
        // Get subject details
        $subject = $this->getCommonSubject();
        $subjectType = $subject ? class_basename($subject) : null;
        
        // Return processed data
        return [
            'type' => 'user_action',
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
            ] : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subject ? $subject->id : null,
            'description' => "User updated their profile",
            'changes' => $this->collectChanges(),
            'timestamp' => $this->getLatestTimestamp()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Format a batch message
     *
     * @param array $batchData Processed batch data
     * @return string Formatted message
     */
    public function formatBatchMessage(array $batchData): string
    {
        return "User {$batchData['user']['name']} {$batchData['action']} their profile.";
    }
}
```

### Example Response from ActionLogger

When you process activities using ActionLogger, you'll get structured data that can be used in your application. Here's an example of the response format:

```php
// Get activities for a user
$activities = ActionLogger::forSubject($user)->get();

// Process the activities
$result = ActionLogger::process($activities);
```

Example response from `process()`:

```php
[
    'batch_uuid' => '123e4567-e89b-12d3-a456-426614174000',
    'message' => 'John Doe updated 2 entities',
    'causer' => [
        'id' => 1,
        'name' => 'John Doe',
    ],
    'causer_type' => 'App\\Models\\User',
    'causer_id' => 1,
    'action' => 'updated',
    'entities' => [
        [
            'type' => 'User',
            'id' => 1,
            'changes' => [
                [
                    'attribute' => 'Name',
                    'old' => 'John',
                    'new' => 'John Doe',
                ],
                [
                    'attribute' => 'Email',
                    'old' => 'john@example.com',
                    'new' => 'johndoe@example.com',
                ],
            ],
        ],
        [
            'type' => 'Profile',
            'id' => 1,
            'changes' => [
                [
                    'attribute' => 'Phone',
                    'old' => null,
                    'new' => '+1234567890',
                ],
            ],
        ],
    ],
    'created_at' => '2023-05-15 14:30:45',
]
```

Example batch response:

```php
// Process a batch of activities
$batchUuid = '123e4567-e89b-12d3-a456-426614174000';
$result = ActionLogger::processBatch($activities, $batchUuid);
```

Example batch response:

```php
[
    'batch_uuid' => '123e4567-e89b-12d3-a456-426614174000',
    'message' => 'John Doe modified 3 entities',
    'causer' => [
        'id' => 1,
        'name' => 'John Doe',
    ],
    'causer_type' => 'App\\Models\\User',
    'causer_id' => 1,
    'action' => 'modified',
    'entities' => [
        [
            'type' => 'User',
            'id' => 1,
            'changes' => [
                [
                    'attribute' => 'Name',
                    'old' => 'John',
                    'new' => 'John Doe',
                ],
            ],
        ],
        [
            'type' => 'Profile',
            'id' => 1,
            'changes' => [
                [
                    'attribute' => 'Phone',
                    'old' => null,
                    'new' => '+1234567890',
                ],
            ],
        ],
        [
            'type' => 'Brief',
            'id' => 1,
            'changes' => [
                [
                    'attribute' => 'Brief Type',
                    'old' => 'final_brief',
                    'new' => 'first_brief',
                ],
            ],
        ],
    ],
    'created_at' => '2023-05-15 14:30:45',
]
```

### Formatting Messages

You can also get formatted messages for your activities:

```php
// Format a batch message
$message = ActionLogger::formatBatchMessage($activities);

// Example result: "John Doe updated their profile and added contact information."
```

## Real-World Example

Here's a controller example showing how ActionLogger works with a real application:

```php
namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Profile;
use Illuminate\Http\Request;
use BIM\ActionLogger\Facades\ActionLogger;

class UserController extends Controller
{
    public function update(Request $request, User $user)
    {
        // Start a batch
        ActionLogger::startBatch();
        
        try {
            // Update user data
            $user->update($request->only(['name', 'email']));
            
            // Update profile
            $user->profile->update($request->only(['phone', 'address']));
            
            // Commit the batch
            ActionLogger::commitBatch();
            
            return response()->json([
                'message' => 'User profile updated successfully',
                'user' => $user->fresh(['profile']),
            ]);
        } catch (\Exception $e) {
            // Discard the batch if there's an error
            ActionLogger::discardBatch();
            
            return response()->json([
                'message' => 'Failed to update user profile',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    
    public function activity(User $user)
    {
        // Get all activities for this user
        $activities = ActionLogger::forSubject($user)->get();
        
        // Process the activities
        $processedActivities = ActionLogger::process($activities);
        
        return response()->json([
            'activities' => $processedActivities,
        ]);
    }
}
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.