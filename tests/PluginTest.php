<?php
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    public function test_plugin_file_exists_and_constants()
    {
        $pluginFile = __DIR__ . '/../groui-smart-assistant/groui-smart-assistant.php';
        $this->assertFileExists($pluginFile);
        require_once $pluginFile;
        $this->assertTrue(defined('GROUI_SMART_ASSISTANT_VERSION'));
    }
}
