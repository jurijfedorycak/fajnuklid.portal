<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Config\Config;
use App\Services\MailerService;
use Tests\TestCase;

class MailerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetConfig();
        parent::tearDown();
    }

    private function setConfigValues(array $values): void
    {
        $ref = new \ReflectionClass(Config::class);
        $ref->getProperty('config')->setValue(null, $values);
        $ref->getProperty('loaded')->setValue(null, true);
    }

    private function resetConfig(): void
    {
        $ref = new \ReflectionClass(Config::class);
        $ref->getProperty('config')->setValue(null, []);
        $ref->getProperty('loaded')->setValue(null, false);
    }

    public function testSendReturnsFalseForEmptyRecipient(): void
    {
        $this->setConfigValues([]);
        $mailer = new MailerService();

        $this->assertFalse($mailer->send('', 'Subject', '<p>Body</p>'));
        $this->assertFalse($mailer->send('   ', 'Subject', '<p>Body</p>'));
    }
}
