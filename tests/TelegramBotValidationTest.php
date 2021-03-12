<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class TelegramBotValidationTest extends TestCaseSymconValidation
{
    public function testValidateTelegramBot(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateTelegramBotModule(): void
    {
        $this->validateModule(__DIR__ . '/../TelegramBot');
    }
}