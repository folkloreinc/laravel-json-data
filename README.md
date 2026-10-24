# laravel-json-data

References to models in the JSON columns of Eloquent models, and, soon, the schema of JSON columns.

```php
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;

// In a service provider: the keys of the references, like Eloquent's morph map
JsonData::map(['prize' => [Prize::class, 'handle']]);

// A model with a JSON column
protected $casts = ['data' => JsonDataCast::class];

$game->data = ['prizes' => [['prize' => $prize, 'quantity' => 1]]];
$game->save();
// stored: {"prizes": [{"prize": "prize://grand-prize", "quantity": 1}]}

$game->data['prizes'][0]['prize']; // the Prize model, resolved in one query per key
```

- A reference is stored as `{key}://{identifier}`, readable without the model that holds it.
- Values are turned into references when the model is saved, by a handler per key, which can check that the owner may refer to them.
- The references of a collection resolve together (`JsonData::load()`), and are listed without resolving them (`JsonData::references()`).
- The relations of the references are synced when the model is saved.
- `php artisan folklore:json-references:rewrite` rewrites the references of one key to another.

## Status

In development, before 1.0. The code comes from [`folklore/laravel-folklore`](https://github.com/folkloreinc/laravel-folklore) 2, which requires this package. Until alpha 2, a model declares its JSON columns through laravel-folklore, or through `JsonData::declareUsing()`; alpha 2 brings `jsonDataSchema()`, the package's own way to declare them. See the [roadmap](https://github.com/folkloreinc/laravel-json-data/issues/1).

Requires PHP 8.3+ and Laravel 12 or 13.

## Documentation

- [docs/json-data.md](docs/json-data.md): the specification
- [docs/decisions/](docs/decisions/): the decisions about JSON data
- [CHANGELOG.md](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE).
