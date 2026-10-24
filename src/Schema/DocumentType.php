<?php

namespace Folklore\JsonData\Schema;

use Folklore\JsonData\Exceptions\InvalidJsonData;

/**
 * A document, such as ProseMirror JSON, kept as it is given. `references()` declares the
 * references it may hold at any depth, as a `**` path (docs/json-data.md, "Paths").
 */
class DocumentType extends Type
{
    /**
     * @var list<ReferenceType>
     */
    protected array $references = [];

    /**
     * The references the document may hold: keys (`'media'`), or reference nodes, to sync
     * them to a relation (`Json::reference('media')->relation('medias')`).
     */
    public function references(string|ReferenceType ...$references): static
    {
        foreach ($references as $reference) {
            $reference = is_string($reference) ? new ReferenceType($reference) : $reference;
            if ($reference->isTranslatable()) {
                throw InvalidJsonData::invalidSchema('The references of a document are not translatable.');
            }
            $this->references[] = $reference;
        }

        return $this;
    }

    /**
     * @return list<ReferenceType>
     */
    public function getReferences(): array
    {
        return $this->references;
    }
}
