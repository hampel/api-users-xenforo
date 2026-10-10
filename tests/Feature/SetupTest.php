<?php namespace Tests\Feature;

use Hampel\ApiUsers\Setup;
use Hampel\Testing\Concerns\UsesDatabaseTransactions;
use Tests\TestCase;

class SetupTest extends TestCase
{
	use UsesDatabaseTransactions;

	/**
	 * Only the first uninstall step is run here: the second drops the table, and DDL commits the
	 * transaction this suite relies on. That one is proved by uninstalling in a sandbox.
	 */
	public function test_uninstall_moves_api_users_to_a_scheme_that_survives_this_add_on()
	{
		$user = $this->createApiUser();
		$member = $this->createMember();
		$memberScheme = $this->schemeOf($member->user_id);

		$setup = new Setup($this->app()->addOnManager()->getById('Hampel/ApiUsers'), $this->app());
		$setup->uninstallStep1();

		$this->assertSame('XF:NoPassword', $this->schemeOf($user->user_id));
		$this->assertSame($memberScheme, $this->schemeOf($member->user_id));

		// and what is left behind is an account core itself will not log in
		$auth = $this->app()->em()->find('XF:UserAuth', $user->user_id);
		$this->assertFalse($auth->authenticate('anything'));
	}
}
