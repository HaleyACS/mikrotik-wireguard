<?php

require_once __DIR__ . '/run_tests.php';
require_once __DIR__ . '/../src/auth.php';

abstract class AuthTestCase extends TestCase {
    protected string $adminHashPath;
    protected ?string $originalHash = null;
    protected int $reportingLevel;

    public function setUp(): void {
        $this->reportingLevel = error_reporting(E_ALL & ~E_WARNING);
        $this->adminHashPath = __DIR__ . '/../.admin-hash';
        if (file_exists($this->adminHashPath)) {
            $this->originalHash = file_get_contents($this->adminHashPath);
        }
    }

    public function tearDown(): void {
        error_reporting($this->reportingLevel);
        if ($this->originalHash !== null) {
            file_put_contents($this->adminHashPath, $this->originalHash);
        } else {
            if (file_exists($this->adminHashPath)) {
                unlink($this->adminHashPath);
            }
        }
        clearstatcache(true, $this->adminHashPath);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    protected function createHashFile(?string $password = null): void {
        $hash = password_hash($password ?? 'test_password_123', PASSWORD_BCRYPT);
        file_put_contents($this->adminHashPath, $hash);
        clearstatcache(true, $this->adminHashPath);
    }

    protected function removeHashFile(): void {
        if (file_exists($this->adminHashPath)) {
            unlink($this->adminHashPath);
        }
        clearstatcache(true, $this->adminHashPath);
    }
}
