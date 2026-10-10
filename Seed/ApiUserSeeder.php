<?php namespace Hampel\ApiUsers\Seed;

use Hampel\ApiUsers\Factory\ApiUserFactory;
use Hampel\Seeder\Factory\ApiKeyFactory;
use Hampel\Seeder\Seed\AbstractSeeder;

/**
 * API users in each state the list page shows: with a key and without, administrators and not.
 *
 * Development only: needs the Hampel/Seeder add-on, which is not a dependency of this one, and is
 * left out of a release.
 *
 *     php cmd.php seeder:run Hampel/ApiUsers:ApiUser -p users=30
 *
 * Parameters:
 *
 *     users      how many API users to make (10)
 *     admins     how many of them are administrators, holding no permissions (2)
 *     keyless    how many are left with no API key, as a new one is (2)
 *
 * Which accounts are the administrators and which have no key is decided by position in the run,
 * not at random: the first are the administrators and the last have no key. Every other account
 * gets one key naming it, and every third of those a second, inactive one.
 *
 * The run prints each key's value. They are working credentials on the forum they were made on.
 */
class ApiUserSeeder extends AbstractSeeder
{
	public function run()
	{
		$users = $this->count('users', 10);
		$admins = min($users, $this->count('admins', 2));
		$keyless = min($users, $this->count('keyless', 2));

		for ($i = 0; $i < $users; $i++)
		{
			$factory = ApiUserFactory::new();
			if ($i < $admins)
			{
				$factory = $factory->administrator();
			}

			$user = $factory->create();

			if ($i >= $users - $keyless)
			{
				continue;
			}

			ApiKeyFactory::new()->for($user)->used()->create();
			if ($i % 3 == 2)
			{
				ApiKeyFactory::new()->for($user)->inactive()->create();
			}
		}
	}
}
