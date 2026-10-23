<?php namespace Tests\Unit;

use Hampel\ApiUsers\Authentication\ApiOnly;
use Tests\TestCase;

class ApiOnlyTest extends TestCase
{
	public function test_nothing_authenticates_against_it(): void
	{
		$auth = new ApiOnly();

		$this->assertFalse($auth->authenticate(1, ''));
		$this->assertFalse($auth->authenticate(1, 'anything'));
		$this->assertSame([], $auth->generate('anything'));
	}

	/**
	 * Core reads false as "give this account a password": saving an administrator record resets
	 * one. True is what keeps core from trying.
	 */
	public function test_it_reports_a_password_so_core_does_not_try_to_supply_one(): void
	{
		$this->assertTrue((new ApiOnly())->hasPassword());
	}

	/** An upgradable scheme is rewritten to the default one by a successful login. */
	public function test_it_is_never_upgraded_to_another_scheme(): void
	{
		$this->assertFalse((new ApiOnly())->isUpgradable());
	}

	public function test_xenforo_resolves_the_stored_name_back_to_this_class(): void
	{
		$this->assertInstanceOf(ApiOnly::class, $this->app()->auth(ApiOnly::NAME));
		$this->assertSame(ApiOnly::NAME, (new ApiOnly())->getAuthenticationName());
	}

	/** UserAuth::getAuthenticationHandler() rewrites an underscore to a backslash. */
	public function test_the_stored_name_survives_the_xenforo_1_fallback(): void
	{
		$this->assertStringNotContainsString('_', ApiOnly::NAME);
	}
}
