<?php

require_once __DIR__ . '/run_tests.php';

class QrCodeAssetTest extends TestCase {
    public function testQrCodeLibraryIsAvailableForTheDashboard(): void {
        $library = file_get_contents(__DIR__ . '/../assets/js/qrcode-generator.min.js');

        $this->assertTrue($library !== false);
        $this->assertTrue(strpos($library, 'qrcode') !== false);
        $this->assertTrue(strpos($library, 'createSvgTag') !== false);
    }

    public function testBothConfigurationModalsExposeQrCodeTabs(): void {
        $page = file_get_contents(__DIR__ . '/../index.php');
        $script = file_get_contents(__DIR__ . '/../assets/js/app.js');

        $this->assertEquals(2, substr_count($page, 'data-tab="qrcode"'));
        $this->assertTrue(strpos($page, 'tab-qrcode') !== false);
        $this->assertTrue(strpos($page, 'tab-export-qrcode') !== false);
        $this->assertTrue(strpos($script, 'switchAddTab(el.dataset.tab)') !== false);
        $this->assertTrue(strpos($script, 'switchExportTab(el.dataset.tab)') !== false);
        $this->assertTrue(strpos($script, 'renderQrCode') !== false);
        $this->assertTrue(strpos($script, 'clearQrCode') !== false);
    }
}
