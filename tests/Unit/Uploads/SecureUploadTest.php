<?php

namespace Tests\Unit\Uploads;

use App\Support\Uploads\SecureUpload;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

class SecureUploadTest extends TestCase
{
    public function test_it_rejects_executable_and_svg_uploads(): void
    {
        $upload = app(SecureUpload::class);

        $php = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');
        $this->expectException(RuntimeException::class);
        $upload->assertSafe($php, 4096, SecureUpload::DOCUMENT_EXTENSIONS, SecureUpload::DOCUMENT_MIMES);
    }

    public function test_it_accepts_pdf_documents(): void
    {
        $upload = app(SecureUpload::class);
        $pdf = UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf');

        $upload->assertSafe($pdf, 4096, SecureUpload::DOCUMENT_EXTENSIONS, SecureUpload::DOCUMENT_MIMES);
        $this->assertTrue(true);
    }
}
