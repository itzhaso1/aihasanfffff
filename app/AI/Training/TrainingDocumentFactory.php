<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Turns one raw training file into a validated {@see TrainingDocument}. Structural
 * validation only; semantic checks (overlap, live facts) belong to the linter.
 */
final class TrainingDocumentFactory
{
    private const REQUIRED_FIELDS = ['id', 'type', 'version', 'priority', 'always', 'max_chars'];

    private const OPTIONAL_FIELDS = ['applies_to', 'title', 'includes'];

    private const ID_PATTERN = '/^(rule|example|scenario|playbook|knowledge|profile)\.[a-z0-9_]+(\.[a-z0-9_]+)*$/';

    private const SELECTOR_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const MAX_TITLE_LENGTH = 120;

    private const EXECUTABLE_MARKERS = ['<?php', '<?=', '<%'];

    public function __construct(
        private readonly TrainingLimits $limits = new TrainingLimits,
        private readonly TrainingFrontMatterParser $parser = new TrainingFrontMatterParser,
    ) {}

    public function create(TrainingSourceFile $file, string $raw): TrainingDocument
    {
        $source = $file->relativePath;
        [$fields, $body] = $this->parser->parse($this->normalize($raw, $source), $source);
        $this->assertKnownFields($fields, $source);

        $type = $this->type($fields, $file, $source);
        $id = $this->id($fields, $type, $source);
        $appliesTo = $this->appliesTo($fields['applies_to'] ?? [], $source);
        $always = $this->always($fields, $type, $appliesTo, $source);
        $maxChars = $this->maxChars($fields, $source);
        $includes = $this->includes($fields, $type, $source);
        $content = $this->content($body, $type, $maxChars, $source);
        $this->assertSituationalSelectors($type, $appliesTo, $source);

        $metadata = [
            'id' => $id,
            'type' => $type->value,
            'version' => $this->version($fields, $source),
            'priority' => $this->priority($fields, $source)->value,
            'always' => $always,
            'applies_to' => $appliesTo->toArray(),
            'max_chars' => $maxChars,
            'title' => $this->title($fields, $source),
            'includes' => $includes,
        ];

        return new TrainingDocument(
            id: $id,
            type: $type,
            version: $metadata['version'],
            priority: TrainingPriority::from($metadata['priority']),
            always: $always,
            appliesTo: $appliesTo,
            maxChars: $maxChars,
            content: $content,
            contentHash: hash('sha256', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n".$content),
            bodyHash: hash('sha256', $content),
            source: $source,
            title: $metadata['title'],
            includes: $includes,
        );
    }

    private function normalize(string $raw, string $source): string
    {
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw TrainingException::because(TrainingIssueCode::InvalidEncoding, 'File is not valid UTF-8.', $source);
        }
        foreach (self::EXECUTABLE_MARKERS as $marker) {
            if (str_contains($raw, $marker)) {
                throw TrainingException::because(TrainingIssueCode::ExecutableContent, 'Training files are Markdown only.', $source);
            }
        }
        $text = str_starts_with($raw, "\u{FEFF}") ? substr($raw, 3) : $raw;

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function assertKnownFields(array $fields, string $source): void
    {
        foreach (array_keys($fields) as $key) {
            if (! in_array($key, [...self::REQUIRED_FIELDS, ...self::OPTIONAL_FIELDS], true)) {
                throw $this->fail(TrainingIssueCode::UnknownField, 'Unknown front matter field "'.$key.'".', $source);
            }
        }
        foreach (self::REQUIRED_FIELDS as $key) {
            if (! array_key_exists($key, $fields)) {
                throw $this->fail(TrainingIssueCode::MissingField, 'Missing front matter field "'.$key.'".', $source);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function type(array $fields, TrainingSourceFile $file, string $source): TrainingType
    {
        $type = is_string($fields['type']) ? TrainingType::tryFrom($fields['type']) : null;
        if ($type === null) {
            throw $this->fail(TrainingIssueCode::InvalidType, 'Unsupported training type.', $source);
        }
        if ($type !== $file->folderType) {
            throw $this->fail(TrainingIssueCode::TypeFolderMismatch, 'Type "'.$type->value.'" does not belong in '.$file->folderType->folder().'/.', $source);
        }

        return $type;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function id(array $fields, TrainingType $type, string $source): string
    {
        $id = $fields['id'];
        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1 || ! str_starts_with($id, $type->value.'.')) {
            throw $this->fail(TrainingIssueCode::InvalidId, 'Id must be "'.$type->value.'.<name>" in lowercase dotted form.', $source);
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function version(array $fields, string $source): int
    {
        if (! is_int($fields['version']) || $fields['version'] < 1) {
            throw $this->fail(TrainingIssueCode::InvalidVersion, 'Version must be a positive integer.', $source);
        }

        return $fields['version'];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function priority(array $fields, string $source): TrainingPriority
    {
        $priority = is_string($fields['priority']) ? TrainingPriority::tryFrom($fields['priority']) : null;

        return $priority ?? throw $this->fail(TrainingIssueCode::InvalidPriority, 'Priority must be critical, high, normal or low.', $source);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function always(array $fields, TrainingType $type, TrainingAppliesTo $appliesTo, string $source): bool
    {
        if (! is_bool($fields['always'])) {
            throw $this->fail(TrainingIssueCode::InvalidAlways, 'Always must be true or false.', $source);
        }
        if ($fields['always'] && ($type !== TrainingType::Rule || ! $appliesTo->isEmpty())) {
            throw $this->fail(TrainingIssueCode::InvalidAlways, 'Only rules without selectors can be always-on.', $source);
        }

        return $fields['always'];
    }

    private function appliesTo(mixed $raw, string $source): TrainingAppliesTo
    {
        if (! is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw $this->fail(TrainingIssueCode::InvalidAppliesTo, 'applies_to must be a map of selector lists.', $source);
        }
        $values = [];
        foreach ($raw as $dimension => $list) {
            if (TrainingDimension::tryFrom((string) $dimension) === null) {
                throw $this->fail(TrainingIssueCode::InvalidAppliesTo, 'Unknown applies_to dimension "'.$dimension.'".', $source);
            }
            if (! is_array($list) || $list === [] || ! array_is_list($list)) {
                throw $this->fail(TrainingIssueCode::InvalidAppliesTo, 'applies_to.'.$dimension.' must be a non-empty list.', $source);
            }
            foreach ($list as $value) {
                if (! is_string($value) || preg_match(self::SELECTOR_PATTERN, $value) !== 1) {
                    throw $this->fail(TrainingIssueCode::InvalidAppliesTo, 'applies_to values must be snake_case identifiers.', $source);
                }
            }
            $values[(string) $dimension] = $list;
        }

        return new TrainingAppliesTo($values);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function maxChars(array $fields, string $source): int
    {
        $max = $fields['max_chars'];
        if (! is_int($max) || $max < TrainingLimits::MIN_DOCUMENT_CHARS || $max > $this->limits->maxDocumentChars) {
            throw $this->fail(TrainingIssueCode::InvalidMaxChars, 'max_chars must be between '.TrainingLimits::MIN_DOCUMENT_CHARS.' and '.$this->limits->maxDocumentChars.'.', $source);
        }

        return $max;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return list<string>
     */
    private function includes(array $fields, TrainingType $type, string $source): array
    {
        $includes = $fields['includes'] ?? null;
        if ($type !== TrainingType::Profile) {
            if ($includes !== null) {
                throw $this->fail(TrainingIssueCode::InvalidIncludes, 'Only profiles may declare includes.', $source);
            }

            return [];
        }
        if (! is_array($includes) || $includes === [] || ! array_is_list($includes)) {
            throw $this->fail(TrainingIssueCode::InvalidIncludes, 'A profile must include a non-empty list of document ids.', $source);
        }
        foreach ($includes as $include) {
            if (! is_string($include) || preg_match(self::ID_PATTERN, $include) !== 1 || str_starts_with($include, 'profile.')) {
                throw $this->fail(TrainingIssueCode::InvalidIncludes, 'Profile includes must be content document ids.', $source);
            }
        }
        if (count(array_unique($includes)) !== count($includes)) {
            throw $this->fail(TrainingIssueCode::InvalidIncludes, 'Profile includes must not repeat.', $source);
        }

        return $includes;
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function title(array $fields, string $source): ?string
    {
        $title = $fields['title'] ?? null;
        if ($title !== null && (! is_string($title) || mb_strlen($title) > self::MAX_TITLE_LENGTH)) {
            throw $this->fail(TrainingIssueCode::InvalidTitle, 'Title must be text of at most '.self::MAX_TITLE_LENGTH.' characters.', $source);
        }

        return $title;
    }

    private function content(string $body, TrainingType $type, int $maxChars, string $source): string
    {
        $content = trim($body);
        if ($content === '') {
            throw $this->fail(TrainingIssueCode::EmptyContent, 'Document body is empty.', $source);
        }
        if (mb_strlen($content) > $maxChars) {
            throw $this->fail(TrainingIssueCode::ContentTooLong, 'Body is longer than its max_chars of '.$maxChars.'.', $source);
        }
        foreach ($type->requiredHeadings() as $heading) {
            if (preg_match('/^## '.preg_quote($heading, '/').'\s*$/m', $content) !== 1) {
                throw $this->fail(TrainingIssueCode::MissingHeading, 'Missing "## '.$heading.'" section.', $source);
            }
        }

        return $content;
    }

    private function assertSituationalSelectors(TrainingType $type, TrainingAppliesTo $appliesTo, string $source): void
    {
        if ($type->isSituational() && $appliesTo->values(TrainingDimension::Intents) === []) {
            throw $this->fail(TrainingIssueCode::InvalidAppliesTo, 'Examples and scenarios must declare applies_to.intents.', $source);
        }
    }

    private function fail(TrainingIssueCode $issue, string $message, string $source): TrainingException
    {
        return TrainingException::because($issue, $message, $source);
    }
}
