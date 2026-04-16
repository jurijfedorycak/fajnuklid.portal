<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Response;
use Tests\TestCase;

class ResponseTest extends TestCase
{
    /**
     * @dataProvider validContentTypes
     */
    public function testSanitizeContentTypePassesValidMediaTypesThrough(string $input): void
    {
        $this->assertSame($input, Response::sanitizeContentType($input));
    }

    public static function validContentTypes(): array
    {
        return [
            'pdf' => ['application/pdf'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'heic' => ['image/heic'],
            'msword' => ['application/msword'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'json' => ['application/json'],
            'html with charset' => ['text/html; charset=utf-8'],
            'octet-stream' => ['application/octet-stream'],
        ];
    }

    /**
     * @dataProvider invalidContentTypes
     */
    public function testSanitizeContentTypeFallsBackWhenValueLooksMalicious(string $input): void
    {
        $this->assertSame('application/octet-stream', Response::sanitizeContentType($input));
    }

    public static function invalidContentTypes(): array
    {
        return [
            'empty' => [''],
            'no slash' => ['junk'],
            'no type' => ['/pdf'],
            'no subtype' => ['application/'],
            'CRLF injection' => ["text/html\r\nSet-Cookie: x=1"],
            'LF injection' => ["text/html\nLocation: https://evil.com"],
            'trailing garbage' => ['image/jpeg<script>'],
        ];
    }
}
