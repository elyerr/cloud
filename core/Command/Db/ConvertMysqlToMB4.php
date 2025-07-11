<?php

/**
 * SPDX-FileCopyrightText: 2017 ownCloud GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OC\Core\Command\Db;

use OC\DB\MySqlTools;
use OC\Migration\ConsoleOutput;
use OC\Repair\Collation;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ConvertMysqlToMB4 extends Command {
	public function __construct(
		private IConfig $config,
		private IDBConnection $connection,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this
			->setName('db:convert-mysql-charset')
			->setDescription('Convert charset of MySQL/MariaDB to use utf8mb4');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($this->connection->getDatabaseProvider() !== IDBConnection::PLATFORM_MYSQL) {
			$output->writeln('This command is only valid for MySQL/MariaDB databases.');
			return 1;
		}

		$tools = new MySqlTools();
		if (!$tools->supports4ByteCharset($this->connection)) {
			$url = $this->urlGenerator->linkToDocs('admin-mysql-utf8mb4');
			$output->writeln('The database is not properly setup to use the charset utf8mb4.');
			$output->writeln("For more information please read the documentation at $url");
			return 1;
		}

		// enable charset
		$this->config->setSystemValue('mysql.utf8mb4', true);

		// run conversion
		$coll = new Collation($this->config, $this->logger, $this->connection, false);
		$coll->run(new ConsoleOutput($output));

		return 0;
	}
}
