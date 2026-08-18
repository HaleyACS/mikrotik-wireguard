<?php

require_once __DIR__ . '/run_tests.php';
require_once __DIR__ . '/MockMikrotikRestClient.php';

class WireGuardManagerTest extends TestCase {
    
    public function testMaxPeers() {
        $this->assertEquals(254, WireGuardManager::maxPeers('3.0.0.0/24', '3.0.0.1'));
        $this->assertEquals(2046, WireGuardManager::maxPeers('3.0.0.0/21', '3.0.0.1'));
        $this->assertEquals(255, WireGuardManager::maxPeers('3.0.0.0/24', '3.0.0.0'));
        $this->assertEquals(255, WireGuardManager::maxPeers('3.0.0.0/24'));
        $this->assertEquals(65534, WireGuardManager::maxPeers('10.0.0.0/16', '10.0.0.1'));
    }

    public function testKeyGeneration() {
        // Run key generation
        $keys = WireGuardManager::generateKeyPair();
        
        $this->assertTrue(isset($keys['private_key']), 'Private key should be set');
        $this->assertTrue(isset($keys['public_key']), 'Public key should be set');
        
        $privBytes = base64_decode($keys['private_key']);
        $pubBytes = base64_decode($keys['public_key']);
        
        $this->assertEquals(32, strlen($privBytes), 'Private key should be 32 bytes');
        $this->assertEquals(32, strlen($pubBytes), 'Public key should be 32 bytes');
        
        // Derive public key from private key to verify math correctness
        $derivedPubBytes = sodium_crypto_scalarmult_base($privBytes);
        $derivedPubB64 = base64_encode($derivedPubBytes);
        
        $this->assertEquals($keys['public_key'], $derivedPubB64, 'Public key derivation should match');
    }

    public function testIpAllocation() {
        // Initialize manager with a mock client
        $manager = new WireGuardManager(new MockMikrotikRestClient(), [
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        // Scenario 1: Empty peer list
        $peersEmpty = [];
        $ip = $manager->calculateNextFreeIp($peersEmpty);
        $this->assertEquals('3.0.0.2', $ip);

        // Scenario 2: 3.0.0.2 is taken
        $peersOne = [
            ['allowed-address' => '3.0.0.2/32']
        ];
        $ip = $manager->calculateNextFreeIp($peersOne);
        $this->assertEquals('3.0.0.3', $ip);

        // Scenario 3: 3.0.0.2 and 3.0.0.3 are taken
        $peersTwo = [
            ['allowed-address' => '3.0.0.2/32'],
            ['allowed-address' => '3.0.0.3/32']
        ];
        $ip = $manager->calculateNextFreeIp($peersTwo);
        $this->assertEquals('3.0.0.4', $ip);

        // Scenario 4: Gap in allocation (3.0.0.2 and 3.0.0.4 taken)
        $peersGap = [
            ['allowed-address' => '3.0.0.2/32'],
            ['allowed-address' => '3.0.0.4/32']
        ];
        $ip = $manager->calculateNextFreeIp($peersGap);
        $this->assertEquals('3.0.0.3', $ip);
        
        // Scenario 5: Multiple allowed IPs inside one allowed-address string or random spaces
        $peersComplex = [
            ['allowed-address' => '3.0.0.2/32,192.168.1.0/24'],
            ['allowed-address' => '3.0.0.3/32']
        ];
        $ip = $manager->calculateNextFreeIp($peersComplex);
        $this->assertEquals('3.0.0.4', $ip);
    }

    public function testConfigFormatting() {
        $config = WireGuardManager::generateConfig(
            '3.0.0.5',
            'client_private_key_base64_goes_here',
            'server_public_key_base64_goes_here',
            'vpn.example.com:13231',
            '3.0.0.0/24,192.168.111.0/24'
        );

        $this->assertTrue(str_contains($config, '[Interface]'), 'Config should contain [Interface] section');
        $this->assertTrue(str_contains($config, 'PrivateKey = client_private_key_base64_goes_here'), 'Config should contain client private key');
        $this->assertTrue(str_contains($config, 'Address = 3.0.0.5/32'), 'Config should contain address');
        $this->assertTrue(str_contains($config, '[Peer]'), 'Config should contain [Peer] section');
        $this->assertTrue(str_contains($config, 'PublicKey = server_public_key_base64_goes_here'), 'Config should contain server public key');
        $this->assertTrue(str_contains($config, 'Endpoint = vpn.example.com:13231'), 'Config should contain endpoint');
        $this->assertTrue(str_contains($config, 'AllowedIPs = 3.0.0.0/24,192.168.111.0/24'), 'Config should contain allowed IPs');
        $this->assertTrue(str_contains($config, 'PersistentKeepalive = 25'), 'Config should contain persistent keepalive');
    }

    public function testGetPeers() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            [
                '.id' => '*1c',
                'interface' => 'WireGuard-ResNovae',
                'name' => 'Enrico-Casa',
                'public-key' => 'Xkx0S5i7MvUvN7zdTSF+icEZebIyD74uR+pc8JMzYjA=',
                'allowed-address' => '3.0.0.2/32',
                'rx' => 5976883,
                'tx' => 491827,
                'last-handshake' => '9s'
            ]
        ]);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        $peers = $manager->getPeers();

        $this->assertEquals(1, count($peers));
        $this->assertEquals('Enrico-Casa', $peers[0]['name']);
        $this->assertEquals('3.0.0.2/32', $peers[0]['allowed-address']);
        $this->assertEquals('5.70 MiB', $peers[0]['rx_formatted']);
    }

    public function testAddPeer() {
        $mockClient = new MockMikrotikRestClient();
        // Mock getPeers response (empty) — first call: unfiltered request() for IP allocation
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        // Mock getPeers response (empty) — second call: via getPeers() for peer ID lookup
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        // Mock getServerPublicKey response
        $mockClient->setResponse('GET', '/interface/wireguard', [
            [
                'name' => 'WireGuard-ResNovae',
                'public-key' => 'SERVER_PUBLIC_KEY_BASE64'
            ]
        ]);
        // Mock the PUT response
        $mockClient->setResponse('PUT', '/interface/wireguard/peers', [
            '.id' => '*1d'
        ]);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1',
            'endpoint' => 'vpn.example.com:13231',
            'client_allowed_ips' => '3.0.0.0/24,192.168.111.0/24'
        ]);

        $result = $manager->addPeer('Test-Client-New');

        $this->assertEquals('Test-Client-New', $result['name']);
        $this->assertEquals('3.0.0.2', $result['ip']);
        
        // Find PUT request in client history
        $putRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PUT' && $req['path'] === '/interface/wireguard/peers') {
                $putRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($putRequest, 'A PUT request should have been made to create the peer');
        $this->assertEquals('WireGuard-ResNovae', $putRequest['data']['interface']);
        $this->assertEquals('3.0.0.2/32', $putRequest['data']['allowed-address']);
        $this->assertEquals('Test-Client-New', $putRequest['data']['name']);
    }

    public function testAddPeerWithCollision() {
        $mockClient = new MockMikrotikRestClient();
        // First getAllPeers call: existing peer at 3.0.0.5
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            ['.id' => '*1a', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'existing_key', 'allowed-address' => '3.0.0.5/32']
        ]);
        // getServerPublicKey response
        $mockClient->setResponse('GET', '/interface/wireguard', [
            ['name' => 'WireGuard-ResNovae', 'public-key' => 'SERVER_PUBLIC_KEY']
        ]);
        // addPeer response
        $mockClient->setResponse('PUT', '/interface/wireguard/peers', ['.id' => '*1c']);
        // getPeers for ID lookup: our peer (matched by name) + concurrent peer with SAME IP
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            ['.id' => '*1a', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'existing_key', 'allowed-address' => '3.0.0.5/32'],
            ['.id' => '*1b', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'concurrent_key', 'allowed-address' => '3.0.0.2/32'],
            ['.id' => '*1c', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'our_key', 'allowed-address' => '3.0.0.2/32', 'name' => 'Test-Client-Collision'],
        ]);
        // Second getAllPeers for re-allocation (still sees 3.0.0.2 as taken by concurrent)
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            ['.id' => '*1a', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'existing_key', 'allowed-address' => '3.0.0.5/32'],
            ['.id' => '*1b', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'concurrent_key', 'allowed-address' => '3.0.0.2/32'],
            ['.id' => '*1c', 'interface' => 'WireGuard-ResNovae', 'public-key' => 'our_key', 'allowed-address' => '3.0.0.2/32', 'name' => 'Test-Client-Collision'],
        ]);
        // updatePeer response
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1',
            'endpoint' => 'vpn.example.com:13231',
            'client_allowed_ips' => '3.0.0.0/24,192.168.111.0/24'
        ]);

        $result = $manager->addPeer('Test-Client-Collision');

        $this->assertEquals('Test-Client-Collision', $result['name']);
        $this->assertEquals('3.0.0.3', $result['ip'], 'Should re-allocate to next free IP after collision');

        // Find PATCH request in client history
        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && str_contains($req['path'], '/interface/wireguard/peers/')) {
                $patchRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to fix IP collision');
        $this->assertEquals('3.0.0.3/32', $patchRequest['data']['allowed-address']);
    }

    public function testAddPeerDuplicateNameThrows() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            ['.id' => '*1a', 'interface' => 'WireGuard-ResNovae', 'name' => 'Existing-Client']
        ]);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1',
        ]);

        $threw = false;
        try {
            $manager->addPeer('Existing-Client');
        } catch (Exception $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), 'already exists'));
        }
        $this->assertTrue($threw, 'Expected exception for duplicate name');
    }

    public function testRegenerateKey() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1',
            'endpoint' => 'vpn.example.com:13231',
            'client_allowed_ips' => '3.0.0.0/24,192.168.111.0/24'
        ]);

        $keys = $manager->regenerateKey('*1c');

        $this->assertTrue(isset($keys['private_key']), 'Private key should be set');
        $this->assertTrue(isset($keys['public_key']), 'Public key should be set');
        $this->assertEquals(32, strlen(base64_decode($keys['private_key'])));
        $this->assertEquals(32, strlen(base64_decode($keys['public_key'])));

        // Verify PATCH request sent with new public-key
        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to regenerate key');
        $this->assertEquals($keys['public_key'], $patchRequest['data']['public-key']);
    }

    public function testUpdatePeer() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        $manager->updatePeer('*1c', 'Updated-Name');

        // Verify PATCH request sent with new name
        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to update peer');
        $this->assertEquals('Updated-Name', $patchRequest['data']['name']);
    }

    public function testDeletePeer() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('DELETE', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        $manager->deletePeer('*1c');

        // Verify DELETE request sent
        $deleteRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'DELETE' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $deleteRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($deleteRequest, 'A DELETE request should have been made to delete peer');
    }

    public function testTogglePeerDisable() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        $manager->togglePeer('*1c', true);

        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to toggle peer');
        $this->assertEquals('yes', $patchRequest['data']['disabled']);
    }

    public function testTogglePeerEnable() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);

        $manager = new WireGuardManager($mockClient, [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1'
        ]);

        $manager->togglePeer('*1c', false);

        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }

        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to toggle peer');
        $this->assertEquals('no', $patchRequest['data']['disabled']);
    }

    public function testExtractUniqueIpv4Addresses() {
        // Empty list
        $this->assertEquals([], WireGuardManager::extractUniqueIpv4Addresses([]));

        // Single peer
        $peers = [
            ['allowed-address' => '3.0.0.2/32']
        ];
        $this->assertEquals(['3.0.0.2'], WireGuardManager::extractUniqueIpv4Addresses($peers));

        // Multiple peers with unique IPs
        $peers = [
            ['allowed-address' => '3.0.0.2/32'],
            ['allowed-address' => '3.0.0.5/32'],
        ];
        $this->assertEquals(['3.0.0.2', '3.0.0.5'], WireGuardManager::extractUniqueIpv4Addresses($peers));

        // Duplicate IPs across peers
        $peers = [
            ['allowed-address' => '3.0.0.2/32'],
            ['allowed-address' => '3.0.0.2/32'],
        ];
        $result = WireGuardManager::extractUniqueIpv4Addresses($peers);
        $this->assertCount(1, $result);
        $this->assertEquals(['3.0.0.2'], $result);

        // Multiple CIDRs in single allowed-address
        $peers = [
            ['allowed-address' => '3.0.0.2/32,192.168.1.0/24'],
        ];
        $this->assertEquals(['3.0.0.2', '192.168.1.0'], WireGuardManager::extractUniqueIpv4Addresses($peers));

        // Sorted order
        $peers = [
            ['allowed-address' => '10.0.0.1/32'],
            ['allowed-address' => '3.0.0.5/32'],
            ['allowed-address' => '192.168.1.1/32'],
        ];
        $this->assertEquals(['3.0.0.5', '10.0.0.1', '192.168.1.1'], WireGuardManager::extractUniqueIpv4Addresses($peers));

        // Non-IPv4 addresses filtered out
        $peers = [
            ['allowed-address' => '::1/128'],
            ['allowed-address' => '3.0.0.2/32'],
        ];
        $this->assertEquals(['3.0.0.2'], WireGuardManager::extractUniqueIpv4Addresses($peers));
    }

    private function peerMock(): MockMikrotikRestClient {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', [
            [
                '.id' => '*1c',
                'interface' => 'WireGuard-ResNovae',
                'name' => 'Cliente-X',
                'public-key' => 'OLD_PUBLIC_KEY',
                'allowed-address' => '3.0.0.2/32',
            ]
        ]);
        $mockClient->setResponse('GET', '/interface/wireguard', [
            ['name' => 'WireGuard-ResNovae', 'public-key' => 'SERVER_PUBLIC_KEY']
        ]);
        $mockClient->setResponse('PATCH', '/interface/wireguard/peers/*1c', []);
        return $mockClient;
    }

    private function peerManagerConfig(): array {
        return [
            'interface' => 'WireGuard-ResNovae',
            'subnet' => '3.0.0.0/24',
            'server_ip' => '3.0.0.1',
            'endpoint' => 'vpn.example.com:13231',
            'client_allowed_ips' => '3.0.0.0/24,192.168.111.0/24'
        ];
    }

    public function testFindPeerByName() {
        $manager = new WireGuardManager($this->peerMock(), $this->peerManagerConfig());
        $peer = $manager->findPeerByName('Cliente-X');
        $this->assertNotNull($peer);
        $this->assertEquals('*1c', $peer['.id']);
        $this->assertEquals('3.0.0.2/32', $peer['allowed-address']);
    }

    public function testFindPeerByNameCaseInsensitive() {
        $manager = new WireGuardManager($this->peerMock(), $this->peerManagerConfig());
        $peer = $manager->findPeerByName('cliente-x');
        $this->assertNotNull($peer, 'Lookup should be case-insensitive');
    }

    public function testFindPeerByNameNotFound() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());
        $this->assertNull($manager->findPeerByName('Inesistente'));
    }

    public function testRegeneratePeer() {
        $mockClient = $this->peerMock();
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $result = $manager->regeneratePeer('Cliente-X');

        $this->assertEquals('Cliente-X', $result['name']);
        $this->assertEquals('3.0.0.2', $result['ip'], 'IP should stay unchanged after regeneration');
        $this->assertTrue(isset($result['private_key']), 'Private key should be set');
        $this->assertTrue(isset($result['public_key']), 'Public key should be set');
        $this->assertTrue(str_contains($result['config'], '[Interface]'), 'Config should be generated');
        $this->assertTrue(str_contains($result['script'], 'MikroTik Client Setup Script'), 'Script should be generated');

        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }
        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to regenerate key');
        $this->assertEquals($result['public_key'], $patchRequest['data']['public-key']);
    }

    public function testRegeneratePeerNotFound() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $threw = false;
        try {
            $manager->regeneratePeer('Inesistente');
        } catch (Exception $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), 'not found'));
        }
        $this->assertTrue($threw, 'Expected exception for unknown peer name');
    }

    public function testRegeneratePeerCaseInsensitive() {
        $manager = new WireGuardManager($this->peerMock(), $this->peerManagerConfig());
        $result = $manager->regeneratePeer('cliente-x');
        $this->assertEquals('Cliente-X', $result['name']);
        $this->assertEquals('3.0.0.2', $result['ip']);
    }

    public function testDeletePeerByName() {
        $mockClient = $this->peerMock();
        $mockClient->setResponse('DELETE', '/interface/wireguard/peers/*1c', []);
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $result = $manager->deletePeerByName('Cliente-X');

        $this->assertEquals('Cliente-X', $result['name']);
        $this->assertEquals('3.0.0.2', $result['ip']);

        $deleteRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'DELETE' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $deleteRequest = $req;
                break;
            }
        }
        $this->assertNotEmpty($deleteRequest, 'A DELETE request should have been made to remove the peer');
    }

    public function testDeletePeerByNameNotFound() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $threw = false;
        try {
            $manager->deletePeerByName('Inesistente');
        } catch (Exception $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), 'not found'));
        }
        $this->assertTrue($threw, 'Expected exception for unknown peer name');
    }

    public function testTogglePeerByNameDisable() {
        $mockClient = $this->peerMock();
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $result = $manager->togglePeerByName('Cliente-X', true);

        $this->assertEquals('Cliente-X', $result['name']);
        $this->assertEquals('3.0.0.2', $result['ip']);
        $this->assertTrue($result['disabled']);

        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }
        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to disable the peer');
        $this->assertEquals('yes', $patchRequest['data']['disabled']);
    }

    public function testTogglePeerByNameEnable() {
        $mockClient = $this->peerMock();
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $result = $manager->togglePeerByName('Cliente-X', false);

        $this->assertFalse($result['disabled']);

        $patchRequest = null;
        foreach ($mockClient->history as $req) {
            if ($req['method'] === 'PATCH' && $req['path'] === '/interface/wireguard/peers/*1c') {
                $patchRequest = $req;
                break;
            }
        }
        $this->assertNotEmpty($patchRequest, 'A PATCH request should have been made to enable the peer');
        $this->assertEquals('no', $patchRequest['data']['disabled']);
    }

    public function testTogglePeerByNameNotFound() {
        $mockClient = new MockMikrotikRestClient();
        $mockClient->setResponse('GET', '/interface/wireguard/peers', []);
        $manager = new WireGuardManager($mockClient, $this->peerManagerConfig());

        $threw = false;
        try {
            $manager->togglePeerByName('Inesistente', true);
        } catch (Exception $e) {
            $threw = true;
            $this->assertTrue(str_contains($e->getMessage(), 'not found'));
        }
        $this->assertTrue($threw, 'Expected exception for unknown peer name');
    }
}
