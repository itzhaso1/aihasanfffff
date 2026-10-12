<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Typed read access to the training library. The index is rebuilt only when the byte
 * fingerprint of the library changes, so edits are never hidden by the cache.
 */
final class TrainingRepository
{
    private ?TrainingIndex $index = null;

    private ?string $fingerprint = null;

    public function __construct(
        private readonly TrainingLoader $loader = new TrainingLoader,
    ) {}

    /**
     * @return list<TrainingDocument>
     */
    public function list(): array
    {
        return $this->index()->all();
    }

    public function get(string $id): TrainingDocument
    {
        return $this->findById($id) ?? throw TrainingException::unknownDocument($id);
    }

    public function findById(string $id): ?TrainingDocument
    {
        return $this->index()->find($id);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByType(TrainingType $type): array
    {
        return $this->index()->ofType($type);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listBySkill(string $skill): array
    {
        return $this->index()->withSelector(TrainingDimension::Skills, $skill);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByIntent(string $intent): array
    {
        return $this->index()->withSelector(TrainingDimension::Intents, $intent);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByDialect(string $dialect): array
    {
        return $this->index()->withSelector(TrainingDimension::Dialects, $dialect);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByLanguage(string $language): array
    {
        return $this->index()->withSelector(TrainingDimension::Languages, $language);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByRole(string $role): array
    {
        return $this->index()->withSelector(TrainingDimension::Roles, $role);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function listByDomain(string $domain): array
    {
        return $this->index()->withSelector(TrainingDimension::Domains, $domain);
    }

    public function libraryVersion(): string
    {
        return $this->index()->version();
    }

    public function index(): TrainingIndex
    {
        $fingerprint = $this->loader->fingerprint();
        if ($this->index === null || $this->fingerprint !== $fingerprint) {
            $this->index = $this->loader->load();
            $this->fingerprint = $fingerprint;
        }

        return $this->index;
    }

    /** Drops the cached index; the next access reloads and revalidates the library. */
    public function refresh(): TrainingIndex
    {
        $this->index = null;

        return $this->index();
    }
}
