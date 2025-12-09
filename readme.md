# Laravel Weclapp

[![Latest Stable Version](https://poser.pugx.org/geccomedia/weclapp/v/stable)](https://packagist.org/packages/geccomedia/weclapp) [![Total Downloads](https://poser.pugx.org/geccomedia/weclapp/downloads)](https://packagist.org/packages/geccomedia/weclapp) [![License](https://poser.pugx.org/geccomedia/weclapp/license)](https://packagist.org/packages/geccomedia/weclapp)

This repo implements most of the Laravel Eloquent Model for the Weclapp web api.

## Installation Informations

Require this package with Composer

```
composer require geccomedia/weclapp
```

Add the variables to your .env

```
WECLAPP_BASE_URL=https://#your-sub-domain#.weclapp.com/webapp/api/v1/
WECLAPP_API_KEY=your-key
```

## Usage

Use existing models from "Geccomedia\\Weclapp\\Models"-Namespace.

Most Eloquent methods are implemented within the limitations of the web api.
```
<?php

use Geccomedia\\Weclapp\\Models\\Customer;

class YourClass
{
    public function yourFunction()
    {
        $customer = Customer::where('company', 'Your Company Name')
            ->firstOrFail();
```

### Referenced Entities and Eager Loading

Weclapp's API can return referenced entities (e.g. belongs-to targets) in the same response.
This package supports that in two ways:

- Directly request specific foreign keys via includeReferencedEntities()
- Use Laravel-style with() for belongsTo relations and let the package handle it automatically

Basic API usage (manual):
```
use Geccomedia\\Weclapp\\Models\\Article;

$articles = Article::query()
    ->select(['id','name','unitId','articleCategoryId'])
    ->includeReferencedEntities(['unitId','articleCategoryId'])
    ->get();

// Fetch the referencedEntities object from the last request
$referenced = Article::query()->getModel()->getConnection()->getLastReferencedEntities();
```

Laravel-like eager loading for belongsTo:
```
use Geccomedia\\Weclapp\\Models\\Article;

// Define relations on your model as usual, e.g. in Article model:
// public function unit() { return $this->belongsTo(\Geccomedia\\Weclapp\\Models\\Unit::class, 'unitId'); }
// public function articleCategory() { return $this->belongsTo(\Geccomedia\\Weclapp\\Models\\ArticleCategory::class, 'articleCategoryId'); }

$articles = Article::query()
    ->select(['id','name','unitId','articleCategoryId'])
    ->with(['unit', 'articleCategory'])
    ->get();

foreach ($articles as $article) {
    $unit = $article->unit; // Already hydrated from the same API call (or null if not present)
    $cat  = $article->articleCategory;
}
```

Notes:
- This auto-hydration is optimized for belongsTo relations where the parent has a foreign key like unitId.
- The Weclapp response groups referenced records under keys matching the foreign key without the trailing "Id" (e.g., unitId → unit).
- If a requested relation is not present in the API's referencedEntities block, Eloquent will fall back to its normal eager loading (which may perform additional API calls per relation).
- If you select specific columns, ensure the foreign key columns are included or use with(), which ensures they are selected.

### Eager loading without declaring relations (simple/inferred)

If you don't want to add relation methods on your models, you can still eager-load referenced entities using an inferred helper:

```
use Geccomedia\\Weclapp\\Models\\Article;

// Infer foreign keys as relationName . 'Id' (e.g. unit => unitId)
$articles = Article::query()
    ->select(['id','name','unitId','articleCategoryId'])
    ->withReferenced(['unit','articleCategory'])
    ->get();

foreach ($articles as $article) {
    // Access like a property. Since the relation is preloaded via the API response,
    // you can read it even without a relation method on the model.
    $unit = $article->unit;               // array of attributes (or null)
    $cat  = $article->articleCategory;    // array of attributes (or null)
}
```

Optionally, provide a map to hydrate as specific model classes:

```
use Geccomedia\\Weclapp\\Models\\Unit;
use Geccomedia\\Weclapp\\Models\\ArticleCategory;

$articles = Article::query()
    ->select(['id','name','unitId','articleCategoryId'])
    ->withReferenced([
        'unit' => Unit::class,
        'articleCategory' => ArticleCategory::class,
    ])
    ->get();

$firstUnit = $articles->first()->unit; // Unit model instance
```

- This is a convenience wrapper over Weclapp's includeReferencedEntities and does not cover has-many relations.
- Key inference uses the simple rule: relationName + 'Id' and referenced bucket named by removing trailing 'Id'.
- If an endpoint uses different naming (e.g. customerId → party), either use withReferenced(['customer']) which now understands common defaults, or define an explicit relation or per-model map (see below).

Advanced: per-model referenced bucket overrides
- You can define a property on your model to override how foreign keys map to referencedEntities buckets:

```php
class SalesOrder extends \Geccomedia\Weclapp\Model {
    protected $table = 'salesOrder';

    // Map FK to referenced bucket key returned by Weclapp
    protected array $referencedEntityBucketMap = [
        'customerId' => 'party', // Weclapp returns customer as party
        // 'supplierId' => 'party', etc.
    ];
}
```

- The package will use this map for eager hydration even when using withReferenced() or with().

## Custom models

Example:
```
<?php namespace Your\Custom\Namespace;

use Geccomedia\Weclapp\Model;

class CustomModel extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'custom-api-route';
}
```

## Mass assignments

If you want to do mass assignment please use [unguard](https://laravel.com/api/6.x/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.html#method_unguard) and [reguard](https://laravel.com/api/6.x/Illuminate/Database/Eloquent/Concerns/GuardsAttributes.html#method_reguard)

Example:

```
$customer = new \Geccomedia\Weclapp\Models\Customer();
\Geccomedia\Weclapp\Models\Customer::unguard();
$customer->fill(['partyType' => 'ORGANIZATION']);
\Geccomedia\Weclapp\Models\Customer::reguard();
```

## Sub Entities

Weclapp api has some models it views as sub entities to other entities.
For those cases we need to supply the main entity for the query with whereEntity($name, $id)

Example:

```
$comments = Comment::whereEntity('customer', 123)->orderByDesc()->get();
```
Without the call to "whereEntity" the api would complain that we are missing fields.
See #22 for more information.

## Logging

If you want to see the requests being made, you can use the Connections log

Example:

```
use Geccomedia\Weclapp\Connection;

app(Connection::class)->enableQueryLog();

\Geccomedia\Weclapp\Models\Customer::create(['name' => 'Test'])

app(Connection::class)->getQueryLog();
```

## License & Copyright

Copyright (c) 2017 Gecco Media GmbH

[License](LICENSE)
