<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\CompanyDocumentRepository;
use Tests\DatabaseTestCase;

class CompanyDocumentRepositoryTest extends DatabaseTestCase
{
    private CompanyDocumentRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = $this->createRepositoryWithMockedPdo(CompanyDocumentRepository::class);
    }

    public function testFindByIdReturnsRow(): void
    {
        $row = [
            'id' => 5,
            'company_id' => 2,
            'document_type' => 'Dodatek',
            'title' => 'Dodatek č. 1',
            'file_path' => 'company-documents/dodatek_abc123def456.pdf',
            'original_filename' => 'dodatek.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'uploaded_by_user_id' => 7,
            'created_at' => '2026-05-17 10:00:00',
            'updated_at' => '2026-05-17 10:00:00',
        ];
        $this->setupFetchMock($row);

        $this->assertEquals($row, $this->repository->findById(5));
    }

    public function testFindByIdReturnsNullWhenMissing(): void
    {
        $this->setupFetchMock(false);

        $this->assertNull($this->repository->findById(999));
    }

    public function testFindByCompanyIdReturnsRows(): void
    {
        $rows = [
            ['id' => 1, 'company_id' => 2, 'title' => 'Smlouva'],
            ['id' => 2, 'company_id' => 2, 'title' => 'Dodatek'],
        ];
        $this->setupFetchAllMock($rows);

        $this->assertSame($rows, $this->repository->findByCompanyId(2));
    }

    public function testFindByCompanyIdsReturnsEmptyForEmptyInput(): void
    {
        // No query should run; assert without touching the PDO mock expectations.
        $this->assertSame([], $this->repository->findByCompanyIds([]));
    }

    public function testFindByCompanyIdsReturnsRows(): void
    {
        $rows = [
            ['id' => 1, 'company_id' => 2],
            ['id' => 2, 'company_id' => 3],
        ];
        $this->setupFetchAllMock($rows);

        $this->assertSame($rows, $this->repository->findByCompanyIds([2, 3, 2]));
    }

    public function testCountByCompanyId(): void
    {
        $this->setupFetchColumnMock('4');

        $this->assertSame(4, $this->repository->countByCompanyId(2));
    }

    public function testCreateReturnsInsertId(): void
    {
        $this->setupInsertMock(42);

        $id = $this->repository->create([
            'company_id' => 2,
            'document_type' => 'Harmonogram',
            'title' => 'Harmonogram úklidu',
            'file_path' => 'company-documents/harmonogram_abc123def456.pdf',
            'original_filename' => 'harmonogram.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'uploaded_by_user_id' => 7,
        ]);

        $this->assertSame(42, $id);
    }

    public function testUpdateMetaReturnsTrueWhenRowAffected(): void
    {
        $this->setupRowCountMock(1);

        $this->assertTrue($this->repository->updateMeta(5, ['title' => 'Nový název', 'document_type' => 'Dodatek']));
    }

    public function testUpdateMetaReturnsFalseWhenNoFields(): void
    {
        // Empty payload short-circuits before any DB call.
        $this->assertFalse($this->repository->updateMeta(5, []));
    }

    public function testDeleteReturnsTrueWhenRowAffected(): void
    {
        $this->setupRowCountMock(1);

        $this->assertTrue($this->repository->delete(5));
    }
}
