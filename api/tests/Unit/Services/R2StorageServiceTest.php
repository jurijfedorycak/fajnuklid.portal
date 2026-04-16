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
        $this->service = new R2StorageService(
            $this->s3Mock,
            'test-bucket',
            null,
            'https://api.example.com/api',
            'test-secret'
        );
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

    // ── extractKey ──

    public function testExtractKeyReturnsInputWhenAlreadyAKey(): void
    {
        $this->assertSame(
            'employee-photos/photo_abc.jpg',
            $this->service->extractKey('employee-photos/photo_abc.jpg')
        );
    }

    public function testExtractKeyStripsBucketFromPathStylePresignedUrl(): void
    {
        $url = 'https://abc123.r2.cloudflarestorage.com/test-bucket/employee-photos/photo_abc.jpg'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Signature=deadbeef';

        $this->assertSame('employee-photos/photo_abc.jpg', $this->service->extractKey($url));
    }

    public function testExtractKeyStripsQueryFromPublicUrl(): void
    {
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz');

        $this->assertSame(
            'employee-photos/photo_abc.jpg',
            $service->extractKey('https://cdn.fajnuklid.cz/employee-photos/photo_abc.jpg?v=1')
        );
    }

    public function testExtractKeyReturnsEmptyForUrlWithNoPath(): void
    {
        $this->assertSame('', $this->service->extractKey('https://example.com'));
    }

    public function testExtractKeyPullsKeyFromProxyUrlQueryString(): void
    {
        // FE may echo the proxy URL back into a save request — the round-trip must
        // land on the same bare key that was stored, not on "api/storage/file".
        $proxy = $this->service->getProxyUrl('employee-contracts/contract_abc.pdf');

        $this->assertSame(
            'employee-contracts/contract_abc.pdf',
            $this->service->extractKey($proxy)
        );
    }

    // ── resolveUrl ──

    public function testResolveUrlReturnsNullForNullOrEmpty(): void
    {
        $this->assertNull($this->service->resolveUrl(null));
        $this->assertNull($this->service->resolveUrl(''));
    }

    public function testResolveUrlReturnsLegacyUploadsPathUnchanged(): void
    {
        $this->assertSame('/uploads/foo.pdf', $this->service->resolveUrl('/uploads/foo.pdf'));
    }

    public function testResolveUrlRegeneratesFreshUrlFromKeyWhenPublicUrlConfigured(): void
    {
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz');

        $this->assertSame(
            'https://cdn.fajnuklid.cz/employee-photos/photo_abc.jpg',
            $service->resolveUrl('employee-photos/photo_abc.jpg')
        );
    }

    public function testResolveUrlRegeneratesFreshUrlFromExpiredPresignedUrl(): void
    {
        // A "stale" presigned URL persisted to DB. resolveUrl must strip the query,
        // extract the key, and regenerate a URL that will work right now.
        $service = new R2StorageService($this->s3Mock, 'test-bucket', 'https://cdn.fajnuklid.cz');

        $stale = 'https://abc123.r2.cloudflarestorage.com/test-bucket/employee-photos/photo_abc.jpg'
            . '?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Expires=3600&X-Amz-Signature=deadbeef';

        $this->assertSame(
            'https://cdn.fajnuklid.cz/employee-photos/photo_abc.jpg',
            $service->resolveUrl($stale)
        );
    }

    // ── Proxy URL (stable HMAC-signed URL the FE can use forever) ──

    public function testSignKeyIsDeterministicForSameKeyAndSecret(): void
    {
        $this->assertSame(
            $this->service->signKey('employee-photos/photo.jpg'),
            $this->service->signKey('employee-photos/photo.jpg')
        );
    }

    public function testSignKeyDiffersPerKey(): void
    {
        $this->assertNotSame(
            $this->service->signKey('employee-photos/a.jpg'),
            $this->service->signKey('employee-photos/b.jpg')
        );
    }

    public function testSignKeyDiffersPerSecret(): void
    {
        $other = new R2StorageService($this->s3Mock, 'test-bucket', null, 'https://api.example.com/api', 'other-secret');
        $this->assertNotSame(
            $this->service->signKey('employee-photos/photo.jpg'),
            $other->signKey('employee-photos/photo.jpg')
        );
    }

    public function testVerifyKeySignatureAcceptsSelfSigned(): void
    {
        $key = 'employee-photos/photo.jpg';
        $sig = $this->service->signKey($key);

        $this->assertTrue($this->service->verifyKeySignature($key, $sig));
    }

    public function testVerifyKeySignatureRejectsForgery(): void
    {
        $this->assertFalse(
            $this->service->verifyKeySignature('employee-photos/photo.jpg', 'deadbeef')
        );
    }

    public function testVerifyKeySignatureRejectsEmptySig(): void
    {
        $this->assertFalse(
            $this->service->verifyKeySignature('employee-photos/photo.jpg', '')
        );
    }

    public function testVerifyKeySignatureRejectsCrossKey(): void
    {
        $sigForA = $this->service->signKey('employee-photos/a.jpg');
        $this->assertFalse(
            $this->service->verifyKeySignature('employee-photos/b.jpg', $sigForA)
        );
    }

    public function testGetProxyUrlShape(): void
    {
        $url = $this->service->getProxyUrl('employee-photos/photo_abc.jpg');

        $this->assertStringStartsWith(
            'https://api.example.com/api/storage/file?key=employee-photos%2Fphoto_abc.jpg&sig=',
            $url
        );
        // Signature is 64 hex chars (sha256)
        $this->assertMatchesRegularExpression('/&sig=[a-f0-9]{64}$/', $url);
    }

    public function testGetProxyUrlIsStableAcrossCalls(): void
    {
        $this->assertSame(
            $this->service->getProxyUrl('employee-photos/photo_abc.jpg'),
            $this->service->getProxyUrl('employee-photos/photo_abc.jpg')
        );
    }

    public function testResolveProxyUrlReturnsNullForEmpty(): void
    {
        $this->assertNull($this->service->resolveProxyUrl(null));
        $this->assertNull($this->service->resolveProxyUrl(''));
    }

    public function testResolveProxyUrlPassesThroughLegacyUploadsPath(): void
    {
        $this->assertSame(
            'https://api.example.com/api/uploads/foo.pdf',
            $this->service->resolveProxyUrl('/uploads/foo.pdf')
        );
    }

    public function testResolveProxyUrlFromBareKey(): void
    {
        $this->assertSame(
            $this->service->getProxyUrl('employee-photos/photo_abc.jpg'),
            $this->service->resolveProxyUrl('employee-photos/photo_abc.jpg')
        );
    }

    public function testResolveProxyUrlFromLegacyPresignedUrl(): void
    {
        $stale = 'https://abc123.r2.cloudflarestorage.com/test-bucket/employee-photos/photo_abc.jpg'
            . '?X-Amz-Signature=deadbeef';

        $this->assertSame(
            $this->service->getProxyUrl('employee-photos/photo_abc.jpg'),
            $this->service->resolveProxyUrl($stale)
        );
    }

    public function testGetObjectWithMetaReturnsBodyAndContentType(): void
    {
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('__toString')->willReturn('raw-bytes');

        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('getObject', $this->callback(function ($args) {
                return $args[0]['Bucket'] === 'test-bucket'
                    && $args[0]['Key'] === 'employee-photos/photo.jpg';
            }))
            ->willReturn(['Body' => $streamMock, 'ContentType' => 'image/jpeg']);

        $result = $this->service->getObjectWithMeta('employee-photos/photo.jpg');

        $this->assertSame('raw-bytes', $result['body']);
        $this->assertSame('image/jpeg', $result['contentType']);
    }

    public function testGetObjectWithMetaFallsBackToOctetStreamWhenContentTypeMissing(): void
    {
        $streamMock = $this->createMock(StreamInterface::class);
        $streamMock->method('__toString')->willReturn('raw-bytes');

        $this->s3Mock->method('__call')
            ->willReturn(['Body' => $streamMock]);

        $result = $this->service->getObjectWithMeta('anything/foo.bin');

        $this->assertSame('application/octet-stream', $result['contentType']);
    }

    public function testSignKeyThrowsWhenSecretMissing(): void
    {
        $noSecret = new R2StorageService($this->s3Mock, 'test-bucket', null, 'https://api.example.com/api', '');

        $this->expectException(\RuntimeException::class);
        $noSecret->signKey('employee-photos/photo.jpg');
    }

    public function testGetProxyUrlDerivesBaseUrlFromRequestWhenAppUrlUnset(): void
    {
        $originalServer = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'portal.fajnuklid.cz';
        $_SERVER['HTTPS'] = 'on';

        try {
            $derived = new R2StorageService($this->s3Mock, 'test-bucket', null, '', 'test-secret');
            $url = $derived->getProxyUrl('employee-photos/photo.jpg');

            $this->assertStringStartsWith(
                'https://portal.fajnuklid.cz/api/storage/file?key=employee-photos%2Fphoto.jpg&sig=',
                $url
            );
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testGetProxyUrlThrowsWhenNoAppUrlAndNoRequestHost(): void
    {
        $originalServer = $_SERVER;
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);

        try {
            $noBase = new R2StorageService($this->s3Mock, 'test-bucket', null, '', 'test-secret');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Nelze určit veřejnou URL API');

            $noBase->getProxyUrl('employee-photos/photo.jpg');
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testGetProxyUrlHonorsForwardedProtoForTlsTerminatingProxies(): void
    {
        $originalServer = $_SERVER;
        $_SERVER['HTTP_HOST'] = 'portal.fajnuklid.cz';
        unset($_SERVER['HTTPS'], $_SERVER['REQUEST_SCHEME'], $_SERVER['SERVER_PORT']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        try {
            $derived = new R2StorageService($this->s3Mock, 'test-bucket', null, '', 'test-secret');
            $url = $derived->getProxyUrl('employee-photos/photo.jpg');

            $this->assertStringStartsWith('https://portal.fajnuklid.cz/api/storage/file?', $url);
        } finally {
            $_SERVER = $originalServer;
        }
    }

    public function testGetObjectWithMetaRaisesNotFoundForMissingKey(): void
    {
        $cmd = $this->createMock(CommandInterface::class);
        $aws = new \Aws\S3\Exception\S3Exception(
            'The specified key does not exist.',
            $cmd,
            ['code' => 'NoSuchKey']
        );

        $this->s3Mock->expects($this->once())
            ->method('__call')
            ->with('getObject', $this->anything())
            ->willThrowException($aws);

        $this->expectException(\App\Exceptions\NotFoundException::class);

        $this->service->getObjectWithMeta('employee-photos/missing.jpg');
    }
}
