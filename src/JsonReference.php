<?php

namespace Folklore\Eloquent;

use Folklore\Contracts\Eloquent\ReferenceHandler;
use Folklore\Eloquent\Exceptions\InvalidJsonReference;
use Illuminate\Database\Eloquent\Model;

/**
 * What a reference key points to: a model, the column that identifies it, and the
 * handler of its values.
 *
 *     JsonReference::to(Media::class)->by('uid')->using(MediaReferences::class)
 */
final readonly class JsonReference
{
    /**
     * @param  class-string<Model>  $model
     * @param  string|null  $column  Null: the model's key name.
     * @param  class-string<ReferenceHandler>|null  $handler  Null: the default handler.
     */
    private function __construct(
        public string $model,
        public ?string $column = null,
        public ?string $handler = null,
    ) {}

    /**
     * @param  string  $model  An Eloquent model class.
     */
    public static function to(string $model): self
    {
        if (! is_a($model, Model::class, true)) {
            throw new InvalidJsonReference("[{$model}] is not an Eloquent model.");
        }

        return new self($model);
    }

    /**
     * Reads a key's target as `JsonData::map()` takes it: a model class (by its key name),
     * a `[model, column]` pair, or a JsonReference.
     */
    public static function from(string $key, mixed $definition): self
    {
        if ($definition instanceof self) {
            return $definition;
        }
        if (is_string($definition) && is_a($definition, Model::class, true)) {
            return self::to($definition);
        }
        if (
            is_array($definition)
            && array_is_list($definition)
            && count($definition) === 2
            && is_string($definition[0])
            && is_a($definition[0], Model::class, true)
            && is_string($definition[1])
            && $definition[1] !== ''
        ) {
            return self::to($definition[0])->by($definition[1]);
        }

        throw InvalidJsonReference::invalidTarget($key);
    }

    /**
     * The column whose value is the reference's identifier.
     */
    public function by(string $column): self
    {
        return new self($this->model, $column, $this->handler);
    }

    /**
     * @param  string  $handler  A class that implements ReferenceHandler.
     */
    public function using(string $handler): self
    {
        if (! is_a($handler, ReferenceHandler::class, true)) {
            throw new InvalidJsonReference("[{$handler}] does not implement ".ReferenceHandler::class.'.');
        }

        return new self($this->model, $this->column, $handler);
    }

    /**
     * The identifier column: the one given with `by()`, or the model's key name.
     */
    public function column(): string
    {
        return $this->column ?? (new $this->model)->getKeyName();
    }

    /**
     * Whether a model, or a model class, is the target's model or extends it.
     *
     * @param  Model|class-string<Model>  $model
     */
    public function accepts(Model|string $model): bool
    {
        return is_a($model, $this->model, true);
    }

    /**
     * The identifier of a model for this target, or null when its column is empty.
     */
    public function identifierOf(Model $model): ?string
    {
        $value = $model->getAttribute($this->column());

        return is_int($value) || (is_string($value) && $value !== '') ? (string) $value : null;
    }

    public function equals(self $other): bool
    {
        return $this->model === $other->model
            && $this->column() === $other->column()
            && $this->handler === $other->handler;
    }

    public function describe(): string
    {
        return $this->model.' by '.$this->column().($this->handler !== null ? ' using '.$this->handler : '');
    }
}
