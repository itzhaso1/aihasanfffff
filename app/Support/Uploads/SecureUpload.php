<?php

namespace App\Support\Uploads;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SecureUpload
{
    /**
     * @var array<int, string>
     */
    public const DOCUMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /**
     * @var array<int, string>
     */
    public const DOCUMENT_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @var array<int, string>
     */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @var array<int, string>
     */
    public const IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * @param  array<int, string>  $allowedExtensions
     * @param  array<int, string>  $allowedMimes
     */
    public function store(
        UploadedFile $file,
        string $directory,
        string $disk = 'public',
        int $maxKilobytes = 4096,
        array $allowedExtensions = self::DOCUMENT_EXTENSIONS,
        array $allowedMimes = self::DOCUMENT_MIMES,
    ): string {
        $this->assertSafe($file, $maxKilobytes, $allowedExtensions, $allowedMimes);

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $safeName = bin2hex(random_bytes(16)).($extension !== '' ? '.'.$extension : '');

        $path = $file->storeAs($directory, $safeName, $disk);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('تعذر تخزين الملف.');
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $allowedExtensions
     * @param  array<int, string>  $allowedMimes
     */
    public function assertSafe(
        UploadedFile $file,
        int $maxKilobytes,
        array $allowedExtensions,
        array $allowedMimes,
    ): void {
        if (! $file->isValid()) {
            throw new RuntimeException('الملف المرفوع غير صالح.');
        }

        $size = $file->getSize();
        if ($size !== null && $size > ($maxKilobytes * 1024)) {
            throw new RuntimeException('حجم الملف يتجاوز الحد المسموح.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        if ($extension === 'svg' || str_contains($mime, 'svg') || str_contains($mime, 'xml')) {
            throw new RuntimeException('نوع الملف غير مسموح.');
        }

        if (in_array($extension, ['php', 'phtml', 'phar', 'exe', 'sh', 'js', 'html', 'htm'], true)) {
            throw new RuntimeException('نوع الملف غير مسموح.');
        }

        $extensionOk = in_array($extension, $allowedExtensions, true);
        $mimeOk = in_array($mime, $allowedMimes, true);

        if (! $extensionOk || ! $mimeOk) {
            throw new RuntimeException('نوع الملف غير مسموح.');
        }
    }

    public function delete(string $path, string $disk = 'public'): void
    {
        if ($path === '') {
            return;
        }

        Storage::disk($disk)->delete($path);
    }
}
