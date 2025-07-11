<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\DAV\Tests\unit\CalDAV\Reminder;

use DateTime;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Reminder\Backend;
use OCA\DAV\CalDAV\Reminder\INotificationProvider;
use OCA\DAV\CalDAV\Reminder\NotificationProviderManager;
use OCA\DAV\CalDAV\Reminder\ReminderService;
use OCA\DAV\Connector\Sabre\Principal;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ReminderServiceTest extends TestCase {
	private Backend&MockObject $backend;
	private NotificationProviderManager&MockObject $notificationProviderManager;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private CalDavBackend&MockObject $caldavBackend;
	private ITimeFactory&MockObject $timeFactory;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;
	private Principal&MockObject $principalConnector;
	private ReminderService $reminderService;

	public const CALENDAR_DATA = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
LOCATION:Somewhere ...
DESCRIPTION:maybe ....
DTSTART;TZID=Europe/Berlin;VALUE=DATE:20160609
DTEND;TZID=Europe/Berlin;VALUE=DATE:20160610
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT15M
END:VALARM
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER;VALUE=DATE-TIME:20160608T000000Z
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;

	public const CALENDAR_DATA_REPEAT = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
LOCATION:Somewhere ...
DESCRIPTION:maybe ....
DTSTART;TZID=Europe/Berlin;VALUE=DATE:20160609
DTEND;TZID=Europe/Berlin;VALUE=DATE:20160610
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT15M
REPEAT:4
DURATION:PT2M
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;

	public const CALENDAR_DATA_RECURRING = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
LOCATION:Somewhere ...
DESCRIPTION:maybe ....
DTSTART;TZID=Europe/Berlin;VALUE=DATE:20160609
DTEND;TZID=Europe/Berlin;VALUE=DATE:20160610
RRULE:FREQ=WEEKLY
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT15M
END:VALARM
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-P8D
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;

	public const CALENDAR_DATA_RECURRING_REPEAT = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
LOCATION:Somewhere ...
DESCRIPTION:maybe ....
DTSTART;TZID=Europe/Berlin;VALUE=DATE:20160609
DTEND;TZID=Europe/Berlin;VALUE=DATE:20160610
RRULE:FREQ=WEEKLY
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT15M
REPEAT:4
DURATION:PT2M
END:VALARM
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-P8D
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;

	public const CALENDAR_DATA_NO_ALARM = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
LOCATION:Somewhere ...
DESCRIPTION:maybe ....
DTSTART;TZID=Europe/Berlin;VALUE=DATE:20160609
DTEND;TZID=Europe/Berlin;VALUE=DATE:20160610
END:VEVENT
END:VCALENDAR
EOD;

	private const CALENDAR_DATA_ONE_TIME = <<<EOD
BEGIN:VCALENDAR
PRODID:-//IDN nextcloud.com//Calendar app 4.3.0-alpha.0//EN
CALSCALE:GREGORIAN
VERSION:2.0
BEGIN:VEVENT
CREATED:20230203T154600Z
DTSTAMP:20230203T154602Z
LAST-MODIFIED:20230203T154602Z
SEQUENCE:2
UID:f6a565b6-f9a8-4d1e-9d01-c8dcbe716b7e
DTSTART;TZID=Europe/Vienna:20230204T090000
DTEND;TZID=Europe/Vienna:20230204T120000
STATUS:CONFIRMED
SUMMARY:TEST
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER;RELATED=START:-PT1H
END:VALARM
END:VEVENT
BEGIN:VTIMEZONE
TZID:Europe/Vienna
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
END:VCALENDAR
EOD;

	private const CALENDAR_DATA_ALL_DAY = <<<EOD
BEGIN:VCALENDAR
PRODID:-//IDN nextcloud.com//Calendar app 4.3.0-alpha.0//EN
CALSCALE:GREGORIAN
VERSION:2.0
BEGIN:VEVENT
CREATED:20230203T113430Z
DTSTAMP:20230203T113432Z
LAST-MODIFIED:20230203T113432Z
SEQUENCE:2
UID:a163a056-ba26-44a2-8080-955f19611a8f
DTSTART;VALUE=DATE:20230204
DTEND;VALUE=DATE:20230205
STATUS:CONFIRMED
SUMMARY:TEST
BEGIN:VALARM
ACTION:EMAIL
TRIGGER;RELATED=START:-PT1H
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;

	private const PAGO_PAGO_VTIMEZONE_ICS = <<<ICS
BEGIN:VCALENDAR
BEGIN:VTIMEZONE
TZID:Pacific/Pago_Pago
BEGIN:STANDARD
TZOFFSETFROM:-1100
TZOFFSETTO:-1100
TZNAME:SST
DTSTART:19700101T000000
END:STANDARD
END:VTIMEZONE
END:VCALENDAR
ICS;

	private ?string $oldTimezone;

	protected function setUp(): void {
		parent::setUp();

		$this->backend = $this->createMock(Backend::class);
		$this->notificationProviderManager = $this->createMock(NotificationProviderManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->caldavBackend = $this->createMock(CalDavBackend::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->principalConnector = $this->createMock(Principal::class);

		$this->caldavBackend->method('getShares')->willReturn([]);

		$this->reminderService = new ReminderService(
			$this->backend,
			$this->notificationProviderManager,
			$this->userManager,
			$this->groupManager,
			$this->caldavBackend,
			$this->timeFactory,
			$this->config,
			$this->logger,
			$this->principalConnector,
		);
	}

	public function testOnCalendarObjectDelete():void {
		$this->backend->expects($this->once())
			->method('cleanRemindersForEvent')
			->with(44);

		$objectData = [
			'id' => '44',
			'component' => 'vevent',
		];

		$this->reminderService->onCalendarObjectDelete($objectData);
	}

	public function testOnCalendarObjectCreateSingleEntry():void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];

		$calls = [
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'de919af7429d3b5c11e8b9d289b411a6', 'EMAIL', true, 1465429500, false],
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', '35b3eae8e792aa2209f0b4e1a302f105', 'DISPLAY', false, 1465344000, false]
		];
		$this->backend->expects($this->exactly(count($calls)))
			->method('insertReminder')
			->willReturnCallback(function () use (&$calls) {
				$expected = array_shift($calls);
				$this->assertEquals($expected, func_get_args());
				return 1;
			});

		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2016-06-08T00:00:00+00:00'));

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	/**
	 * RFC5545 says DTSTART is REQUIRED, but we have seen event without the prop
	 */
	public function testOnCalendarObjectCreateNoDtstart(): void {
		$calendarData = <<<EOD
BEGIN:VCALENDAR
PRODID:-//Nextcloud calendar v1.6.4
BEGIN:VEVENT
CREATED:20160602T133732
DTSTAMP:20160602T133732
LAST-MODIFIED:20160602T133732
UID:wej2z68l9h
SUMMARY:Test Event
BEGIN:VALARM
ACTION:EMAIL
TRIGGER:-PT15M
END:VALARM
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER;VALUE=DATE-TIME:20160608T000000Z
END:VALARM
END:VEVENT
END:VCALENDAR
EOD;
		$objectData = [
			'calendardata' => $calendarData,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];

		$this->backend->expects($this->never())
			->method('insertReminder');

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateSingleEntryWithRepeat(): void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_REPEAT,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];

		$calls = [
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1465429500, false],
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1465429620, true],
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1465429740, true],
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1465429860, true],
			[1337, 42, 'wej2z68l9h', false, 1465430400, false, '5c70531aab15c92b52518ae10a2f78a4', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1465429980, true]
		];
		$this->backend->expects($this->exactly(count($calls)))
			->method('insertReminder')
			->willReturnCallback(function () use (&$calls) {
				$expected = array_shift($calls);
				$this->assertEquals($expected, func_get_args());
				return 1;
			});

		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2016-06-08T00:00:00+00:00'));

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateRecurringEntry(): void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_RECURRING,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];

		$calls = [
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'de919af7429d3b5c11e8b9d289b411a6', 'EMAIL', true, 1467243900, false],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', '8996992118817f9f311ac5cc56d1cc97', 'EMAIL', true, 1467158400, false]
		];
		$this->backend->expects($this->exactly(count($calls)))
			->method('insertReminder')
			->willReturnCallback(function () use (&$calls) {
				$expected = array_shift($calls);
				$this->assertEquals($expected, func_get_args());
				return 1;
			});

		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2016-06-29T00:00:00+00:00'));

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateEmpty():void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_NO_ALARM,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];

		$this->backend->expects($this->never())
			->method('insertReminder');

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateAllDayWithNullTimezone(): void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_ALL_DAY,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];
		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2023-02-03T13:28:00+00:00'));
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => null,
			]);

		// One hour before midnight relative to the server's time
		$expectedReminderTimstamp = (new DateTime('2023-02-03T23:00:00'))->getTimestamp();
		$this->backend->expects(self::once())
			->method('insertReminder')
			->with(1337, 42, self::anything(), false, 1675468800, false, self::anything(), self::anything(), 'EMAIL', true, $expectedReminderTimstamp, false);

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateAllDayWithBlankTimezone(): void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_ALL_DAY,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];
		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2023-02-03T13:28:00+00:00'));
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => '',
			]);

		// One hour before midnight relative to the server's time
		$expectedReminderTimstamp = (new DateTime('2023-02-03T23:00:00'))->getTimestamp();
		$this->backend->expects(self::once())
			->method('insertReminder')
			->with(1337, 42, self::anything(), false, 1675468800, false, self::anything(), self::anything(), 'EMAIL', true, $expectedReminderTimstamp, false);

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateAllDayWithTimezone(): void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_ALL_DAY,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];
		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2023-02-03T13:28:00+00:00'));
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => self::PAGO_PAGO_VTIMEZONE_ICS,
			]);

		// One hour before midnight relative to the timezone
		$expectedReminderTimstamp = (new DateTime('2023-02-03T23:00:00', new DateTimeZone('Pacific/Pago_Pago')))->getTimestamp();
		$this->backend->expects(self::once())
			->method('insertReminder')
			->with(1337, 42, 'a163a056-ba26-44a2-8080-955f19611a8f', false, self::anything(), false, self::anything(), self::anything(), 'EMAIL', true, $expectedReminderTimstamp, false);

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateRecurringEntryWithRepeat():void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_RECURRING_REPEAT,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => null,
			]);

		$calls = [
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467243900, false],
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467244020, true],
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467244140, true],
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467244260, true],
			[1337, 42, 'wej2z68l9h', true, 1467244800, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467244380, true],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', '8996992118817f9f311ac5cc56d1cc97', 'EMAIL', true, 1467158400, false]
		];
		$this->backend->expects($this->exactly(count($calls)))
			->method('insertReminder')
			->willReturnCallback(function () use (&$calls) {
				$expected = array_shift($calls);
				$this->assertEquals($expected, func_get_args());
				return 1;
			});

		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2016-06-29T00:00:00+00:00'));

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testOnCalendarObjectCreateWithEventTimezoneAndCalendarTimezone():void {
		$objectData = [
			'calendardata' => self::CALENDAR_DATA_ONE_TIME,
			'id' => '42',
			'calendarid' => '1337',
			'component' => 'vevent',
		];
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => self::PAGO_PAGO_VTIMEZONE_ICS,
			]);
		$expectedReminderTimstamp = (new DateTime('2023-02-04T08:00:00', new DateTimeZone('Europe/Vienna')))->getTimestamp();
		$this->backend->expects(self::once())
			->method('insertReminder')
			->with(1337, 42, self::anything(), false, self::anything(), false, self::anything(), self::anything(), self::anything(), true, $expectedReminderTimstamp, false)
			->willReturn(1);
		$this->caldavBackend->expects(self::once())
			->method('getCalendarById')
			->with(1337)
			->willReturn([
				'{urn:ietf:params:xml:ns:caldav}calendar-timezone' => null,
			]);
		$this->timeFactory->expects($this->once())
			->method('getDateTime')
			->with()
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2023-02-03T13:28:00+00:00'));
		;

		$this->reminderService->onCalendarObjectCreate($objectData);
	}

	public function testProcessReminders():void {
		$this->backend->expects($this->once())
			->method('getRemindersToProcess')
			->with()
			->willReturn([
				[
					'id' => 1,
					'calendar_id' => 1337,
					'object_id' => 42,
					'uid' => 'wej2z68l9h',
					'is_recurring' => false,
					'recurrence_id' => 1465430400,
					'is_recurrence_exception' => false,
					'event_hash' => '5c70531aab15c92b52518ae10a2f78a4',
					'alarm_hash' => 'de919af7429d3b5c11e8b9d289b411a6',
					'type' => 'EMAIL',
					'is_relative' => true,
					'notification_date' => 1465429500,
					'is_repeat_based' => false,
					'calendardata' => self::CALENDAR_DATA,
					'displayname' => 'Displayname 123',
					'principaluri' => 'principals/users/user001',
				],
				[
					'id' => 2,
					'calendar_id' => 1337,
					'object_id' => 42,
					'uid' => 'wej2z68l9h',
					'is_recurring' => false,
					'recurrence_id' => 1465430400,
					'is_recurrence_exception' => false,
					'event_hash' => '5c70531aab15c92b52518ae10a2f78a4',
					'alarm_hash' => 'ecacbf07d413c3c78d1ac7ad8c469602',
					'type' => 'EMAIL',
					'is_relative' => true,
					'notification_date' => 1465429740,
					'is_repeat_based' => true,
					'calendardata' => self::CALENDAR_DATA_REPEAT,
					'displayname' => 'Displayname 123',
					'principaluri' => 'principals/users/user001',
				],
				[
					'id' => 3,
					'calendar_id' => 1337,
					'object_id' => 42,
					'uid' => 'wej2z68l9h',
					'is_recurring' => false,
					'recurrence_id' => 1465430400,
					'is_recurrence_exception' => false,
					'event_hash' => '5c70531aab15c92b52518ae10a2f78a4',
					'alarm_hash' => '35b3eae8e792aa2209f0b4e1a302f105',
					'type' => 'DISPLAY',
					'is_relative' => false,
					'notification_date' => 1465344000,
					'is_repeat_based' => false,
					'calendardata' => self::CALENDAR_DATA,
					'displayname' => 'Displayname 123',
					'principaluri' => 'principals/users/user001',
				],
				[
					'id' => 4,
					'calendar_id' => 1337,
					'object_id' => 42,
					'uid' => 'wej2z68l9h',
					'is_recurring' => true,
					'recurrence_id' => 1467244800,
					'is_recurrence_exception' => false,
					'event_hash' => 'fbdb2726bc0f7dfacac1d881c1453e20',
					'alarm_hash' => 'ecacbf07d413c3c78d1ac7ad8c469602',
					'type' => 'EMAIL',
					'is_relative' => true,
					'notification_date' => 1467243900,
					'is_repeat_based' => false,
					'calendardata' => self::CALENDAR_DATA_RECURRING_REPEAT,
					'displayname' => 'Displayname 123',
					'principaluri' => 'principals/users/user001',
				],
				[
					'id' => 5,
					'calendar_id' => 1337,
					'object_id' => 42,
					'uid' => 'wej2z68l9h',
					'is_recurring' => true,
					'recurrence_id' => 1467849600,
					'is_recurrence_exception' => false,
					'event_hash' => 'fbdb2726bc0f7dfacac1d881c1453e20',
					'alarm_hash' => '8996992118817f9f311ac5cc56d1cc97',
					'type' => 'EMAIL',
					'is_relative' => true,
					'notification_date' => 1467158400,
					'is_repeat_based' => false,
					'calendardata' => self::CALENDAR_DATA_RECURRING,
					'displayname' => 'Displayname 123',
					'principaluri' => 'principals/users/user001',
				]
			]);

		$this->notificationProviderManager->expects($this->exactly(5))
			->method('hasProvider')
			->willReturnMap([
				['EMAIL', true],
				['DISPLAY', true],
			]);

		$provider1 = $this->createMock(INotificationProvider::class);
		$provider2 = $this->createMock(INotificationProvider::class);
		$provider3 = $this->createMock(INotificationProvider::class);
		$provider4 = $this->createMock(INotificationProvider::class);
		$provider5 = $this->createMock(INotificationProvider::class);

		$getProviderCalls = [
			['EMAIL', $provider1],
			['EMAIL', $provider2],
			['DISPLAY', $provider3],
			['EMAIL', $provider4],
			['EMAIL', $provider5],
		];
		$this->notificationProviderManager->expects($this->exactly(count($getProviderCalls)))
			->method('getProvider')
			->willReturnCallback(function () use (&$getProviderCalls) {
				$expected = array_shift($getProviderCalls);
				$return = array_pop($expected);
				$this->assertEquals($expected, func_get_args());
				return $return;
			});

		$user = $this->createMock(IUser::class);
		$this->userManager->expects($this->exactly(5))
			->method('get')
			->with('user001')
			->willReturn($user);

		$provider1->expects($this->once())
			->method('send')
			->with($this->callback(function ($vevent) {
				if ($vevent->DTSTART->getDateTime()->format(DateTime::ATOM) !== '2016-06-09T00:00:00+00:00') {
					return false;
				}
				return true;
			}, 'Displayname 123', $user));
		$provider2->expects($this->once())
			->method('send')
			->with($this->callback(function ($vevent) {
				if ($vevent->DTSTART->getDateTime()->format(DateTime::ATOM) !== '2016-06-09T00:00:00+00:00') {
					return false;
				}
				return true;
			}, 'Displayname 123', $user));
		$provider3->expects($this->once())
			->method('send')
			->with($this->callback(function ($vevent) {
				if ($vevent->DTSTART->getDateTime()->format(DateTime::ATOM) !== '2016-06-09T00:00:00+00:00') {
					return false;
				}
				return true;
			}, 'Displayname 123', $user));
		$provider4->expects($this->once())
			->method('send')
			->with($this->callback(function ($vevent) {
				if ($vevent->DTSTART->getDateTime()->format(DateTime::ATOM) !== '2016-06-30T00:00:00+00:00') {
					return false;
				}
				return true;
			}, 'Displayname 123', $user));
		$provider5->expects($this->once())
			->method('send')
			->with($this->callback(function ($vevent) {
				if ($vevent->DTSTART->getDateTime()->format(DateTime::ATOM) !== '2016-07-07T00:00:00+00:00') {
					return false;
				}
				return true;
			}, 'Displayname 123', $user));

		$removeReminderCalls = [
			[1],
			[2],
			[3],
			[4],
			[5],
		];
		$this->backend->expects($this->exactly(5))
			->method('removeReminder')
			->willReturnCallback(function () use (&$removeReminderCalls): void {
				$expected = array_shift($removeReminderCalls);
				$this->assertEquals($expected, func_get_args());
			});


		$insertReminderCalls = [
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467848700, false],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467848820, true],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467848940, true],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467849060, true],
			[1337, 42, 'wej2z68l9h', true, 1467849600, false, 'fbdb2726bc0f7dfacac1d881c1453e20', 'ecacbf07d413c3c78d1ac7ad8c469602', 'EMAIL', true, 1467849180, true],
			[1337, 42, 'wej2z68l9h', true, 1468454400, false, 'fbdb2726bc0f7dfacac1d881c1453e20', '8996992118817f9f311ac5cc56d1cc97', 'EMAIL', true, 1467763200, false],
		];
		$this->backend->expects($this->exactly(count($insertReminderCalls)))
			->method('insertReminder')
			->willReturnCallback(function () use (&$insertReminderCalls) {
				$expected = array_shift($insertReminderCalls);
				$this->assertEquals($expected, func_get_args());
				return 99;
			});

		$this->timeFactory->method('getDateTime')
			->willReturn(DateTime::createFromFormat(DateTime::ATOM, '2016-06-08T00:00:00+00:00'));

		$this->reminderService->processReminders();
	}
}
