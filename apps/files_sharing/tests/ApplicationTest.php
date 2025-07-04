<?php

/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Files_Sharing\Tests;

use OCA\Files_Sharing\AppInfo\Application;
use OCA\Files_Sharing\Listener\BeforeDirectFileDownloadListener;
use OCA\Files_Sharing\Listener\BeforeZipCreatedListener;
use OCA\Files_Sharing\SharedStorage;
use OCP\Files\Events\BeforeDirectFileDownloadEvent;
use OCP\Files\Events\BeforeZipCreatedEvent;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use Test\TestCase;

class ApplicationTest extends TestCase {
	private Application $application;

	/** @var IUserSession */
	private $userSession;

	/** @var IRootFolder */
	private $rootFolder;


	protected function setUp(): void {
		parent::setUp();

		$this->application = new Application([]);

		$this->userSession = $this->createMock(IUserSession::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
	}

	public function providesDataForCanGet(): array {
		// normal file (sender) - can download directly
		$senderFileStorage = $this->createMock(IStorage::class);
		$senderFileStorage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(false);
		$senderFile = $this->createMock(File::class);
		$senderFile->method('getStorage')->willReturn($senderFileStorage);
		$senderUserFolder = $this->createMock(Folder::class);
		$senderUserFolder->method('get')->willReturn($senderFile);

		$result[] = [ '/bar.txt', $senderUserFolder, true ];

		// shared file (receiver) with attribute secure-view-enabled set false -
		// can download directly
		$receiverFileShareAttributes = $this->createMock(IAttributes::class);
		$receiverFileShareAttributes->method('getAttribute')->with('permissions', 'download')->willReturn(true);
		$receiverFileShare = $this->createMock(IShare::class);
		$receiverFileShare->method('getAttributes')->willReturn($receiverFileShareAttributes);
		$receiverFileStorage = $this->createMock(SharedStorage::class);
		$receiverFileStorage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(true);
		$receiverFileStorage->method('getShare')->willReturn($receiverFileShare);
		$receiverFile = $this->createMock(File::class);
		$receiverFile->method('getStorage')->willReturn($receiverFileStorage);
		$receiverUserFolder = $this->createMock(Folder::class);
		$receiverUserFolder->method('get')->willReturn($receiverFile);

		$result[] = [ '/share-bar.txt', $receiverUserFolder, true ];

		// shared file (receiver) with attribute secure-view-enabled set true -
		// cannot download directly
		$secureReceiverFileShareAttributes = $this->createMock(IAttributes::class);
		$secureReceiverFileShareAttributes->method('getAttribute')->with('permissions', 'download')->willReturn(false);
		$secureReceiverFileShare = $this->createMock(IShare::class);
		$secureReceiverFileShare->method('getAttributes')->willReturn($secureReceiverFileShareAttributes);
		$secureReceiverFileStorage = $this->createMock(SharedStorage::class);
		$secureReceiverFileStorage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(true);
		$secureReceiverFileStorage->method('getShare')->willReturn($secureReceiverFileShare);
		$secureReceiverFile = $this->createMock(File::class);
		$secureReceiverFile->method('getStorage')->willReturn($secureReceiverFileStorage);
		$secureReceiverUserFolder = $this->createMock(Folder::class);
		$secureReceiverUserFolder->method('get')->willReturn($secureReceiverFile);

		$result[] = [ '/secure-share-bar.txt', $secureReceiverUserFolder, false ];

		return $result;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('providesDataForCanGet')]
	public function testCheckDirectCanBeDownloaded(string $path, Folder $userFolder, bool $run): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userSession->method('isLoggedIn')->willReturn(true);
		$this->rootFolder->method('getUserFolder')->willReturn($userFolder);

		// Simulate direct download of file
		$event = new BeforeDirectFileDownloadEvent($path);
		$listener = new BeforeDirectFileDownloadListener(
			$this->userSession,
			$this->rootFolder
		);
		$listener->handle($event);

		$this->assertEquals($run, $event->isSuccessful());
	}

	public function providesDataForCanZip(): array {
		// Mock: Normal file/folder storage
		$nonSharedStorage = $this->createMock(IStorage::class);
		$nonSharedStorage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(false);

		// Mock: Secure-view file/folder shared storage
		$secureReceiverFileShareAttributes = $this->createMock(IAttributes::class);
		$secureReceiverFileShareAttributes->method('getAttribute')->with('permissions', 'download')->willReturn(false);
		$secureReceiverFileShare = $this->createMock(IShare::class);
		$secureReceiverFileShare->method('getAttributes')->willReturn($secureReceiverFileShareAttributes);
		$secureSharedStorage = $this->createMock(SharedStorage::class);
		$secureSharedStorage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(true);
		$secureSharedStorage->method('getShare')->willReturn($secureReceiverFileShare);

		// 1. can download zipped 2 non-shared files inside non-shared folder
		// 2. can download zipped non-shared folder
		$sender1File = $this->createMock(File::class);
		$sender1File->method('getStorage')->willReturn($nonSharedStorage);
		$sender1Folder = $this->createMock(Folder::class);
		$sender1Folder->method('getStorage')->willReturn($nonSharedStorage);
		$sender1Folder->method('getDirectoryListing')->willReturn([$sender1File, $sender1File]);
		$sender1RootFolder = $this->createMock(Folder::class);
		$sender1RootFolder->method('getStorage')->willReturn($nonSharedStorage);
		$sender1RootFolder->method('getDirectoryListing')->willReturn([$sender1Folder]);
		$sender1UserFolder = $this->createMock(Folder::class);
		$sender1UserFolder->method('get')->willReturn($sender1RootFolder);

		$return[] = [ '/folder', ['bar1.txt', 'bar2.txt'], $sender1UserFolder, true ];
		$return[] = [ '/', ['folder'], $sender1UserFolder, true ];

		// 3. cannot download zipped 1 non-shared file and 1 secure-shared inside non-shared folder
		$receiver1File = $this->createMock(File::class);
		$receiver1File->method('getStorage')->willReturn($nonSharedStorage);
		$receiver1SecureFile = $this->createMock(File::class);
		$receiver1SecureFile->method('getStorage')->willReturn($secureSharedStorage);
		$receiver1Folder = $this->createMock(Folder::class);
		$receiver1Folder->method('getStorage')->willReturn($nonSharedStorage);
		$receiver1Folder->method('getDirectoryListing')->willReturn([$receiver1File, $receiver1SecureFile]);
		$receiver1RootFolder = $this->createMock(Folder::class);
		$receiver1RootFolder->method('getStorage')->willReturn($nonSharedStorage);
		$receiver1RootFolder->method('getDirectoryListing')->willReturn([$receiver1Folder]);
		$receiver1UserFolder = $this->createMock(Folder::class);
		$receiver1UserFolder->method('get')->willReturn($receiver1RootFolder);

		$return[] = [ '/folder', ['secured-bar1.txt', 'bar2.txt'], $receiver1UserFolder, false ];

		// 4. cannot download zipped secure-shared folder
		$receiver2Folder = $this->createMock(Folder::class);
		$receiver2Folder->method('getStorage')->willReturn($secureSharedStorage);
		$receiver2RootFolder = $this->createMock(Folder::class);
		$receiver2RootFolder->method('getStorage')->willReturn($nonSharedStorage);
		$receiver2RootFolder->method('getDirectoryListing')->willReturn([$receiver2Folder]);
		$receiver2UserFolder = $this->createMock(Folder::class);
		$receiver2UserFolder->method('get')->willReturn($receiver2RootFolder);

		$return[] = [ '/', ['secured-folder'], $receiver2UserFolder, false ];

		return $return;
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('providesDataForCanZip')]
	public function testCheckZipCanBeDownloaded(string $dir, array $files, Folder $userFolder, bool $run): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('test');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userSession->method('isLoggedIn')->willReturn(true);

		$this->rootFolder->method('getUserFolder')->with('test')->willReturn($userFolder);

		// Simulate zip download of folder folder
		$event = new BeforeZipCreatedEvent($dir, $files);
		$listener = new BeforeZipCreatedListener(
			$this->userSession,
			$this->rootFolder
		);
		$listener->handle($event);


		$this->assertEquals($run, $event->isSuccessful());
		$this->assertEquals($run, $event->getErrorMessage() === null);
	}

	public function testCheckFileUserNotFound(): void {
		$this->userSession->method('isLoggedIn')->willReturn(false);

		// Simulate zip download of folder folder
		$event = new BeforeZipCreatedEvent('/test', ['test.txt']);
		$listener = new BeforeZipCreatedListener(
			$this->userSession,
			$this->rootFolder
		);
		$listener->handle($event);

		// It should run as this would restrict e.g. share links otherwise
		$this->assertTrue($event->isSuccessful());
		$this->assertEquals(null, $event->getErrorMessage());
	}
}
