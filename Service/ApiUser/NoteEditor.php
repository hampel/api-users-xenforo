<?php namespace Hampel\ApiUsers\Service\ApiUser;

use Hampel\ApiUsers\Entity\ApiUser;
use XF\Service\AbstractService;
use XF\Service\ValidateAndSavableTrait;

/**
 * Changes what an API user's note says. The one place it is changed after the account is made, so
 * the command line and the control panel cannot disagree about what a note may hold.
 */
class NoteEditor extends AbstractService
{
	use ValidateAndSavableTrait;

	/** @var ApiUser */
	protected $apiUser;

	/** @var string */
	protected $note = '';

	public function __construct(\XF\App $app, ApiUser $apiUser)
	{
		parent::__construct($app);

		$this->apiUser = $apiUser;
		$this->note = $apiUser->note;
	}

	/**
	 * @param string $note an empty string clears it
	 * @return void
	 */
	public function setNote($note)
	{
		$this->note = trim((string) $note);
	}

	/**
	 * @return ApiUser
	 */
	public function getApiUser()
	{
		return $this->apiUser;
	}

	/**
	 * @return array<int|string, \XF\Phrase|string> errors, keyed by field
	 */
	protected function _validate()
	{
		if (mb_strlen($this->note) > 255)
		{
			return ['note' => \XF::phrase('hampel_apiusers_note_is_too_long')];
		}

		return [];
	}

	/**
	 * @return ApiUser
	 */
	protected function _save()
	{
		$this->apiUser->note = $this->note;
		$this->apiUser->save();

		return $this->apiUser;
	}
}
