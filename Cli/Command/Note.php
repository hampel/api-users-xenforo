<?php namespace Hampel\ApiUsers\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Changes an API user's note from the command line, through the same service as the control
 * panel's edit page.
 */
class Note extends Command
{
	/**
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('api-users:note')
			->setDescription("Change an API user's note")
			->addArgument('username', InputArgument::REQUIRED, 'The API user')
			->addArgument('note', InputArgument::REQUIRED, 'What the account is for; "" clears it');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$username = $input->getArgument('username');
		$username = is_string($username) ? $username : '';

		/** @var \XF\Entity\User|null $user */
		$user = \XF::finder('XF:User')->where('username', $username)->with('ApiUser')->fetchOne();
		if (!$user)
		{
			$output->writeln('<error>' . \XF::phrase('requested_user_not_found') . '</error>');
			return 1;
		}

		$apiUser = $user->ApiUser;
		if (!$apiUser)
		{
			$output->writeln(sprintf('<error>%s is not an API user.</error>', $user->username));
			return 1;
		}

		/** @var \Hampel\ApiUsers\Service\ApiUser\NoteEditor $editor */
		$editor = \XF::service('Hampel\ApiUsers:ApiUser\NoteEditor', $apiUser);
		$note = $input->getArgument('note');
		$editor->setNote(is_string($note) ? $note : '');

		if (!$editor->validate($errors))
		{
			foreach ($errors AS $error)
			{
				$output->writeln('<error>' . $error . '</error>');
			}

			return 1;
		}

		$editor->save();

		$apiUser = $editor->getApiUser();
		$output->writeln($apiUser->note === ''
			? sprintf('Cleared the note on <info>%s</info>.', $user->username)
			: sprintf('The note on <info>%s</info> is now: %s', $user->username, $apiUser->note)
		);

		return 0;
	}
}
