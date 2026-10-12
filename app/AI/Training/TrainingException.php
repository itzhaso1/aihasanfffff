<?php

declare(strict_types=1);

namespace App\AI\Training;

use RuntimeException;

/**
 * Training library failure. Messages name the library-relative source only, never
 * absolute paths or document content.
 */
final class TrainingException extends RuntimeException
{
    public function __construct(
        public readonly TrainingIssueCode $issue,
        string $message,
        public readonly ?string $source = null,
    ) {
        parent::__construct(($source !== null ? $source.': ' : '').$message);
    }

    public static function because(TrainingIssueCode $issue, string $message, ?string $source = null): self
    {
        return new self($issue, $message, $source);
    }

    public static function missingDirectory(): self
    {
        return new self(TrainingIssueCode::MissingDirectory, 'The training directory does not exist.');
    }

    public static function invalidLimits(): self
    {
        return new self(TrainingIssueCode::InvalidLimits, 'Training limits must be positive and increase from file to library.');
    }

    public static function unexpectedFile(string $source): self
    {
        return new self(TrainingIssueCode::UnexpectedFile, 'File is not part of the training layout.', $source);
    }

    public static function outsideRoot(string $source): self
    {
        return new self(TrainingIssueCode::OutsideRoot, 'File resolves outside the training directory.', $source);
    }

    public static function duplicateId(string $id, string $source): self
    {
        return new self(TrainingIssueCode::DuplicateId, 'Training id "'.$id.'" is defined more than once.', $source);
    }

    public static function duplicateContent(string $source, string $other): self
    {
        return new self(TrainingIssueCode::DuplicateContent, 'Content is identical to '.$other.'.', $source);
    }

    public static function unknownInclude(string $id, string $source): self
    {
        return new self(TrainingIssueCode::UnknownInclude, 'Profile includes unknown or non-content document "'.$id.'".', $source);
    }

    public static function unknownDocument(string $id): self
    {
        return new self(TrainingIssueCode::UnknownDocument, 'Training document "'.$id.'" does not exist.');
    }

    public static function unknownProfile(string $id): self
    {
        return new self(TrainingIssueCode::UnknownProfile, 'Training profile "'.$id.'" does not exist.');
    }

    public static function tooLarge(TrainingIssueCode $issue, string $scope, int $limit): self
    {
        return new self($issue, $scope.' exceeds the '.$limit.' byte limit.');
    }

    public static function budgetExceeded(int $budget): self
    {
        return new self(TrainingIssueCode::BudgetExceeded, 'Always-on training rules do not fit the '.$budget.' character budget.');
    }

    public static function invalidQuery(string $reason): self
    {
        return new self(TrainingIssueCode::InvalidQuery, 'Invalid training query: '.$reason.'.');
    }
}
