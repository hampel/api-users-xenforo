<?php namespace Tests\Feature;

use Tests\TestCase;

class ClassExtensionsTest extends TestCase
{
	/**
	 * Every extension is registered against its XenForo 2.2 class name, by hand for the finder,
	 * the merge service and the controller, and each must still resolve on 2.3 through core's
	 * aliases. validate checks four of them; this checks all five, the controller included.
	 */
	public function test_every_extension_is_registered_against_the_2_2_name_and_resolves(): void
	{
		$resolved = $this->assertAddOnClassExtensionsApply();

		$this->assertEqualsCanonicalizing([
			'XF\Admin\Controller\User',
			'XF\Entity\User',
			'XF\Entity\UserAuth',
			'XF\Finder\User',
			'XF\Service\User\Merge',
		], array_keys($resolved));
	}
}
