<?php

declare(strict_types=1);

namespace App\AI\Training;

enum TrainingIssueCode: string
{
    case MissingDirectory = 'missing_directory';
    case MissingFolder = 'missing_folder';
    case UnexpectedFile = 'unexpected_file';
    case OutsideRoot = 'outside_root';
    case Unreadable = 'unreadable';
    case FileTooLarge = 'file_too_large';
    case TypeTooLarge = 'type_too_large';
    case LibraryTooLarge = 'library_too_large';
    case InvalidEncoding = 'invalid_encoding';
    case ExecutableContent = 'executable_content';
    case MissingFrontMatter = 'missing_front_matter';
    case InvalidFrontMatter = 'invalid_front_matter';
    case UnknownField = 'unknown_field';
    case MissingField = 'missing_field';
    case InvalidId = 'invalid_id';
    case InvalidType = 'invalid_type';
    case TypeFolderMismatch = 'type_folder_mismatch';
    case InvalidVersion = 'invalid_version';
    case InvalidPriority = 'invalid_priority';
    case InvalidAlways = 'invalid_always';
    case InvalidAppliesTo = 'invalid_applies_to';
    case InvalidMaxChars = 'invalid_max_chars';
    case InvalidIncludes = 'invalid_includes';
    case InvalidTitle = 'invalid_title';
    case EmptyContent = 'empty_content';
    case ContentTooLong = 'content_too_long';
    case MissingHeading = 'missing_heading';
    case DuplicateId = 'duplicate_id';
    case DuplicateContent = 'duplicate_content';
    case UnknownInclude = 'unknown_include';
    case UnknownDocument = 'unknown_document';
    case UnknownProfile = 'unknown_profile';
    case UnknownSelector = 'unknown_selector';
    case ConstitutionOverlap = 'constitution_overlap';
    case LiveBusinessFact = 'live_business_fact';
    case SensitiveData = 'sensitive_data';
    case ForbiddenTerm = 'forbidden_term';
    case BudgetExceeded = 'budget_exceeded';
    case InvalidQuery = 'invalid_query';
    case InvalidLimits = 'invalid_limits';
}
