<?php namespace Hampel\ApiUsers\Cli\Command;

use Hampel\ApiUsers\Authentication\ApiOnly;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListUsers extends Command
{
	/**
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('api-users:list')
			->setDescription('List the API users on this forum');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var \Hampel\ApiUsers\Repository\ApiUser $repo */
		$repo = \XF::repository('Hampel\ApiUsers:ApiUser');

		$apiUsers = $repo->findApiUsersForList()->fetch();
		if (!$apiUsers->count())
		{
			$output->writeln('There are no API users.');
			return 0;
		}

		$keys = $repo->countApiKeysByUser($apiUsers->keys());

		$rows = [];
		foreach ($apiUsers AS $userId => $apiUser)
		{
			$user = $apiUser->User;

			$rows[] = [
				$userId,
				$user ? $user->username : '(account deleted)',
				$user && $user->is_admin ? 'yes' : '',
				$keys[$userId] ?? 0,
				date('Y-m-d', $apiUser->created_date),
				$apiUser->CreatedBy ? $apiUser->CreatedBy->username : ($apiUser->created_by_user_id ? '(deleted)' : 'command line'),
				$user && $user->Auth && $user->Auth->scheme_class === ApiOnly::NAME ? '' : 'NOT API-ONLY',
				$apiUser->note,
			];
		}

		$table = new Table($output);
		$table->setHeaders(['ID', 'Username', 'Admin', 'Keys', 'Created', 'By', 'State', 'Note']);
		$table->setRows($rows);
		$table->render();

		return 0;
	}
}
