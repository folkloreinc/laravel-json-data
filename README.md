# laravel-json-data

References to models in the JSON columns of Eloquent models, and the schema of JSON columns.

```php
use Folklore\JsonData\Contracts\HasJsonDataSchema;
use Folklore\JsonData\JsonData;
use Folklore\JsonData\JsonDataCast;
use Folklore\JsonData\Schema\Json;
use Folklore\JsonData\Schema\ObjectType;

// In a service provider: the keys of the references, like Eloquent's morph map
JsonData::map(['prize' => [Prize::class, 'handle']]);

// A model with a JSON column, and its schema
class Game extends Model implements HasJsonDataSchema
{
    protected $casts = ['data' => JsonDataCast::class];

    public function jsonDataSchema(string $attribute): ?ObjectType
    {
        return $attribute === 'data' ? Json::object([
            'title' => Json::string()->translatable()->extract('title_{locale}'),
            'prizes' => Json::list(Json::object([
                'prize' => Json::reference('prize')->relation('prizes'),
                'quantity' => Json::integer()->default(1),
            ])),
        ])->additional(false) : null;
    }
}

JsonData::fill($game, ['title' => 'Quiz', 'prizes' => [['prize' => $prize]]]);
$game->save();
// stored: {"title": {"fr": "Quiz"}, "prizes": [{"prize": "prize://grand-prize", "quantity": 1}]}

$game->data['prizes'][0]['prize']; // the Prize model, resolved in one query per key
```

- A schema per JSON column declares its references, the values copied into columns, and the keys of a flat input that `JsonData::fill()` puts into it, refusing the others.
- A reference is stored as `{key}://{identifier}`, readable without the model that holds it.
- Values are turned into references when the model is saved, by a handler per key, which can check that the owner may refer to them.
- The references of a collection resolve together (`JsonData::load()`), and are listed without resolving them (`JsonData::references()`).
- The relations of the references are synced when the model is saved.
- `php artisan folklore:json-references:rewrite` rewrites the references of one key to another.

## Status

In development, before 1.0. The code comes from [`folklore/laravel-folklore`](https://github.com/folkloreinc/laravel-folklore) 2, which requires this package. A model declares its JSON columns with `jsonDataSchema()`; laravel-folklore feeds its 1.x declarations through `JsonData::declareUsing()`. The schema's validation rules and JSON Schema export are its next slices. See the [roadmap](https://github.com/folkloreinc/laravel-json-data/issues/1).

Requires PHP 8.3+ and Laravel 12 or 13.

## Documentation

- [docs/json-data.md](docs/json-data.md): the specification
- [docs/decisions/](docs/decisions/): the decisions about JSON data
- [CHANGELOG.md](CHANGELOG.md)

## License

MIT. See [LICENSE](LICENSE).
