<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Repositories\CompanyDocumentRepository;
use App\Services\CompanyDocumentService;
use App\Services\R2StorageService;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

class CompanyDocumentServiceTest extends TestCase
{
    private MockObject&CompanyDocumentRepository $repo;
    private MockObject&R2StorageService $storage;
    private CompanyDocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->createMock(CompanyDocumentRepository::class);
        $this->storage = $this->createMock(R2StorageService::class);
        $this->service = new CompanyDocumentService($this->repo, $this->storage);
    }

    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 5,
            'company_id' => 2,
            'document_type' => 'Dodatek',
            'title' => 'Dodatek č. 1',
            'file_path' => 'company-documents/dodatek_abc123def456.pdf',
            'original_filename' => 'dodatek.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'created_at' => '2026-05-17 10:00:00',
            'updated_at' => '2026-05-17 10:00:00',
        ], $overrides);
    }

    public function testListForCompanyFormatsRowsWithoutUrl(): void
    {
        $this->repo->method('findByCompanyId')->willReturn([$this->sampleRow()]);
        $this->storage->expects($this->never())->method('resolveProxyUrl');

        $result = $this->service->listForCompany(2);

        $this->assertCount(1, $result);
        $this->assertSame([
            'id' => 5,
            'companyId' => 2,
            'documentType' => 'Dodatek',
            'title' => 'Dodatek č. 1',
            'filename' => 'dodatek.pdf',
            'mimeType' => 'application/pdf',
            'sizeBytes' => 1024,
            'uploadedAt' => '2026-05-17 10:00:00',
        ], $result[0]);
    }

    public function testListForCompanyWithUrlIncludesProxyUrl(): void
    {
        $this->repo->method('findByCompanyId')->willReturn([$this->sampleRow()]);
        $this->storage->method('resolveProxyUrl')->willReturn('https://api.test/storage/file?key=x&sig=y');

        $result = $this->service->listForCompany(2, true);

        $this->assertSame('https://api.test/storage/file?key=x&sig=y', $result[0]['url']);
    }

    public function testNullDocumentTypeIsNormalisedToNull(): void
    {
        $this->repo->method('findByCompanyId')->willReturn([$this->sampleRow(['document_type' => ''])]);

        $result = $this->service->listForCompany(2);

        $this->assertNull($result[0]['documentType']);
    }

    public function testListForCompaniesGroupedGroupsByCompany(): void
    {
        $this->repo->method('findByCompanyIds')->willReturn([
            $this->sampleRow(['id' => 1, 'company_id' => 2]),
            $this->sampleRow(['id' => 2, 'company_id' => 3]),
            $this->sampleRow(['id' => 3, 'company_id' => 2]),
        ]);

        $grouped = $this->service->listForCompaniesGrouped([2, 3]);

        $this->assertCount(2, $grouped[2]);
        $this->assertCount(1, $grouped[3]);
        $this->assertSame(1, $grouped[2][0]['id']);
        $this->assertSame(2, $grouped[3][0]['id']);
    }

    public function testListForCompaniesGroupedKeepsEmptyCompanies(): void
    {
        $this->repo->method('findByCompanyIds')->willReturn([]);

        $grouped = $this->service->listForCompaniesGrouped([2, 3]);

        $this->assertSame([2 => [], 3 => []], $grouped);
    }

    public function testUploadThrowsWhenTitleMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->upload(2, 7, [], '   ', null);
    }

    public function testUploadThrowsWhenTitleTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->upload(2, 7, [], str_repeat('a', 256), null);
    }

    public function testUploadThrowsWhenTypeTooLong(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->upload(2, 7, [], 'Platný název', str_repeat('b', 101));
    }

    public function testUploadHappyPath(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");

        try {
            $file = [
                'error' => UPLOAD_ERR_OK,
                'size' => 28,
                'tmp_name' => $tmp,
                'name' => 'dodatek.pdf',
            ];

            $this->storage->expects($this->once())
                ->method('upload')
                ->with('company-documents', $tmp, 'dodatek.pdf', 'application/pdf')
                ->willReturn('company-documents/dodatek_abc123def456.pdf');

            $this->repo->expects($this->once())
                ->method('create')
                ->willReturn(5);

            $this->repo->method('findById')->willReturn($this->sampleRow());
            $this->storage->method('resolveProxyUrl')->willReturn('https://api.test/storage/file?key=x&sig=y');

            $result = $this->service->upload(2, 7, $file, 'Dodatek č. 1', 'Dodatek');

            $this->assertSame(5, $result['id']);
            $this->assertSame('Dodatek č. 1', $result['title']);
            $this->assertSame('https://api.test/storage/file?key=x&sig=y', $result['url']);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUploadRejectsUnsupportedMime(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($tmp, "GIF89a plain stuff that is not an allowed type");

        try {
            $file = [
                'error' => UPLOAD_ERR_OK,
                'size' => 46,
                'tmp_name' => $tmp,
                'name' => 'not-allowed.gif',
            ];

            $this->storage->expects($this->never())->method('upload');
            $this->expectException(ValidationException::class);

            $this->service->upload(2, 7, $file, 'Obrázek', null);
        } finally {
            @unlink($tmp);
        }
    }

    public function testUpdateMetaThrowsWhenMissing(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->updateMeta(999, 'Název', null);
    }

    public function testUpdateMetaReturnsFormattedRow(): void
    {
        $this->repo->method('findById')->willReturn($this->sampleRow(['title' => 'Aktualizováno']));
        $this->repo->expects($this->once())
            ->method('updateMeta')
            ->with(5, ['title' => 'Aktualizováno', 'document_type' => 'Dodatek']);
        $this->storage->method('resolveProxyUrl')->willReturn('https://api.test/x');

        $result = $this->service->updateMeta(5, 'Aktualizováno', 'Dodatek');

        $this->assertSame('Aktualizováno', $result['title']);
    }

    public function testDeleteByIdThrowsWhenMissing(): void
    {
        $this->repo->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);
        $this->service->deleteById(999);
    }

    public function testDeleteByIdRemovesRowAndFile(): void
    {
        $this->repo->method('findById')->willReturn($this->sampleRow());
        $this->repo->expects($this->once())->method('delete')->with(5);
        $this->storage->method('extractKey')->willReturn('company-documents/dodatek_abc123def456.pdf');
        $this->storage->expects($this->once())->method('delete')
            ->with('company-documents/dodatek_abc123def456.pdf');

        $this->service->deleteById(5);
    }
}
