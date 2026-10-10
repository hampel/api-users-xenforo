<?php namespace Hampel\ApiUsers\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Creates an API user from the command line - the same account the control panel's form makes,
 * through the same service.
 *
 * Shell access is the authorisation: there is no acting administrator here, so nothing is asked
 * for and the account is recorded as created by nobody.
 */
class Create extends Command
{
	protected function configure()
	{
		$this
			->setName('api-users:create')
			->setDescription('Create an API user: an account with no password or email address, restricted to API access')
			->addArgument('username', InputArgument::REQUIRED, 'Name for the new account')
			->addOption('admin', null, InputOption::VALUE_NONE,
				'Also make it an administrator, holding no admin permissions')
			->addOption('note', null, InputOption::VALUE_REQUIRED,
				'What the account is for', '');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		/** @var \Hampel\ApiUsers\Service\ApiUser\Creator $creator */
		$creator = \XF::service('Hampel\ApiUsers:ApiUser\Creator', $input->getArgument('username'));
		$creator->setNote($input->getOption('note'));
		$creator->setAdmin($input->getOption('admin'));

		if (!$creator->validate($errors))
		{
			foreach ($errors AS $error)
			{
				$output->writeln('<error>' . $error . '</error>');
			}

			return 1;
		}

		$apiUser = $creator->save();
		$user = $creator->getUser();

		$output->writeln(sprintf(
			'Created API user <info>%s</info> (user_id %d)%s.',
			$user->username,
			$apiUser->user_id,
			$input->getOption('admin') ? ', an administrator with no admin permissions' : ''
		));
		$output->writeln('It can do nothing until an API key names it: create one under Setup > API keys.');

		return 0;
	}
}
