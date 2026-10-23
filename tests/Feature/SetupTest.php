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
	public function test_uninstall_moves_api_users_to_a_scheme_that_survives_this_add_on(): void
	{
		$user = $this->createApiUser();
		$member = $this->createMember();
		$memberScheme = $this->schemeOf($this->idOf($member));

		$addOn = $this->app()->addOnManager()->getById('Hampel/ApiUsers');
		$this->assertNotNull($addOn);
		$setup = new Setup($addOn, $this->app());
		$setup->uninstallStep1();

		$this->assertSame('XF:NoPassword', $this->schemeOf($this->idOf($user)));
		$this->assertSame($memberScheme, $this->schemeOf($this->idOf($member)));

		// and what is left behind is an account core itself will not log in
		$auth = $this->authOf($user);
		$this->assertFalse($auth->authenticate('anything'));
	}
}
