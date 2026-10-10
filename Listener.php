<?php namespace Hampel\ApiUsers;

class Listener
{
	/**
	 * Declares this add-on's secrets to the Admin API, which defines the
	 * hampel_admin_api_redaction event: there are none. It has no options and reads no
	 * config.php key of its own.
	 *
	 * Not a dependency on the Admin API: an event no installed add-on defines is never fired.
	 *
	 * @param array $declarations
	 */
	public static function adminApiRedaction(array &$declarations)
	{
		$declarations['Hampel/ApiUsers'] = [];
	}
}
