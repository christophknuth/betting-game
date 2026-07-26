<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Infrastructure;

use BettingGame\Infrastructure\Cache\FileCache;
use BettingGame\Infrastructure\Cache\CacheInvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FileCacheTest extends TestCase
{
    private FileCache $cache;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/betting-game-cache-test-' . uniqid();
        $this->cache = new FileCache($this->cacheDir, 3600);
    }

    protected function tearDown(): void
    {
        // Clean up cache files
        if (is_dir($this->cacheDir)) {
            $files = glob($this->cacheDir . '/*.cache');
            if ($files) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->cacheDir);
        }
    }

    public function testSetAndGet(): void
    {
        $this->assertTrue($this->cache->set('test_key', 'test_value'));
        $this->assertEquals('test_value', $this->cache->get('test_key'));
    }

    public function testGetWithDefault(): void
    {
        $this->assertEquals('default', $this->cache->get('nonexistent', 'default'));
    }

    public function testHas(): void
    {
        $this->cache->set('existing_key', 'value');
        
        $this->assertTrue($this->cache->has('existing_key'));
        $this->assertFalse($this->cache->has('nonexistent_key'));
    }

    public function testDelete(): void
    {
        $this->cache->set('test_key', 'test_value');
        $this->assertTrue($this->cache->has('test_key'));
        
        $this->assertTrue($this->cache->delete('test_key'));
        $this->assertFalse($this->cache->has('test_key'));
    }

    public function testClear(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        
        $this->assertTrue($this->cache->clear());
        
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testSetMultiple(): void
    {
        $values = [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ];

        $this->assertTrue($this->cache->setMultiple($values));
        
        $this->assertEquals('value1', $this->cache->get('key1'));
        $this->assertEquals('value2', $this->cache->get('key2'));
        $this->assertEquals('value3', $this->cache->get('key3'));
    }

    public function testGetMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $result = $this->cache->getMultiple(['key1', 'key2', 'key3'], 'default');
        
        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'default',
        ], $result);
    }

    public function testDeleteMultiple(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');

        $this->assertTrue($this->cache->deleteMultiple(['key1', 'key2']));
        
        $this->assertFalse($this->cache->has('key1'));
        $this->assertFalse($this->cache->has('key2'));
    }

    public function testTtlExpiration(): void
    {
        $cache = new FileCache($this->cacheDir, 1); // 1 second TTL
        $cache->set('expiring_key', 'value', 1);
        
        $this->assertTrue($cache->has('expiring_key'));
        
        sleep(2);
        
        $this->assertFalse($cache->has('expiring_key'));
        $this->assertEquals('default', $cache->get('expiring_key', 'default'));
    }

    public function testInvalidKeyThrowsException(): void
    {
        $this->expectException(CacheInvalidArgumentException::class);
        $this->cache->set('invalid{key}', 'value');
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(CacheInvalidArgumentException::class);
        $this->cache->set('', 'value');
    }

    public function testComplexValueTypes(): void
    {
        $arrayValue = ['a' => 1, 'b' => 2, 'c' => [3, 4, 5]];
        $objectValue = (object) ['property' => 'value'];
        
        $this->cache->set('array_key', $arrayValue);
        $this->cache->set('object_key', $objectValue);
        
        $this->assertEquals($arrayValue, $this->cache->get('array_key'));
        $this->assertEquals($objectValue, $this->cache->get('object_key'));
    }
}
