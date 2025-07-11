<?php

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace Test\Comments;

use OC\Comments\Comment;
use OCP\Comments\IComment;
use OCP\Comments\IllegalIDChangeException;
use OCP\Comments\MessageTooLongException;
use Test\TestCase;

class CommentTest extends TestCase {
	/**
	 * @throws IllegalIDChangeException
	 */
	public function testSettersValidInput(): void {
		$comment = new Comment();

		$id = 'comment23';
		$parentId = 'comment11.5';
		$topMostParentId = 'comment11.0';
		$childrenCount = 6;
		$message = 'I like to comment comment';
		$verb = 'comment';
		$actor = ['type' => 'users', 'id' => 'alice'];
		$creationDT = new \DateTime();
		$latestChildDT = new \DateTime('yesterday');
		$object = ['type' => 'files', 'id' => 'file64'];
		$referenceId = sha1('referenceId');
		$metaData = ['last_edit_actor_id' => 'admin'];

		$comment
			->setId($id)
			->setParentId($parentId)
			->setTopmostParentId($topMostParentId)
			->setChildrenCount($childrenCount)
			->setMessage($message)
			->setVerb($verb)
			->setActor($actor['type'], $actor['id'])
			->setCreationDateTime($creationDT)
			->setLatestChildDateTime($latestChildDT)
			->setObject($object['type'], $object['id'])
			->setReferenceId($referenceId)
			->setMetaData($metaData);

		$this->assertSame($id, $comment->getId());
		$this->assertSame($parentId, $comment->getParentId());
		$this->assertSame($topMostParentId, $comment->getTopmostParentId());
		$this->assertSame($childrenCount, $comment->getChildrenCount());
		$this->assertSame($message, $comment->getMessage());
		$this->assertSame($verb, $comment->getVerb());
		$this->assertSame($actor['type'], $comment->getActorType());
		$this->assertSame($actor['id'], $comment->getActorId());
		$this->assertSame($creationDT, $comment->getCreationDateTime());
		$this->assertSame($latestChildDT, $comment->getLatestChildDateTime());
		$this->assertSame($object['type'], $comment->getObjectType());
		$this->assertSame($object['id'], $comment->getObjectId());
		$this->assertSame($referenceId, $comment->getReferenceId());
		$this->assertSame($metaData, $comment->getMetaData());
	}


	public function testSetIdIllegalInput(): void {
		$this->expectException(IllegalIDChangeException::class);

		$comment = new Comment();

		$comment->setId('c23');
		$comment->setId('c17');
	}

	/**
	 * @throws IllegalIDChangeException
	 */
	public function testResetId(): void {
		$comment = new Comment();
		$comment->setId('c23');
		$comment->setId('');

		$this->assertSame('', $comment->getId());
	}

	public static function simpleSetterProvider(): array {
		return [
			['Id', true],
			['TopmostParentId', true],
			['ParentId', true],
			['Message', true],
			['Verb', true],
			['Verb', ''],
			['ChildrenCount', true],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('simpleSetterProvider')]
	public function testSimpleSetterInvalidInput($field, $input): void {
		$this->expectException(\InvalidArgumentException::class);

		$comment = new Comment();
		$setter = 'set' . $field;

		$comment->$setter($input);
	}

	public static function roleSetterProvider(): array {
		return [
			['Actor', true, true],
			['Actor', 'users', true],
			['Actor', true, 'alice'],
			['Actor', ' ', ' '],
			['Object', true, true],
			['Object', 'files', true],
			['Object', true, 'file64'],
			['Object', ' ', ' '],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('roleSetterProvider')]
	public function testSetRoleInvalidInput($role, $type, $id): void {
		$this->expectException(\InvalidArgumentException::class);

		$comment = new Comment();
		$setter = 'set' . $role;
		$comment->$setter($type, $id);
	}


	public function testSetUberlongMessage(): void {
		$this->expectException(MessageTooLongException::class);

		$comment = new Comment();
		$msg = str_pad('', IComment::MAX_MESSAGE_LENGTH + 1, 'x');
		$comment->setMessage($msg);
	}

	public static function mentionsProvider(): array {
		return [
			[
				'@alice @bob look look, a cook!',
				[['type' => 'user', 'id' => 'alice'], ['type' => 'user', 'id' => 'bob']],
			],
			[
				'no mentions in this message',
				[]
			],
			[
				'@alice @bob look look, a duplication @alice test @bob!',
				[['type' => 'user', 'id' => 'alice'], ['type' => 'user', 'id' => 'bob']],
			],
			[
				'@alice is the author, notify @bob, nevertheless mention her!',
				[['type' => 'user', 'id' => 'alice'], ['type' => 'user', 'id' => 'bob']],
				/* author: */ 'alice'
			],
			[
				'@foobar and @barfoo you should know, @foo@bar.com is valid'
					. ' and so is @bar@foo.org@foobar.io I hope that clarifies everything.'
					. ' cc @23452-4333-54353-2342 @yolo!'
					. ' however the most important thing to know is that www.croissant.com/@oil is not valid'
					. ' and won\'t match anything at all',
				[
					['type' => 'user', 'id' => 'bar@foo.org@foobar.io'],
					['type' => 'user', 'id' => '23452-4333-54353-2342'],
					['type' => 'user', 'id' => 'foo@bar.com'],
					['type' => 'user', 'id' => 'foobar'],
					['type' => 'user', 'id' => 'barfoo'],
					['type' => 'user', 'id' => 'yolo'],
				],
			],
			[
				'@@chef is also a valid mention, no matter how strange it looks',
				[['type' => 'user', 'id' => '@chef']],
			],
			[
				'Also @"user with spaces" are now supported',
				[['type' => 'user', 'id' => 'user with spaces']],
			],
			[
				'Also @"guest/0123456789abcdef" are now supported',
				[['type' => 'guest', 'id' => 'guest/0123456789abcdef']],
			],
			[
				'Also @"group/My Group ID 321" are now supported',
				[['type' => 'group', 'id' => 'My Group ID 321']],
			],
			[
				'Welcome federation @"federated_group/My Group ID 321" @"federated_team/Former Cirle" @"federated_user/cloudId@http://example.tld:8080/nextcloud"! Now freshly supported',
				[
					['type' => 'federated_user', 'id' => 'cloudId@http://example.tld:8080/nextcloud'],
					['type' => 'federated_group', 'id' => 'My Group ID 321'],
					['type' => 'federated_team', 'id' => 'Former Cirle'],
				],
			],
			[
				'Emails are supported since 30.0.2 right? @"email/aa23d315de327cfc330f0401ea061005b2b0cdd45ec8346f12664dd1f34cb886"',
				[
					['type' => 'email', 'id' => 'aa23d315de327cfc330f0401ea061005b2b0cdd45ec8346f12664dd1f34cb886'],
				],
			],
		];
	}

	/**
	 *
	 * @param string $message
	 * @param array $expectedMentions
	 * @param ?string $author
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('mentionsProvider')]
	public function testMentions(string $message, array $expectedMentions, ?string $author = null): void {
		$comment = new Comment();
		$comment->setMessage($message);
		if (!is_null($author)) {
			$comment->setActor('user', $author);
		}
		$mentions = $comment->getMentions();
		$this->assertSame($expectedMentions, $mentions);
	}
}
