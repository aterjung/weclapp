<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Geccomedia\Weclapp\Models\YourModel; // Replace with an actual model from the package

// Example 1: Using the ignoreMissingProperties method with an Eloquent model
$model = YourModel::find(123); // Replace with an actual ID
$model->someProperty = 'new value';

// Method 1: Using the ignoreMissingProperties method
$model->ignoreMissingProperties()->save();

// Method 2: Using the options array
$model->save(['ignoreMissingProperties' => true]);

// Example 2: Using the ignoreMissingProperties method with a query builder
YourModel::where('id', 123)
    ->ignoreMissingProperties()
    ->update(['someProperty' => 'new value']);

// Example 3: Using the ignoreMissingProperties method with a raw query
\DB::connection('weclapp')
    ->table('yourEndpoint') // Replace with an actual endpoint
    ->where('id', 123)
    ->ignoreMissingProperties()
    ->update(['someProperty' => 'new value']);

echo "Examples of using ignoreMissingProperties parameter with Weclapp API.\n";
echo "Note: This is just an example and won't actually run without proper configuration.\n";
