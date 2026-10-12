<?php

declare(strict_types=1);

namespace App\AI\Training;

use App\AI\Constitution\ConstitutionLoader;
use App\AI\Core\Prompt\PromptSection;

/**
 * Full library check. Unlike the loader, it reports every problem instead of stopping
 * at the first, and adds semantic checks: constitution overlap, live facts in
 * knowledge, sensitive data and unknown selector values. Read-only on source files.
 */
final class TrainingLinter
{
    private readonly TrainingSource $source;

    private readonly TrainingDocumentFactory $factory;

    public function __construct(
        private readonly TrainingLimits $limits = new TrainingLimits,
        ?string $directory = null,
        private readonly ConstitutionLoader $constitution = new ConstitutionLoader,
        private readonly TrainingContentScanner $scanner = new TrainingContentScanner,
    ) {
        $this->source = new TrainingSource($limits, $directory);
        $this->factory = new TrainingDocumentFactory($limits);
    }

    public function lint(): TrainingLintReport
    {
        try {
            $scan = $this->source->scan();
        } catch (TrainingException $exception) {
            return new TrainingLintReport([TrainingLintIssue::fromException($exception)], 0);
        }

        $issues = [
            ...array_map(static fn (string $folder): TrainingLintIssue => new TrainingLintIssue(TrainingIssueCode::MissingFolder, $folder.'/', 'Required training folder is missing.'), $scan->missingFolders),
            ...array_map(static fn (TrainingException $problem): TrainingLintIssue => TrainingLintIssue::fromException($problem), $scan->problems),
        ];

        $overlap = new ConstitutionOverlapDetector(array_map(
            static fn (PromptSection $section): string => $section->content,
            $this->constitution->current()->sections,
        ));

        $documents = [];
        $sizes = [];
        foreach ($scan->files as $file) {
            try {
                $raw = $this->source->read($file);
                $document = $this->factory->create($file, $raw);
            } catch (TrainingException $exception) {
                $issues[] = TrainingLintIssue::fromException($exception);

                continue;
            }
            $sizes[] = [$file->folderType, strlen($raw)];
            $documents[] = $document;
            array_push($issues, ...$this->documentIssues($document, $overlap));
        }

        array_push($issues, ...$this->libraryIssues($documents, $sizes));

        return new TrainingLintReport($issues, count($documents));
    }

    /**
     * @return list<TrainingLintIssue>
     */
    private function documentIssues(TrainingDocument $document, ConstitutionOverlapDetector $overlap): array
    {
        $issues = $this->scanner->scan($document);
        foreach ($overlap->overlaps($document) as $law) {
            $issues[] = new TrainingLintIssue(TrainingIssueCode::ConstitutionOverlap, $document->source, 'Restates the constitution law "'.mb_substr($law, 0, 80).'"; reference the law instead.');
        }
        foreach (TrainingDimension::cases() as $dimension) {
            foreach ($document->appliesTo->values($dimension) as $value) {
                if (! TrainingVocabulary::knows($dimension, $value)) {
                    $issues[] = new TrainingLintIssue(TrainingIssueCode::UnknownSelector, $document->source, 'Unknown '.$dimension->value.' selector "'.$value.'".');
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<TrainingDocument>  $documents
     * @param  list<array{0: TrainingType, 1: int}>  $sizes
     * @return list<TrainingLintIssue>
     */
    private function libraryIssues(array $documents, array $sizes): array
    {
        $issues = [];
        $ids = [];
        $bodies = [];
        foreach ($documents as $document) {
            if (isset($ids[$document->id])) {
                $issues[] = new TrainingLintIssue(TrainingIssueCode::DuplicateId, $document->source, 'Id "'.$document->id.'" is also defined in '.$ids[$document->id].'.');
            }
            if (isset($bodies[$document->bodyHash])) {
                $issues[] = new TrainingLintIssue(TrainingIssueCode::DuplicateContent, $document->source, 'Content is identical to '.$bodies[$document->bodyHash].'.');
            }
            $ids[$document->id] ??= $document->source;
            $bodies[$document->bodyHash] ??= $document->source;
        }

        $contentTypes = [];
        foreach ($documents as $document) {
            $contentTypes[$document->id] = $document->type;
        }
        foreach ($documents as $document) {
            foreach ($document->includes as $include) {
                if (! isset($contentTypes[$include]) || ! $contentTypes[$include]->isPromptContent()) {
                    $issues[] = new TrainingLintIssue(TrainingIssueCode::UnknownInclude, $document->source, 'Profile includes unknown document "'.$include.'".');
                }
            }
        }

        return [...$issues, ...$this->sizeIssues($sizes)];
    }

    /**
     * @param  list<array{0: TrainingType, 1: int}>  $sizes
     * @return list<TrainingLintIssue>
     */
    private function sizeIssues(array $sizes): array
    {
        $issues = [];
        $byType = [];
        $total = 0;
        foreach ($sizes as [$type, $bytes]) {
            $byType[$type->value] = ($byType[$type->value] ?? 0) + $bytes;
            $total += $bytes;
        }
        foreach ($byType as $type => $bytes) {
            if ($bytes > $this->limits->maxTypeBytes) {
                $issues[] = new TrainingLintIssue(TrainingIssueCode::TypeTooLarge, TrainingType::from($type)->folder().'/', 'Folder exceeds the '.$this->limits->maxTypeBytes.' byte limit.');
            }
        }
        if ($total > $this->limits->maxLibraryBytes) {
            $issues[] = new TrainingLintIssue(TrainingIssueCode::LibraryTooLarge, null, 'Library exceeds the '.$this->limits->maxLibraryBytes.' byte limit.');
        }

        return $issues;
    }
}
