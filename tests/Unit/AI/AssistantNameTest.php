<?php

namespace Tests\Unit\AI;

use App\Models\AiSetting;
use App\Services\AI\AssistantName;
use Tests\TestCase;

class AssistantNameTest extends TestCase
{
    public function test_sanitize_accepts_arabic_and_latin_names(): void
    {
        $this->assertSame('ساره', AssistantName::sanitize('  ساره  '));
        $this->assertSame('حسن', AssistantName::sanitize('حسن'));
        $this->assertSame('هشام', AssistantName::sanitize('هشام'));
        $this->assertSame('AI Assistant', AssistantName::sanitize('AI Assistant'));
    }

    public function test_sanitize_rejects_markup_and_control_characters(): void
    {
        $this->assertTrue(AssistantName::containsMarkup('<b>ساره</b>'));
        $this->assertNull(AssistantName::sanitize('<script>alert(1)</script>'));
        $this->assertNull(AssistantName::sanitize('محمد@store'));
        $this->assertNull(AssistantName::sanitize(''));
        $this->assertNull(AssistantName::sanitize(null));
    }

    public function test_generic_names_do_not_override_persona_identity(): void
    {
        $setting = new AiSetting(['name' => 'AI Assistant']);

        $this->assertNull(AssistantName::configured($setting));
        $this->assertSame('ليلى', AssistantName::display($setting, 'ليلى'));
        $this->assertSame('AI Assistant', AssistantName::display($setting));
    }

    public function test_configured_name_wins_over_persona_identity(): void
    {
        $setting = new AiSetting(['name' => 'ساره']);

        $this->assertSame('ساره', AssistantName::configured($setting));
        $this->assertSame('ساره', AssistantName::display($setting, 'حسن'));
    }
}
