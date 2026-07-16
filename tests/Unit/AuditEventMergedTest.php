<?php

namespace Tests\Unit;

use App\Enums\AuditEvent;
use PHPUnit\Framework\TestCase;

class AuditEventMergedTest extends TestCase
{
    public function test_merged_case_exists_with_label(): void
    {
        $this->assertSame('merged', AuditEvent::Merged->value);
        $this->assertSame('Fusionó', AuditEvent::Merged->label());
    }
}
