<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */

namespace Test\Files\Storage\Wrapper;

use Exception;
use OC\Encryption\Exceptions\ModuleDoesNotExistsException;
use OC\Encryption\File;
use OC\Encryption\Util;
use OC\Files\Cache\Cache;
use OC\Files\Cache\CacheEntry;
use OC\Files\Mount\MountPoint;
use OC\Files\Storage\Temporary;
use OC\Files\Storage\Wrapper\Encryption;
use OC\Files\View;
use OC\Memcache\ArrayCache;
use OC\User\Manager;
use OCP\Encryption\IEncryptionModule;
use OCP\Encryption\IFile;
use OCP\Encryption\Keys\IStorage;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Cache\ICache;
use OCP\Files\Mount\IMountPoint;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\Files\Storage\Storage;

class EncryptionTest extends Storage {
	/**
	 * block size will always be 8192 for a PHP stream
	 * @see https://bugs.php.net/bug.php?id=21641
	 */
	protected int $headerSize = 8192;
	private Temporary $sourceStorage;
	/** @var Encryption&MockObject */
	protected $instance;
	private \OC\Encryption\Keys\Storage&MockObject $keyStore;
	private Util&MockObject $util;
	private \OC\Encryption\Manager&MockObject $encryptionManager;
	private IEncryptionModule&MockObject $encryptionModule;
	private Cache&MockObject $cache;
	private LoggerInterface&MockObject $logger;
	private File&MockObject $file;
	private MountPoint&MockObject $mount;
	private \OC\Files\Mount\Manager&MockObject $mountManager;
	private \OC\Group\Manager&MockObject $groupManager;
	private IConfig&MockObject $config;
	private ArrayCache&MockObject $arrayCache;
	/** dummy unencrypted size */
	private int $dummySize = -1;

	protected function setUp(): void {
		parent::setUp();

		$mockModule = $this->buildMockModule();
		$this->encryptionManager = $this->getMockBuilder(\OC\Encryption\Manager::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEncryptionModule', 'isEnabled'])
			->getMock();
		$this->encryptionManager->expects($this->any())
			->method('getEncryptionModule')
			->willReturn($mockModule);

		$this->arrayCache = $this->createMock(ArrayCache::class);
		$this->config = $this->getMockBuilder(IConfig::class)
			->disableOriginalConstructor()
			->getMock();
		$this->groupManager = $this->getMockBuilder('\OC\Group\Manager')
			->disableOriginalConstructor()
			->getMock();

		$this->util = $this->getMockBuilder(Util::class)
			->onlyMethods(['getUidAndFilename', 'isFile', 'isExcluded', 'stripPartialFileExtension'])
			->setConstructorArgs([new View(), new Manager(
				$this->config,
				$this->createMock(ICacheFactory::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class),
			), $this->groupManager, $this->config, $this->arrayCache])
			->getMock();
		$this->util->expects($this->any())
			->method('getUidAndFilename')
			->willReturnCallback(function ($path) {
				return ['user1', $path];
			});
		$this->util->expects($this->any())
			->method('stripPartialFileExtension')
			->willReturnCallback(function ($path) {
				return $path;
			});

		$this->file = $this->getMockBuilder(File::class)
			->disableOriginalConstructor()
			->onlyMethods(['getAccessList'])
			->getMock();
		$this->file->expects($this->any())->method('getAccessList')->willReturn([]);

		$this->logger = $this->createMock(LoggerInterface::class);

		$this->sourceStorage = new Temporary([]);

		$this->keyStore = $this->createMock(\OC\Encryption\Keys\Storage::class);

		$this->mount = $this->getMockBuilder(MountPoint::class)
			->disableOriginalConstructor()
			->onlyMethods(['getOption'])
			->getMock();
		$this->mount->expects($this->any())->method('getOption')->willReturnCallback(function ($option, $default) {
			if ($option === 'encrypt' && $default === true) {
				global $mockedMountPointEncryptionEnabled;
				if ($mockedMountPointEncryptionEnabled !== null) {
					return $mockedMountPointEncryptionEnabled;
				}
			}
			return true;
		});

		$this->cache = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();
		$this->cache->expects($this->any())
			->method('get')
			->willReturnCallback(function ($path) {
				return ['encrypted' => false, 'path' => $path];
			});

		$this->mountManager = $this->createMock(\OC\Files\Mount\Manager::class);
		$this->mountManager->method('findByStorageId')
			->willReturn([]);

		$this->instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $this->sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$this->util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache
				]
			)
			->onlyMethods(['getMetaData', 'getCache', 'getEncryptionModule'])
			->getMock();

		$this->instance->expects($this->any())
			->method('getMetaData')
			->willReturnCallback(function ($path) {
				return ['encrypted' => true, 'size' => $this->dummySize, 'path' => $path];
			});

		$this->instance->expects($this->any())
			->method('getCache')
			->willReturn($this->cache);

		$this->instance->expects($this->any())
			->method('getEncryptionModule')
			->willReturn($mockModule);
	}

	protected function buildMockModule(): IEncryptionModule&MockObject {
		$this->encryptionModule = $this->getMockBuilder('\OCP\Encryption\IEncryptionModule')
			->disableOriginalConstructor()
			->onlyMethods(['getId', 'getDisplayName', 'begin', 'end', 'encrypt', 'decrypt', 'update', 'shouldEncrypt', 'getUnencryptedBlockSize', 'isReadable', 'encryptAll', 'prepareDecryptAll', 'isReadyForUser', 'needDetailedAccessList'])
			->getMock();

		$this->encryptionModule->expects($this->any())->method('getId')->willReturn('UNIT_TEST_MODULE');
		$this->encryptionModule->expects($this->any())->method('getDisplayName')->willReturn('Unit test module');
		$this->encryptionModule->expects($this->any())->method('begin')->willReturn([]);
		$this->encryptionModule->expects($this->any())->method('end')->willReturn('');
		$this->encryptionModule->expects($this->any())->method('encrypt')->willReturnArgument(0);
		$this->encryptionModule->expects($this->any())->method('decrypt')->willReturnArgument(0);
		$this->encryptionModule->expects($this->any())->method('update')->willReturn(true);
		$this->encryptionModule->expects($this->any())->method('shouldEncrypt')->willReturn(true);
		$this->encryptionModule->expects($this->any())->method('getUnencryptedBlockSize')->willReturn(8192);
		$this->encryptionModule->expects($this->any())->method('isReadable')->willReturn(true);
		$this->encryptionModule->expects($this->any())->method('needDetailedAccessList')->willReturn(false);
		return $this->encryptionModule;
	}

	/**
	 *
	 * @param string $path
	 * @param array $metaData
	 * @param bool $encrypted
	 * @param bool $unencryptedSizeSet
	 * @param int $storedUnencryptedSize
	 * @param array $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestGetMetaData')]
	public function testGetMetaData($path, $metaData, $encrypted, $unencryptedSizeSet, $storedUnencryptedSize, $expected): void {
		$sourceStorage = $this->getMockBuilder('\OC\Files\Storage\Storage')
			->disableOriginalConstructor()->getMock();

		$cache = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();
		$cache->expects($this->any())
			->method('get')
			->willReturnCallback(
				function ($path) use ($encrypted) {
					return new CacheEntry(['encrypted' => $encrypted, 'path' => $path, 'size' => 0, 'fileid' => 1]);
				}
			);

		$this->instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$this->util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache,
				]
			)
			->onlyMethods(['getCache', 'verifyUnencryptedSize'])
			->getMock();

		if ($unencryptedSizeSet) {
			$this->invokePrivate($this->instance, 'unencryptedSize', [[$path => $storedUnencryptedSize]]);
		}

		$fileEntry = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();
		$sourceStorage->expects($this->once())->method('getMetaData')->with($path)
			->willReturn($metaData);
		$sourceStorage->expects($this->any())
			->method('getCache')
			->with($path)
			->willReturn($fileEntry);
		if ($metaData !== null) {
			$fileEntry->expects($this->any())
				->method('get')
				->with($metaData['fileid']);
		}

		$this->instance->expects($this->any())->method('getCache')->willReturn($cache);
		if ($expected !== null) {
			$this->instance->expects($this->any())->method('verifyUnencryptedSize')
				->with($path, 0)->willReturn($expected['size']);
		}

		$result = $this->instance->getMetaData($path);
		if (isset($expected['encrypted'])) {
			$this->assertSame($expected['encrypted'], (bool)$result['encrypted']);

			if (isset($expected['encryptedVersion'])) {
				$this->assertSame($expected['encryptedVersion'], $result['encryptedVersion']);
			}
		}

		if ($expected !== null) {
			$this->assertSame($expected['size'], $result['size']);
		} else {
			$this->assertSame(null, $result);
		}
	}

	public static function dataTestGetMetaData(): array {
		return [
			['/test.txt', ['size' => 42, 'encrypted' => 2, 'encryptedVersion' => 2, 'fileid' => 1], true, true, 12, ['size' => 12, 'encrypted' => true, 'encryptedVersion' => 2]],
			['/test.txt', null, true, true, 12, null],
			['/test.txt', ['size' => 42, 'encrypted' => 0, 'fileid' => 1], false, false, 12, ['size' => 42, 'encrypted' => false]],
			['/test.txt', ['size' => 42, 'encrypted' => false, 'fileid' => 1], true, false, 12, ['size' => 12, 'encrypted' => true]]
		];
	}

	public function testFilesize(): void {
		$cache = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();
		$cache->expects($this->any())
			->method('get')
			->willReturn(new CacheEntry(['encrypted' => true, 'path' => '/test.txt', 'size' => 0, 'fileid' => 1]));

		$this->instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $this->sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$this->util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache,
				]
			)
			->onlyMethods(['getCache', 'verifyUnencryptedSize'])
			->getMock();

		$this->instance->expects($this->any())->method('getCache')->willReturn($cache);
		$this->instance->expects($this->any())->method('verifyUnencryptedSize')
			->willReturn(42);


		$this->assertSame(42,
			$this->instance->filesize('/test.txt')
		);
	}

	/**
	 *
	 * @param int $encryptedSize
	 * @param int $unencryptedSize
	 * @param bool $failure
	 * @param int $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestVerifyUnencryptedSize')]
	public function testVerifyUnencryptedSize($encryptedSize, $unencryptedSize, $failure, $expected): void {
		$sourceStorage = $this->getMockBuilder('\OC\Files\Storage\Storage')
			->disableOriginalConstructor()->getMock();

		$this->instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$this->util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache,
				]
			)
			->onlyMethods(['fixUnencryptedSize'])
			->getMock();

		$sourceStorage->expects($this->once())->method('filesize')->willReturn($encryptedSize);

		$this->instance->expects($this->any())->method('fixUnencryptedSize')
			->with('/test.txt', $encryptedSize, $unencryptedSize)
			->willReturnCallback(
				function () use ($failure, $expected) {
					if ($failure) {
						throw new Exception();
					} else {
						return $expected;
					}
				}
			);

		$this->assertSame(
			$expected,
			$this->invokePrivate($this->instance, 'verifyUnencryptedSize', ['/test.txt', $unencryptedSize])
		);
	}

	public static function dataTestVerifyUnencryptedSize(): array {
		return [
			[120, 80, false, 80],
			[120, 120, false, 80],
			[120, -1, false, 80],
			[120, -1, true, -1]
		];
	}

	/**
	 *
	 * @param string $source
	 * @param string $target
	 * @param $encryptionEnabled
	 * @param boolean $renameKeysReturn
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestCopyAndRename')]
	public function testRename($source,
		$target,
		$encryptionEnabled,
		$renameKeysReturn): void {
		if ($encryptionEnabled) {
			$this->keyStore
				->expects($this->once())
				->method('renameKeys')
				->willReturn($renameKeysReturn);
		} else {
			$this->keyStore
				->expects($this->never())->method('renameKeys');
		}
		$this->util->expects($this->any())
			->method('isFile')->willReturn(true);
		$this->encryptionManager->expects($this->once())
			->method('isEnabled')->willReturn($encryptionEnabled);

		$this->instance->mkdir($source);
		$this->instance->mkdir(dirname($target));
		$this->instance->rename($source, $target);
	}

	public function testCopyEncryption(): void {
		$this->instance->file_put_contents('source.txt', 'bar');
		$this->instance->copy('source.txt', 'target.txt');
		$this->assertSame('bar', $this->instance->file_get_contents('target.txt'));
		$targetMeta = $this->instance->getMetaData('target.txt');
		$sourceMeta = $this->instance->getMetaData('source.txt');
		$this->assertSame($sourceMeta['encrypted'], $targetMeta['encrypted']);
		$this->assertSame($sourceMeta['size'], $targetMeta['size']);
	}

	/**
	 * data provider for testCopyTesting() and dataTestCopyAndRename()
	 *
	 * @return array
	 */
	public static function dataTestCopyAndRename(): array {
		return [
			['source', 'target', true, false, false],
			['source', 'target', true, true, false],
			['source', '/subFolder/target', true, false, false],
			['source', '/subFolder/target', true, true, true],
			['source', '/subFolder/target', false, true, false],
		];
	}

	public function testIsLocal(): void {
		$this->encryptionManager->expects($this->once())
			->method('isEnabled')->willReturn(true);
		$this->assertFalse($this->instance->isLocal());
	}

	/**
	 *
	 * @param string $path
	 * @param boolean $rmdirResult
	 * @param boolean $isExcluded
	 * @param boolean $encryptionEnabled
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestRmdir')]
	public function testRmdir($path, $rmdirResult, $isExcluded, $encryptionEnabled): void {
		$sourceStorage = $this->getMockBuilder('\OC\Files\Storage\Storage')
			->disableOriginalConstructor()->getMock();

		$util = $this->getMockBuilder('\OC\Encryption\Util')->disableOriginalConstructor()->getMock();

		$sourceStorage->expects($this->once())->method('rmdir')->willReturn($rmdirResult);
		$util->expects($this->any())->method('isExcluded')->willReturn($isExcluded);
		$this->encryptionManager->expects($this->any())->method('isEnabled')->willReturn($encryptionEnabled);

		$encryptionStorage = new Encryption(
			[
				'storage' => $sourceStorage,
				'root' => 'foo',
				'mountPoint' => '/mountPoint',
				'mount' => $this->mount
			],
			$this->encryptionManager,
			$util,
			$this->logger,
			$this->file,
			null,
			$this->keyStore,
			$this->mountManager,
			$this->arrayCache,
		);


		if ($rmdirResult === true && $isExcluded === false && $encryptionEnabled === true) {
			$this->keyStore->expects($this->once())->method('deleteAllFileKeys')->with('/mountPoint' . $path);
		} else {
			$this->keyStore->expects($this->never())->method('deleteAllFileKeys');
		}

		$encryptionStorage->rmdir($path);
	}

	public static function dataTestRmdir(): array {
		return [
			['/file.txt', true, true, true],
			['/file.txt', false, true, true],
			['/file.txt', true, false, true],
			['/file.txt', false, false, true],
			['/file.txt', true, true, false],
			['/file.txt', false, true, false],
			['/file.txt', true, false, false],
			['/file.txt', false, false, false],
		];
	}

	/**
	 *
	 * @param boolean $excluded
	 * @param boolean $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestCopyKeys')]
	public function testCopyKeys($excluded, $expected): void {
		$this->util->expects($this->once())
			->method('isExcluded')
			->willReturn($excluded);

		if ($excluded) {
			$this->keyStore->expects($this->never())->method('copyKeys');
		} else {
			$this->keyStore->expects($this->once())->method('copyKeys')->willReturn(true);
		}

		$this->assertSame($expected,
			self::invokePrivate($this->instance, 'copyKeys', ['/source', '/target'])
		);
	}

	public static function dataTestCopyKeys(): array {
		return [
			[true, false],
			[false, true],
		];
	}

	/**
	 *
	 * @param string $path
	 * @param bool $strippedPathExists
	 * @param string $strippedPath
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestGetHeader')]
	public function testGetHeader($path, $strippedPathExists, $strippedPath): void {
		$sourceStorage = $this->getMockBuilder('\OC\Files\Storage\Storage')
			->disableOriginalConstructor()->getMock();

		$util = $this->getMockBuilder('\OC\Encryption\Util')
			->setConstructorArgs(
				[
					new View(),
					new Manager(
						$this->config,
						$this->createMock(ICacheFactory::class),
						$this->createMock(IEventDispatcher::class),
						$this->createMock(LoggerInterface::class),
					),
					$this->groupManager,
					$this->config,
					$this->arrayCache
				]
			)->getMock();

		$cache = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();
		$cache->expects($this->any())
			->method('get')
			->willReturnCallback(function ($path) {
				return ['encrypted' => true, 'path' => $path];
			});

		$instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache,
				]
			)
			->onlyMethods(['getCache', 'readFirstBlock'])
			->getMock();

		$instance->method('getCache')->willReturn($cache);

		$util->method('parseRawHeader')
			->willReturn([Util::HEADER_ENCRYPTION_MODULE_KEY => 'OC_DEFAULT_MODULE']);

		if ($strippedPathExists) {
			$instance->method('readFirstBlock')
				->with($strippedPath)->willReturn('');
		} else {
			$instance->method('readFirstBlock')
				->with($path)->willReturn('');
		}

		$util->expects($this->once())->method('stripPartialFileExtension')
			->with($path)->willReturn($strippedPath);
		$sourceStorage->expects($this->once())
			->method('is_file')
			->with($strippedPath)
			->willReturn($strippedPathExists);

		$this->invokePrivate($instance, 'getHeader', [$path]);
	}

	public static function dataTestGetHeader(): array {
		return [
			['/foo/bar.txt', false, '/foo/bar.txt'],
			['/foo/bar.txt.part', false, '/foo/bar.txt'],
			['/foo/bar.txt.ocTransferId7437493.part', false, '/foo/bar.txt'],
			['/foo/bar.txt.part', true, '/foo/bar.txt'],
			['/foo/bar.txt.ocTransferId7437493.part', true, '/foo/bar.txt'],
		];
	}

	/**
	 * test if getHeader adds the default module correctly to the header for
	 * legacy files
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestGetHeaderAddLegacyModule')]
	public function testGetHeaderAddLegacyModule($header, $isEncrypted, $strippedPathExists, $expected): void {
		$sourceStorage = $this->getMockBuilder(\OC\Files\Storage\Storage::class)
			->disableOriginalConstructor()->getMock();

		$sourceStorage->expects($this->once())
			->method('is_file')
			->with('test.txt')
			->willReturn($strippedPathExists);

		$util = $this->getMockBuilder(Util::class)
			->onlyMethods(['stripPartialFileExtension', 'parseRawHeader'])
			->setConstructorArgs([new View(), new Manager(
				$this->config,
				$this->createMock(ICacheFactory::class),
				$this->createMock(IEventDispatcher::class),
				$this->createMock(LoggerInterface::class),
			), $this->groupManager, $this->config, $this->arrayCache])
			->getMock();
		$util->expects($this->any())
			->method('stripPartialFileExtension')
			->willReturnCallback(function ($path) {
				return $path;
			});

		$cache = $this->createMock(Cache::class);
		$cache->expects($this->any())
			->method('get')
			->willReturnCallback(function ($path) use ($isEncrypted) {
				return ['encrypted' => $isEncrypted, 'path' => $path];
			});

		$instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $sourceStorage,
						'root' => 'foo',
						'mountPoint' => '/',
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache,
				]
			)
			->onlyMethods(['readFirstBlock', 'getCache'])
			->getMock();

		$instance->method('readFirstBlock')->willReturn('');

		$util->method(('parseRawHeader'))->willReturn($header);
		$instance->method('getCache')->willReturn($cache);

		$result = $this->invokePrivate($instance, 'getHeader', ['test.txt']);
		$this->assertSameSize($expected, $result);
		foreach ($result as $key => $value) {
			$this->assertArrayHasKey($key, $expected);
			$this->assertSame($expected[$key], $value);
		}
	}

	public static function dataTestGetHeaderAddLegacyModule(): array {
		return [
			[['cipher' => 'AES-128'], true, true, ['cipher' => 'AES-128', Util::HEADER_ENCRYPTION_MODULE_KEY => 'OC_DEFAULT_MODULE']],
			[[], true, false, []],
			[[], true, true, [Util::HEADER_ENCRYPTION_MODULE_KEY => 'OC_DEFAULT_MODULE']],
			[[], false, true, []],
		];
	}

	public static function dataCopyBetweenStorage(): array {
		return [
			[true, true, true],
			[true, false, false],
			[false, true, false],
			[false, false, false],
		];
	}

	public function testCopyBetweenStorageMinimumEncryptedVersion(): void {
		$storage2 = $this->createMock(\OC\Files\Storage\Storage::class);

		$sourceInternalPath = $targetInternalPath = 'file.txt';
		$preserveMtime = $isRename = false;

		$storage2->expects($this->any())
			->method('fopen')
			->willReturnCallback(function ($path, $mode) {
				$temp = Server::get(ITempManager::class);
				return fopen($temp->getTemporaryFile(), $mode);
			});
		$storage2->method('getId')
			->willReturn('stroage2');
		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())
			->method('get')
			->with($sourceInternalPath)
			->willReturn(['encryptedVersion' => 0]);
		$storage2->expects($this->once())
			->method('getCache')
			->willReturn($cache);
		$this->encryptionManager->expects($this->any())
			->method('isEnabled')
			->willReturn(true);
		global $mockedMountPointEncryptionEnabled;
		$mockedMountPointEncryptionEnabled = true;

		$expectedCachePut = [
			'encrypted' => true,
		];
		$expectedCachePut['encryptedVersion'] = 1;

		$this->cache->expects($this->once())
			->method('put')
			->with($sourceInternalPath, $expectedCachePut);

		$this->invokePrivate($this->instance, 'copyBetweenStorage', [$storage2, $sourceInternalPath, $targetInternalPath, $preserveMtime, $isRename]);

		$this->assertFalse(false);
	}

	/**
	 *
	 * @param bool $encryptionEnabled
	 * @param bool $mountPointEncryptionEnabled
	 * @param bool $expectedEncrypted
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataCopyBetweenStorage')]
	public function testCopyBetweenStorage($encryptionEnabled, $mountPointEncryptionEnabled, $expectedEncrypted): void {
		$storage2 = $this->createMock(\OC\Files\Storage\Storage::class);

		$sourceInternalPath = $targetInternalPath = 'file.txt';
		$preserveMtime = $isRename = false;

		$storage2->expects($this->any())
			->method('fopen')
			->willReturnCallback(function ($path, $mode) {
				$temp = Server::get(ITempManager::class);
				return fopen($temp->getTemporaryFile(), $mode);
			});
		$storage2->method('getId')
			->willReturn('stroage2');
		if ($expectedEncrypted) {
			$cache = $this->createMock(ICache::class);
			$cache->expects($this->once())
				->method('get')
				->with($sourceInternalPath)
				->willReturn(['encryptedVersion' => 12345]);
			$storage2->expects($this->once())
				->method('getCache')
				->willReturn($cache);
		}
		$this->encryptionManager->expects($this->any())
			->method('isEnabled')
			->willReturn($encryptionEnabled);
		// FIXME can not overwrite the return after definition
		//		$this->mount->expects($this->at(0))
		//			->method('getOption')
		//			->with('encrypt', true)
		//			->willReturn($mountPointEncryptionEnabled);
		global $mockedMountPointEncryptionEnabled;
		$mockedMountPointEncryptionEnabled = $mountPointEncryptionEnabled;

		$expectedCachePut = [
			'encrypted' => $expectedEncrypted,
		];
		if ($expectedEncrypted === true) {
			$expectedCachePut['encryptedVersion'] = 1;
		}

		$this->arrayCache->expects($this->never())->method('set');

		$this->cache->expects($this->once())
			->method('put')
			->with($sourceInternalPath, $expectedCachePut);

		$this->invokePrivate($this->instance, 'copyBetweenStorage', [$storage2, $sourceInternalPath, $targetInternalPath, $preserveMtime, $isRename]);

		$this->assertFalse(false);
	}

	/**
	 *
	 * @param string $sourceInternalPath
	 * @param string $targetInternalPath
	 * @param bool $copyResult
	 * @param bool $encrypted
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestCopyBetweenStorageVersions')]
	public function testCopyBetweenStorageVersions($sourceInternalPath, $targetInternalPath, $copyResult, $encrypted): void {
		$sourceStorage = $this->createMock(\OC\Files\Storage\Storage::class);

		$targetStorage = $this->createMock(\OC\Files\Storage\Storage::class);

		$cache = $this->getMockBuilder('\OC\Files\Cache\Cache')
			->disableOriginalConstructor()->getMock();

		$mountPoint = '/mountPoint';

		/** @var Encryption |MockObject $instance */
		$instance = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					[
						'storage' => $targetStorage,
						'root' => 'foo',
						'mountPoint' => $mountPoint,
						'mount' => $this->mount
					],
					$this->encryptionManager,
					$this->util,
					$this->logger,
					$this->file,
					null,
					$this->keyStore,
					$this->mountManager,
					$this->arrayCache
				]
			)
			->onlyMethods(['updateUnencryptedSize', 'getCache'])
			->getMock();

		$targetStorage->expects($this->once())->method('copyFromStorage')
			->with($sourceStorage, $sourceInternalPath, $targetInternalPath)
			->willReturn($copyResult);

		$instance->expects($this->any())->method('getCache')
			->willReturn($cache);

		$this->arrayCache->expects($this->once())->method('set')
			->with('encryption_copy_version_' . $sourceInternalPath, true);

		if ($copyResult) {
			$cache->expects($this->once())->method('get')
				->with($sourceInternalPath)
				->willReturn(new CacheEntry(['encrypted' => $encrypted, 'size' => 42]));
			if ($encrypted) {
				$instance->expects($this->once())->method('updateUnencryptedSize')
					->with($mountPoint . $targetInternalPath, 42);
			} else {
				$instance->expects($this->never())->method('updateUnencryptedSize');
			}
		} else {
			$instance->expects($this->never())->method('updateUnencryptedSize');
		}

		$result = $this->invokePrivate(
			$instance,
			'copyBetweenStorage',
			[
				$sourceStorage,
				$sourceInternalPath,
				$targetInternalPath,
				false,
				false
			]
		);

		$this->assertSame($copyResult, $result);
	}

	public static function dataTestCopyBetweenStorageVersions(): array {
		return [
			['/files/foo.txt', '/files_versions/foo.txt.768743', true, true],
			['/files/foo.txt', '/files_versions/foo.txt.768743', true, false],
			['/files/foo.txt', '/files_versions/foo.txt.768743', false, true],
			['/files/foo.txt', '/files_versions/foo.txt.768743', false, false],
			['/files_versions/foo.txt.6487634', '/files/foo.txt', true, true],
			['/files_versions/foo.txt.6487634', '/files/foo.txt', true, false],
			['/files_versions/foo.txt.6487634', '/files/foo.txt', false, true],
			['/files_versions/foo.txt.6487634', '/files/foo.txt', false, false],

		];
	}

	/**
	 * @param string $path
	 * @param bool $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestIsVersion')]
	public function testIsVersion($path, $expected): void {
		$this->assertSame($expected,
			$this->invokePrivate($this->instance, 'isVersion', [$path])
		);
	}

	public static function dataTestIsVersion(): array {
		return [
			['files_versions/foo', true],
			['/files_versions/foo', true],
			['//files_versions/foo', true],
			['files/versions/foo', false],
			['files/files_versions/foo', false],
			['files_versions_test/foo', false],
		];
	}

	/**
	 *
	 * @param bool $encryptMountPoint
	 * @param mixed $encryptionModule
	 * @param bool $encryptionModuleShouldEncrypt
	 * @param bool $expected
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('dataTestShouldEncrypt')]
	public function testShouldEncrypt(
		$encryptMountPoint,
		$encryptionModule,
		$encryptionModuleShouldEncrypt,
		$expected,
	): void {
		$encryptionManager = $this->createMock(\OC\Encryption\Manager::class);
		$util = $this->createMock(Util::class);
		$fileHelper = $this->createMock(IFile::class);
		$keyStorage = $this->createMock(IStorage::class);
		$mountManager = $this->createMock(\OC\Files\Mount\Manager::class);
		$mount = $this->createMock(IMountPoint::class);
		$arrayCache = $this->createMock(ArrayCache::class);
		$path = '/welcome.txt';
		$fullPath = 'admin/files/welcome.txt';
		$defaultEncryptionModule = $this->createMock(IEncryptionModule::class);

		$wrapper = $this->getMockBuilder(Encryption::class)
			->setConstructorArgs(
				[
					['mountPoint' => '', 'mount' => $mount, 'storage' => ''],
					$encryptionManager,
					$util,
					$this->logger,
					$fileHelper,
					null,
					$keyStorage,
					$mountManager,
					$arrayCache
				]
			)
			->onlyMethods(['getFullPath', 'getEncryptionModule'])
			->getMock();

		if ($encryptionModule === true) {
			/** @var IEncryptionModule|MockObject $encryptionModule */
			$encryptionModule = $this->createMock(IEncryptionModule::class);
		}

		$wrapper->method('getFullPath')->with($path)->willReturn($fullPath);
		$wrapper->expects($encryptMountPoint ? $this->once() : $this->never())
			->method('getEncryptionModule')
			->with($fullPath)
			->willReturnCallback(
				function () use ($encryptionModule) {
					if ($encryptionModule === false) {
						throw new ModuleDoesNotExistsException();
					}
					return $encryptionModule;
				}
			);
		$mount->expects($this->once())->method('getOption')->with('encrypt', true)
			->willReturn($encryptMountPoint);

		if ($encryptionModule !== null && $encryptionModule !== false) {
			$encryptionModule
				->method('shouldEncrypt')
				->with($fullPath)
				->willReturn($encryptionModuleShouldEncrypt);
		}

		if ($encryptionModule === null) {
			$encryptionManager->expects($this->once())
				->method('getEncryptionModule')
				->willReturn($defaultEncryptionModule);
		}
		$defaultEncryptionModule->method('shouldEncrypt')->willReturn(true);

		$result = $this->invokePrivate($wrapper, 'shouldEncrypt', [$path]);

		$this->assertSame($expected, $result);
	}

	public static function dataTestShouldEncrypt(): array {
		return [
			[false, false, false, false],
			[true, false, false, false],
			[true, true, false, false],
			[true, true, true, true],
			[true, null, false, true],
		];
	}
}
