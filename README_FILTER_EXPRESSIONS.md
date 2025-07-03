# Filter Expressions Support for Weclapp API

This extension adds support for the Weclapp API's Filter Expressions feature, which allows for more complex filtering capabilities beyond the basic filters.

## What are Filter Expressions?

Filter Expressions allow you to specify complex filter expressions that can combine multiple conditions and express relations between properties. They provide more powerful filtering capabilities than the basic filters, including:

- String concatenation and comparison
- Arithmetic operations
- Case-insensitive comparison
- Logical operators (and, or, not)
- Functions like lower(), trim(), length()
- Predicates on properties

## Usage

### Basic Usage

```php
use Geccomedia\Weclapp\Query\Builder;

$query = new Builder($connection, $grammar, $processor);
$query->from('party')
    ->filterExpression('(lower(contacts.firstName + " " + contacts.lastName) = "john doe") and (lastModifiedDate >= "2022-01-01T00:00:00Z")');
```

### Combining with Regular Where Clauses

Filter expressions can be combined with regular where clauses:

```php
$query->from('party')
    ->where('partyType', '=', 'ORGANIZATION')
    ->filterExpression('(salesChannel in ["NET1", "NET4", "NET5"])');
```

### Using Multiple Filter Expressions

You can use multiple filter expressions, which will be combined with AND logic:

```php
$query->from('party')
    ->filterExpression('(not (contacts.firstName null))')
    ->filterExpression('(lower(contacts.firstName) ~ "%john%")');
```

## Examples of Filter Expressions

```
// Enum literals are specified as string literals
(salesChannel in ["NET1", "NET4", "NET5"]) and (partyType = "ORGANIZATION")

// Normal arithmetic operations are supported
(unitPrice + unitPrice * salesTax) <= 49.99

// Elementary functions
length(trim(notes)) <= 140

// Conditions can be combined with logical operators
(not (contacts.firstName null)) or (currencyId = 4711)

// Case insensitive comparison
lower(contacts.firstName) ~ '%"special"%'

// Properties can be affixed with predicates
(values[locale = "de"].text ~ "Liefer%") and (values[locale = "en"].text ~ "Ship%")
```

## Available Operations

### Arithmetic Operations

- Addition (+): Works with integer, float, string
- Subtraction (-): Works with integer, float
- Multiplication (*): Works with integer, float
- Division (/): Works with integer, float
- Negation (-): Works with integer, float

### Comparison Operations

- Equals (=): Works with integer, float, string, boolean, date, enum
- Not Equals (!=): Works with integer, float, string, boolean, date, enum
- Less Than (<): Works with integer, float, date
- Greater Than (>): Works with integer, float, date
- Less Than or Equal (<=): Works with integer, float, date
- Greater Than or Equal (>=): Works with integer, float, date
- Like (~): Works with string
- Not Like (!~): Works with string
- In (in): Works with array
- Not In (not in): Works with array

### Logical Operations

- And (and)
- Or (or)
- Not (not)

## Note

Filter Expressions is marked as a beta feature in the Weclapp API documentation. Use with caution and test thoroughly.
