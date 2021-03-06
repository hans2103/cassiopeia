<?php
/**
 * @package     Joomla.Tests
 * @subpackage  Api.tests
 *
 * @copyright   Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

use Codeception\Util\HttpCode;

/**
 * Class ContentCest.
 *
 * Basic com_content (article) tests.
 *
 * @since   4.0.0
 */
class ContentCest
{
	/**
	 * Api test before running.
	 *
	 * @param   mixed   ApiTester  $I  Api tester
	 *
	 * @return void
	 *
	 * @since   4.0.0
	 */
	public function _before(ApiTester $I)
	{
		// TODO: Improve this to retrieve a specific ID to replace with a known ID
		$desiredUserId = 3;
		$I->updateInDatabase('users', ['id' => 3], []);
		$I->updateInDatabase('user_usergroup_map', ['user_id' => 3], []);
		$enabledData = ['user_id' => $desiredUserId, 'profile_key' => 'joomlatoken.enabled', 'profile_value' => 1];
		$tokenData = ['user_id' => $desiredUserId, 'profile_key' => 'joomlatoken.token', 'profile_value' => 'dOi2m1NRrnBHlhaWK/WWxh3B5tqq1INbdf4DhUmYTI4='];
		$I->haveInDatabase('user_profiles', $enabledData);
		$I->haveInDatabase('user_profiles', $tokenData);
	}

	/**
	 * Api test after running.
	 *
	 * @param   mixed   ApiTester  $I  Api tester
	 *
	 * @return void
	 *
	 * @since   4.0.0
	 */
	public function _after(ApiTester $I)
	{
	}

	/**
	 * Test the crud endpoints of com_content from the API.
	 *
	 * @param   mixed   ApiTester  $I  Api tester
	 *
	 * @return void
	 *
	 * @since   4.0.0
	 *
	 * @TODO: Make these separate tests but requires sample data being installed so there are existing articles
	 */
	public function testCrudOnArticle(ApiTester $I)
	{
		$I->amBearerAuthenticated('c2hhMjU2OjM6ZTJmMjJlYTNlNTU0NmM1MDJhYTIzYzMwN2MxYzAwZTQ5NzJhMWRmOTUyNjY5MTk2YjE5ODJmZWMwZTcxNzgwMQ==');
		$I->haveHttpHeader('Content-Type', 'application/json');
		$I->haveHttpHeader('Accept', 'application/vnd.api+json');

		$testarticle = [
			'title' => 'Just for you',
			'catid' => 2,
			'articletext' => 'A dummy article to save to the database',
			'language' => '*',
			'alias' => 'tobias'
		];

		$I->sendPOST('/content/article', $testarticle);

		$I->seeResponseCodeIs(HttpCode::OK);

		$I->amBearerAuthenticated('c2hhMjU2OjM6ZTJmMjJlYTNlNTU0NmM1MDJhYTIzYzMwN2MxYzAwZTQ5NzJhMWRmOTUyNjY5MTk2YjE5ODJmZWMwZTcxNzgwMQ==');
		$I->haveHttpHeader('Accept', 'application/vnd.api+json');
		$I->sendGET('/content/article/1');
		$I->seeResponseCodeIs(HttpCode::OK);

		$I->amBearerAuthenticated('c2hhMjU2OjM6ZTJmMjJlYTNlNTU0NmM1MDJhYTIzYzMwN2MxYzAwZTQ5NzJhMWRmOTUyNjY5MTk2YjE5ODJmZWMwZTcxNzgwMQ==');
		$I->haveHttpHeader('Content-Type', 'application/json');
		$I->haveHttpHeader('Accept', 'application/vnd.api+json');
		$I->sendGET('/content/article/1', ['title' => 'Another Title']);
		$I->seeResponseCodeIs(HttpCode::OK);

		$I->amBearerAuthenticated('c2hhMjU2OjM6ZTJmMjJlYTNlNTU0NmM1MDJhYTIzYzMwN2MxYzAwZTQ5NzJhMWRmOTUyNjY5MTk2YjE5ODJmZWMwZTcxNzgwMQ==');
		$I->haveHttpHeader('Accept', 'application/vnd.api+json');
		$I->sendDELETE('/content/article/1');
		$I->seeResponseCodeIs(HttpCode::NO_CONTENT);
	}
}
