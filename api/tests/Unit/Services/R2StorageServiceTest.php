<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\R2StorageService;
use Aws\CommandInterface;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class R2StorageServiceTest extends TestCase
{
    private R2StorageService $service;
    private MockObject&S3Client $s3Mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->s3Mock = $this->createMock(S3Client::class);
        $this->service = new R2StorageService($this->s3Mock, 'test-bucket');
    }

    // ── generateKey ──

    public function testGenerateKeyProducesCorrectFormat(): void
    {
        $key = R2StorageService::generateKey('employee-photos', 'portrait.jpg');

        $this->assertStringStartsWith('employee-photos/', $key);
        $this->assertStringEndsWith('.jpg', $key);
        // Format: folder/safeName_16hexChars.ext
        $this->assertMatchesRegularExpression(
            '#^employee-photos/portrait_[a-f0-9]{16}\.jpg$#',
            $key
        );
    }

    public function testGenerateKeySanitizesUnsafeCharacters(): void
    {
        $key = R2StorageService::generateKey('photos', 'foto s diakritikou čřž.png');

        $this->assertStringStartsWith('photos/', $key);
        $this->assertStringEndsWith('.png', $key);
        $this->assertDoesNotMatchRegularExpression('/[ čřž]/', $key);
    }

    public function testGenerateKeyHandlesNoExtension(): void
    {
        $key = R2StorageService::generateKey('docs', 'readme');

        $this->assertStringStartsWith('docs/readme_', $key);
        // No dot in the unique ID portion
        $parts = explode('/', $key);
        $filename = $parts[1];
        $this->assertDoesNotMatchRegularExpression('/\./', substr($filename, strpos($filename, '_') + 1));
    }

    public function testGenerateKeyHandlesEmptyFilename(): void
    {
        $key = R2StorageService::generateKey('docs', '....pdf');

        $this->assertStringStartsWith('docs/file_', $key);
        $this->assertStringEndsWith('.pdf', $key);
    }

    public function testGenerateKeySanitizesExtension(): void
    {
        $key = R2StorageService::generateKey('docs', 'exploit.p h p');

        $this->assertStringStartsWith('docs/exploit_', $key);
        // Extension should be sanitized (spaces removed)
        $this->assertStringEndsWith('.php', $key);
    }

    public function testGenerateKeyStripsNullBytes(): void
    {
        $key = R2StorageService::generateKey('photos', "evil\0name.jpg");

        $this->assertStringStartsWith('photos/evilname_', $key);
        $this->assertStringNotContainsString("\0", $key);
    }

    public function testGenerateKeyProducesUniqueKeys(): void
    {
        $key1 = R2StorageService::generateKey('photos', 'same.jpg');
        $key2 = R2StorageService::generateKey('photos', 'same.jpg');

        $this->assertNotSame($key1, $key2);
    }

    // ── upload ──

    public function testUploadCallsPutObjectWithCorrectParams(): void
    {
        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('putObject', $this->callback(function ($args) {
                $params = $args[0];
                return $params['Bucket'] === 'test-bucket'
                    && str_starts_with($params['Key'], 'employee-photos/photo_')
                    && str_ends_with($params['Key'], '.jpg')
                    && $params['SourceFile'] === '/tmp/upload123'
                    && $params['ContentType'] === 'image/jpeg';
            }));

        $key = $this->service->upload('employee-photos', '/tmp/upload123', 'photo.jpg', 'image/jpeg');

        $this->assertStringStartsWith('employee-photos/photo_', $key);
        $this->assertStringEndsWith('.jpg', $key);
    }

    public function testUploadWrapsAwsException(): void
    {
        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('putObject', $this->anything())
            ->willThrowException(new \Aws\Exception\AwsException(
                'Access denied',
                $this->createMock(CommandInterface::class)
            ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nepodařilo se nahrát soubor do úložiště.');

        $this->service->upload('photos', '/tmp/file', 'test.jpg', 'image/jpeg');
    }

    // ── delete ──

    public function testDeleteCallsDeleteObjectWithCorrectParams(): void
    {
        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('deleteObject', $this->callback(function ($args) {
                $params = $args[0];
                return $params['Bucket'] === 'test-bucket'
                    && $params['Key'] === 'employee-photos/photo_abc123.jpg';
            }));

        $this->service->delete('employee-photos/photo_abc123.jpg');
    }

    // ── getPublicUrl ──

    public function testGetPublicUrlReturnsNullWhenNotConfigured(): void
    {
        $this->assertNull($this->service->getPublicUrl('employee-photos/photo.jpg'));
    }

    public function testGetPublicUrlReturnsCorrectUrlWhenConfigured(): void
    {
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz');

        $url = $service->getPublicUrl('employee-photos/photo.jpg');

        $this->assertSame('https://cdn.fajnuklid.cz/employee-photos/photo.jpg', $url);
    }

    public function testGetPublicUrlTrimsTrailingSlash(): void
    {
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz/');

        $url = $service->getPublicUrl('employee-photos/photo.jpg');

        $this->assertSame('https://cdn.fajnuklid.cz/employee-photos/photo.jpg', $url);
    }

    // ── getUrl ──

    public function testGetUrlReturnsPublicUrlWhenConfigured(): void
    {
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz');

        $url = $service->getUrl('employee-photos/photo.jpg');

        $this->assertSame('https://cdn.fajnuklid.cz/employee-photos/photo.jpg', $url);
    }

    public function testGetUrlFallsBackToPresignedWhenNoPublicUrl(): void
    {
        $mockCmd = $this->createMock(CommandInterface::class);
        $mockRequest = new Psr7Request('GET', new Uri('https://r2.example.com/test-bucket/photo.jpg?signed=1'));

        $this->s3Mock->expects($this->once())
            ->method('getCommand')
            ->with('GetObject', [
                'Bucket' => 'test-bucket',
                'Key' => 'employee-photos/photo.jpg',
            ])
            ->willReturn($mockCmd);

        $this->s3Mock->expects($this->once())
            ->method('createPresignedRequest')
            ->with($mockCmd, '+3600 seconds')
            ->willReturn($mockRequest);

        $url = $this->service->getUrl('employee-photos/photo.jpg');

        $this->assertSame('https://r2.example.com/test-bucket/photo.jpg?signed=1', $url);
    }

    // ── getContent ──

    public function testGetContentReturnsBodyAsString(): void
    {
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('__toString')->willReturn('file-content-bytes');

        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('getObject', $this->callback(function ($args) {
                $params = $args[0];
                return $params['Bucket'] === 'test-bucket'
                    && $params['Key'] === 'contracts/doc.pdf';
            }))
            ->willReturn(['Body' => $streamMock]);

        $content = $this->service->getContent('contracts/doc.pdf');

        $this->assertSame('file-content-bytes', $content);
    }
}
