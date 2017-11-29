<?php
/**
 * @file include/api.php
 * Friendica implementation of statusnet/twitter API
 *
 * @todo Automatically detect if incoming data is HTML or BBCode
 */
use Friendica\App;
use Friendica\Core\System;
use Friendica\Core\Config;
use Friendica\Core\NotificationsManager;
use Friendica\Core\Worker;
use Friendica\Database\DBM;
use Friendica\Model\User;
use Friendica\Network\HTTPException;
use Friendica\Network\HTTPException\BadRequestException;
use Friendica\Network\HTTPException\ForbiddenException;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Network\HTTPException\MethodNotAllowedException;
use Friendica\Network\HTTPException\NotFoundException;
use Friendica\Network\HTTPException\NotImplementedException;
use Friendica\Network\HTTPException\UnauthorizedException;
use Friendica\Network\HTTPException\TooManyRequestsException;
use Friendica\Object\Contact;
use Friendica\Object\Photo;
use Friendica\Protocol\Diaspora;
use Friendica\Util\XML;

require_once 'include/bbcode.php';
require_once 'include/datetime.php';
require_once 'include/conversation.php';
require_once 'include/oauth.php';
require_once 'include/html2plain.php';
require_once 'mod/share.php';
require_once 'mod/item.php';
require_once 'include/security.php';
require_once 'include/contact_selectors.php';
require_once 'include/html2bbcode.php';
require_once 'mod/wall_upload.php';
require_once 'mod/proxy.php';
require_once 'include/message.php';
require_once 'include/group.php';
require_once 'include/like.php';
require_once 'include/plaintext.php';

define('API_METHOD_ANY', '*');
define('API_METHOD_GET', 'GET');
define('API_METHOD_POST', 'POST,PUT');
define('API_METHOD_DELETE', 'POST,DELETE');

$API = array();
$called_api = null;

/**
 * @brief Auth API user
 *
 * It is not sufficient to use local_user() to check whether someone is allowed to use the API,
 * because this will open CSRF holes (just embed an image with src=friendicasite.com/api/statuses/update?status=CSRF
 * into a page, and visitors will post something without noticing it).
 */
function api_user()
{
	if (x($_SESSION, 'allow_api')) {
		return local_user();
	}

	return false;
}

/**
 * @brief Get source name from API client
 *
 * Clients can send 'source' parameter to be show in post metadata
 * as "sent via <source>".
 * Some clients doesn't send a source param, we support ones we know
 * (only Twidere, atm)
 *
 * @return string
 * 		Client source name, default to "api" if unset/unknown
 */
function api_source()
{
	if (requestdata('source')) {
		return requestdata('source');
	}

	// Support for known clients that doesn't send a source name
	if (strpos($_SERVER['HTTP_USER_AGENT'], "Twidere") !== false) {
		return "Twidere";
	}

	logger("Unrecognized user-agent ".$_SERVER['HTTP_USER_AGENT'], LOGGER_DEBUG);

	return "api";
}

/**
 * @brief Format date for API
 *
 * @param string $str Source date, as UTC
 * @return string Date in UTC formatted as "D M d H:i:s +0000 Y"
 */
function api_date($str)
{
	// Wed May 23 06:01:13 +0000 2007
	return datetime_convert('UTC', 'UTC', $str, "D M d H:i:s +0000 Y");
}

/**
 * @brief Register API endpoint
 *
 * Register a function to be the endpont for defined API path.
 *
 * @param string $path   API URL path, relative to System::baseUrl()
 * @param string $func   Function name to call on path request
 * @param bool   $auth   API need logged user
 * @param string $method HTTP method reqiured to call this endpoint.
 *                       One of API_METHOD_ANY, API_METHOD_GET, API_METHOD_POST.
 *                       Default to API_METHOD_ANY
 */
function api_register_func($path, $func, $auth = false, $method = API_METHOD_ANY)
{
	global $API;

	$API[$path] = array(
		'func'   => $func,
		'auth'   => $auth,
		'method' => $method,
	);

	// Workaround for hotot
	$path = str_replace("api/", "api/1.1/", $path);

	$API[$path] = array(
		'func'   => $func,
		'auth'   => $auth,
		'method' => $method,
	);
}

/**
 * @brief Login API user
 *
 * Log in user via OAuth1 or Simple HTTP Auth.
 * Simple Auth allow username in form of <pre>user@server</pre>, ignoring server part
 *
 * @param object $a App
 * @hook 'authenticate'
 * 		array $addon_auth
 *			'username' => username from login form
 *			'password' => password from login form
 *			'authenticated' => return status,
 *			'user_record' => return authenticated user record
 * @hook 'logged_in'
 * 		array $user	logged user record
 */
function api_login(App $a)
{
	// login with oauth
	try {
		$oauth = new FKOAuth1();
		list($consumer,$token) = $oauth->verify_request(OAuthRequest::from_request());
		if (!is_null($token)) {
			$oauth->loginUser($token->uid);
			call_hooks('logged_in', $a->user);
			return;
		}
		echo __FILE__.__LINE__.__FUNCTION__ . "<pre>";
		var_dump($consumer, $token);
		die();
	} catch (Exception $e) {
		logger($e);
	}

	// workaround for HTTP-auth in CGI mode
	if (x($_SERVER, 'REDIRECT_REMOTE_USER')) {
		$userpass = base64_decode(substr($_SERVER["REDIRECT_REMOTE_USER"], 6)) ;
		if (strlen($userpass)) {
			list($name, $password) = explode(':', $userpass);
			$_SERVER['PHP_AUTH_USER'] = $name;
			$_SERVER['PHP_AUTH_PW'] = $password;
		}
	}

	if (!x($_SERVER, 'PHP_AUTH_USER')) {
		logger('API_login: ' . print_r($_SERVER,true), LOGGER_DEBUG);
		header('WWW-Authenticate: Basic realm="Friendica"');
		throw new UnauthorizedException("This API requires login");
	}

	$user = $_SERVER['PHP_AUTH_USER'];
	$password = $_SERVER['PHP_AUTH_PW'];

	// allow "user@server" login (but ignore 'server' part)
	$at = strstr($user, "@", true);
	if ($at) {
		$user = $at;
	}

	// next code from mod/auth.php. needs better solution
	$record = null;

	$addon_auth = array(
		'username' => trim($user),
		'password' => trim($password),
		'authenticated' => 0,
		'user_record' => null,
	);

	/*
		* A plugin indicates successful login by setting 'authenticated' to non-zero value and returning a user record
		* Plugins should never set 'authenticated' except to indicate success - as hooks may be chained
		* and later plugins should not interfere with an earlier one that succeeded.
		*/
	call_hooks('authenticate', $addon_auth);

	if (($addon_auth['authenticated']) && (count($addon_auth['user_record']))) {
		$record = $addon_auth['user_record'];
	} else {
		$user_id = User::authenticate(trim($user), trim($password));
		if ($user_id) {
			$record = dba::select('user', [], ['uid' => $user_id], ['limit' => 1]);
		}
	}

	if ((! $record) || (! count($record))) {
		logger('API_login failure: ' . print_r($_SERVER, true), LOGGER_DEBUG);
		header('WWW-Authenticate: Basic realm="Friendica"');
		//header('HTTP/1.0 401 Unauthorized');
		//die('This api requires login');
		throw new UnauthorizedException("This API requires login");
	}

	authenticate_success($record);

	$_SESSION["allow_api"] = true;

	call_hooks('logged_in', $a->user);
}

/**
 * @brief Check HTTP method of called API
 *
 * API endpoints can define which HTTP method to accept when called.
 * This function check the current HTTP method agains endpoint
 * registered method.
 *
 * @param string $method Required methods, uppercase, separated by comma
 * @return bool
 */
function api_check_method($method)
{
	if ($method == "*") {
		return true;
	}
	return (strpos($method, $_SERVER['REQUEST_METHOD']) !== false);
}

/**
 * @brief Main API entry point
 *
 * Authenticate user, call registered API function, set HTTP headers
 *
 * @param object $a App
 * @return string API call result
 */
function api_call(App $a)
{
	global $API, $called_api;

	$type = "json";
	if (strpos($a->query_string, ".xml") > 0) {
		$type = "xml";
	}
	if (strpos($a->query_string, ".json") > 0) {
		$type = "json";
	}
	if (strpos($a->query_string, ".rss") > 0) {
		$type = "rss";
	}
	if (strpos($a->query_string, ".atom") > 0) {
		$type = "atom";
	}

	try {
		foreach ($API as $p => $info) {
			if (strpos($a->query_string, $p) === 0) {
				if (!api_check_method($info['method'])) {
					throw new MethodNotAllowedException();
				}

				$called_api = explode("/", $p);
				//unset($_SERVER['PHP_AUTH_USER']);

				/// @TODO should be "true ==[=] $info['auth']", if you miss only one = character, you assign a variable (only with ==). Let's make all this even.
				if ($info['auth'] === true && api_user() === false) {
					api_login($a);
				}

				logger('API call for ' . $a->user['username'] . ': ' . $a->query_string);
				logger('API parameters: ' . print_r($_REQUEST, true));

				$stamp =  microtime(true);
				$r = call_user_func($info['func'], $type);
				$duration = (float) (microtime(true) - $stamp);
				logger("API call duration: " . round($duration, 2) . "\t" . $a->query_string, LOGGER_DEBUG);

				if (Config::get("system", "profiler")) {
					$duration = microtime(true)-$a->performance["start"];

					/// @TODO round() really everywhere?
					logger(
						parse_url($a->query_string, PHP_URL_PATH) . ": " . sprintf(
							"Database: %s/%s, Network: %s, I/O: %s, Other: %s, Total: %s",
							round($a->performance["database"] - $a->performance["database_write"], 3),
							round($a->performance["database_write"], 3),
							round($a->performance["network"], 2),
							round($a->performance["file"], 2),
							round($duration - ($a->performance["database"] + $a->performance["network"]	+ $a->performance["file"]), 2),
							round($duration, 2)
						),
						LOGGER_DEBUG
					);

					if (Config::get("rendertime", "callstack")) {
						$o = "Database Read:\n";
						foreach ($a->callstack["database"] as $func => $time) {
							$time = round($time, 3);
							if ($time > 0) {
								$o .= $func . ": " . $time . "\n";
							}
						}
						$o .= "\nDatabase Write:\n";
						foreach ($a->callstack["database_write"] as $func => $time) {
							$time = round($time, 3);
							if ($time > 0) {
								$o .= $func . ": " . $time . "\n";
							}
						}

						$o .= "\nNetwork:\n";
						foreach ($a->callstack["network"] as $func => $time) {
							$time = round($time, 3);
							if ($time > 0) {
								$o .= $func . ": " . $time . "\n";
							}
						}
						logger($o, LOGGER_DEBUG);
					}
				}

				if (false === $r) {
					/*
						* api function returned false withour throw an
						* exception. This should not happend, throw a 500
						*/
					throw new InternalServerErrorException();
				}

				switch ($type) {
					case "xml":
						header("Content-Type: text/xml");
						return $r;
						break;
					case "json":
						header("Content-Type: application/json");
						foreach ($r as $rr)
							$json = json_encode($rr);
							if (x($_GET, 'callback')) {
								$json = $_GET['callback'] . "(" . $json . ")";
							}
							return $json;
						break;
					case "rss":
						header("Content-Type: application/rss+xml");
						return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $r;
						break;
					case "atom":
						header("Content-Type: application/atom+xml");
						return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $r;
						break;
				}
			}
		}

		logger('API call not implemented: ' . $a->query_string);
		throw new NotImplementedException();
	} catch (HTTPException $e) {
		header("HTTP/1.1 {$e->httpcode} {$e->httpdesc}");
		return api_error($type, $e);
	}
}

/**
 * @brief Format API error string
 *
 * @param string $type Return type (xml, json, rss, as)
 * @param object $e    HTTPException Error object
 * @return strin error message formatted as $type
 */
function api_error($type, $e)
{
	$a = get_app();

	$error = ($e->getMessage() !== "" ? $e->getMessage() : $e->httpdesc);
	/// @TODO:  https://dev.twitter.com/overview/api/response-codes

	$error = array("error" => $error,
			"code" => $e->httpcode . " " . $e->httpdesc,
			"request" => $a->query_string);

	$ret = api_format_data('status', $type, array('status' => $error));

	switch ($type) {
		case "xml":
			header("Content-Type: text/xml");
			return $ret;
			break;
		case "json":
			header("Content-Type: application/json");
			return json_encode($ret);
			break;
		case "rss":
			header("Content-Type: application/rss+xml");
			return $ret;
			break;
		case "atom":
			header("Content-Type: application/atom+xml");
			return $ret;
			break;
	}
}

/**
 * @brief Set values for RSS template
 *
 * @param App $a
 * @param array $arr       Array to be passed to template
 * @param array $user_info User info
 * @return array
 * @todo find proper type-hints
 */
function api_rss_extra(App $a, $arr, $user_info)
{
	if (is_null($user_info)) {
		$user_info = api_get_user($a);
	}

	$arr['$user'] = $user_info;
	$arr['$rss'] = array(
		'alternate'    => $user_info['url'],
		'self'         => System::baseUrl() . "/" . $a->query_string,
		'base'         => System::baseUrl(),
		'updated'      => api_date(null),
		'atom_updated' => datetime_convert('UTC', 'UTC', 'now', ATOM_TIME),
		'language'     => $user_info['language'],
		'logo'         => System::baseUrl() . "/images/friendica-32.png",
	);

	return $arr;
}


/**
 * @brief Unique contact to contact url.
 *
 * @param int $id Contact id
 * @return bool|string
 * 		Contact url or False if contact id is unknown
 */
function api_unique_id_to_url($id)
{
	$r = dba::select('contact', array('url'), array('uid' => 0, 'id' => $id), array('limit' => 1));

	if (DBM::is_result($r)) {
		return $r["url"];
	} else {
		return false;
	}
}

/**
 * @brief Get user info array.
 *
 * @param object     $a          App
 * @param int|string $contact_id Contact ID or URL
 * @param string     $type       Return type (for errors)
 */
function api_get_user(App $a, $contact_id = null, $type = "json")
{
	global $called_api;

	$user = null;
	$extra_query = "";
	$url = "";
	$nick = "";

	logger("api_get_user: Fetching user data for user ".$contact_id, LOGGER_DEBUG);

	// Searching for contact URL
	if (!is_null($contact_id) && (intval($contact_id) == 0)) {
		$user = dbesc(normalise_link($contact_id));
		$url = $user;
		$extra_query = "AND `contact`.`nurl` = '%s' ";
		if (api_user() !== false) {
			$extra_query .= "AND `contact`.`uid`=" . intval(api_user());
		}
	}

	// Searching for contact id with uid = 0
	if (!is_null($contact_id) && (intval($contact_id) != 0)) {
		$user = dbesc(api_unique_id_to_url($contact_id));

		if ($user == "") {
			throw new BadRequestException("User not found.");
		}

		$url = $user;
		$extra_query = "AND `contact`.`nurl` = '%s' ";
		if (api_user() !== false) {
			$extra_query .= "AND `contact`.`uid`=" . intval(api_user());
		}
	}

	if (is_null($user) && x($_GET, 'user_id')) {
		$user = dbesc(api_unique_id_to_url($_GET['user_id']));

		if ($user == "") {
			throw new BadRequestException("User not found.");
		}

		$url = $user;
		$extra_query = "AND `contact`.`nurl` = '%s' ";
		if (api_user() !== false) {
			$extra_query .= "AND `contact`.`uid`=" . intval(api_user());
		}
	}
	if (is_null($user) && x($_GET, 'screen_name')) {
		$user = dbesc($_GET['screen_name']);
		$nick = $user;
		$extra_query = "AND `contact`.`nick` = '%s' ";
		if (api_user() !== false) {
			$extra_query .= "AND `contact`.`uid`=".intval(api_user());
		}
	}

	if (is_null($user) && x($_GET, 'profileurl')) {
		$user = dbesc(normalise_link($_GET['profileurl']));
		$nick = $user;
		$extra_query = "AND `contact`.`nurl` = '%s' ";
		if (api_user() !== false) {
			$extra_query .= "AND `contact`.`uid`=".intval(api_user());
		}
	}

	if (is_null($user) && ($a->argc > (count($called_api) - 1)) && (count($called_api) > 0)) {
		$argid = count($called_api);
		list($user, $null) = explode(".", $a->argv[$argid]);
		if (is_numeric($user)) {
			$user = dbesc(api_unique_id_to_url($user));

			if ($user == "") {
				return false;
			}

			$url = $user;
			$extra_query = "AND `contact`.`nurl` = '%s' ";
			if (api_user() !== false) {
				$extra_query .= "AND `contact`.`uid`=" . intval(api_user());
			}
		} else {
			$user = dbesc($user);
			$nick = $user;
			$extra_query = "AND `contact`.`nick` = '%s' ";
			if (api_user() !== false) {
				$extra_query .= "AND `contact`.`uid`=" . intval(api_user());
			}
		}
	}

	logger("api_get_user: user ".$user, LOGGER_DEBUG);

	if (!$user) {
		if (api_user() === false) {
			api_login($a);
			return false;
		} else {
			$user = $_SESSION['uid'];
			$extra_query = "AND `contact`.`uid` = %d AND `contact`.`self` ";
		}
	}

	logger('api_user: ' . $extra_query . ', user: ' . $user);

	// user info
	$uinfo = q(
		"SELECT *, `contact`.`id` AS `cid` FROM `contact`
			WHERE 1
		$extra_query",
		$user
	);

	// Selecting the id by priority, friendica first
	api_best_nickname($uinfo);

	// if the contact wasn't found, fetch it from the contacts with uid = 0
	if (!DBM::is_result($uinfo)) {
		$r = array();

		if ($url != "") {
			$r = q("SELECT * FROM `contact` WHERE `uid` = 0 AND `nurl` = '%s' LIMIT 1", dbesc(normalise_link($url)));
		}

		if (DBM::is_result($r)) {
			$network_name = network_to_name($r[0]['network'], $r[0]['url']);

			// If no nick where given, extract it from the address
			if (($r[0]['nick'] == "") || ($r[0]['name'] == $r[0]['nick'])) {
				$r[0]['nick'] = api_get_nick($r[0]["url"]);
			}

			$ret = array(
				'id' => $r[0]["id"],
				'id_str' => (string) $r[0]["id"],
				'name' => $r[0]["name"],
				'screen_name' => (($r[0]['nick']) ? $r[0]['nick'] : $r[0]['name']),
				'location' => ($r[0]["location"] != "") ? $r[0]["location"] : $network_name,
				'description' => $r[0]["about"],
				'profile_image_url' => $r[0]["micro"],
				'profile_image_url_https' => $r[0]["micro"],
				'url' => $r[0]["url"],
				'protected' => false,
				'followers_count' => 0,
				'friends_count' => 0,
				'listed_count' => 0,
				'created_at' => api_date($r[0]["created"]),
				'favourites_count' => 0,
				'utc_offset' => 0,
				'time_zone' => 'UTC',
				'geo_enabled' => false,
				'verified' => false,
				'statuses_count' => 0,
				'lang' => '',
				'contributors_enabled' => false,
				'is_translator' => false,
				'is_translation_enabled' => false,
				'following' => false,
				'follow_request_sent' => false,
				'statusnet_blocking' => false,
				'notifications' => false,
				'statusnet_profile_url' => $r[0]["url"],
				'uid' => 0,
				'cid' => Contact::getIdForURL($r[0]["url"], api_user(), true),
				'self' => 0,
				'network' => $r[0]["network"],
			);

			return $ret;
		} else {
			throw new BadRequestException("User not found.");
		}
	}

	if ($uinfo[0]['self']) {
		if ($uinfo[0]['network'] == "") {
			$uinfo[0]['network'] = NETWORK_DFRN;
		}

		$usr = q(
			"SELECT * FROM `user` WHERE `uid` = %d LIMIT 1",
			intval(api_user())
		);
		$profile = q(
			"SELECT * FROM `profile` WHERE `uid` = %d AND `is-default` = 1 LIMIT 1",
			intval(api_user())
		);

		/// @TODO old-lost code? (twice)
		// Counting is deactivated by now, due to performance issues
		// count public wall messages
		//$r = q("SELECT COUNT(*) as `count` FROM `item` WHERE `uid` = %d AND `wall`",
		//		intval($uinfo[0]['uid'])
		//);
		//$countitms = $r[0]['count'];
		$countitms = 0;
	} else {
		// Counting is deactivated by now, due to performance issues
		//$r = q("SELECT count(*) as `count` FROM `item`
		//		WHERE  `contact-id` = %d",
		//		intval($uinfo[0]['id'])
		//);
		//$countitms = $r[0]['count'];
		$countitms = 0;
	}

		/// @TODO old-lost code? (twice)
		/*
		// Counting is deactivated by now, due to performance issues
		// count friends
		$r = q("SELECT count(*) as `count` FROM `contact`
				WHERE  `uid` = %d AND `rel` IN ( %d, %d )
				AND `self`=0 AND NOT `blocked` AND NOT `pending` AND `hidden`=0",
				intval($uinfo[0]['uid']),
				intval(CONTACT_IS_SHARING),
				intval(CONTACT_IS_FRIEND)
		);
		$countfriends = $r[0]['count'];

		$r = q("SELECT count(*) as `count` FROM `contact`
				WHERE  `uid` = %d AND `rel` IN ( %d, %d )
				AND `self`=0 AND NOT `blocked` AND NOT `pending` AND `hidden`=0",
				intval($uinfo[0]['uid']),
				intval(CONTACT_IS_FOLLOWER),
				intval(CONTACT_IS_FRIEND)
		);
		$countfollowers = $r[0]['count'];

		$r = q("SELECT count(*) as `count` FROM item where starred = 1 and uid = %d and deleted = 0",
			intval($uinfo[0]['uid'])
		);
		$starred = $r[0]['count'];


		if (! $uinfo[0]['self']) {
			$countfriends = 0;
			$countfollowers = 0;
			$starred = 0;
		}
		*/
	$countfriends = 0;
	$countfollowers = 0;
	$starred = 0;

	// Add a nick if it isn't present there
	if (($uinfo[0]['nick'] == "") || ($uinfo[0]['name'] == $uinfo[0]['nick'])) {
		$uinfo[0]['nick'] = api_get_nick($uinfo[0]["url"]);
	}

	$network_name = network_to_name($uinfo[0]['network'], $uinfo[0]['url']);

	$pcontact_id  = Contact::getIdForURL($uinfo[0]['url'], 0, true);

	$ret = array(
		'id' => intval($pcontact_id),
		'id_str' => (string) intval($pcontact_id),
		'name' => (($uinfo[0]['name']) ? $uinfo[0]['name'] : $uinfo[0]['nick']),
		'screen_name' => (($uinfo[0]['nick']) ? $uinfo[0]['nick'] : $uinfo[0]['name']),
		'location' => ($usr) ? $usr[0]['default-location'] : $network_name,
		'description' => (($profile) ? $profile[0]['pdesc'] : null),
		'profile_image_url' => $uinfo[0]['micro'],
		'profile_image_url_https' => $uinfo[0]['micro'],
		'url' => $uinfo[0]['url'],
		'protected' => false,
		'followers_count' => intval($countfollowers),
		'friends_count' => intval($countfriends),
		'listed_count' => 0,
		'created_at' => api_date($uinfo[0]['created']),
		'favourites_count' => intval($starred),
		'utc_offset' => "0",
		'time_zone' => 'UTC',
		'geo_enabled' => false,
		'verified' => true,
		'statuses_count' => intval($countitms),
		'lang' => '',
		'contributors_enabled' => false,
		'is_translator' => false,
		'is_translation_enabled' => false,
		'following' => (($uinfo[0]['rel'] == CONTACT_IS_FOLLOWER) || ($uinfo[0]['rel'] == CONTACT_IS_FRIEND)),
		'follow_request_sent' => false,
		'statusnet_blocking' => false,
		'notifications' => false,
		/// @TODO old way?
		//'statusnet_profile_url' => System::baseUrl()."/contacts/".$uinfo[0]['cid'],
		'statusnet_profile_url' => $uinfo[0]['url'],
		'uid' => intval($uinfo[0]['uid']),
		'cid' => intval($uinfo[0]['cid']),
		'self' => $uinfo[0]['self'],
		'network' => $uinfo[0]['network'],
	);

	return $ret;
}

/**
 * @brief return api-formatted array for item's author and owner
 *
 * @param object $a    App
 * @param array  $item item from db
 * @return array(array:author, array:owner)
 */
function api_item_get_user(App $a, $item)
{
	$status_user = api_get_user($a, $item["author-link"]);

	$status_user["protected"] = (($item["allow_cid"] != "") ||
					($item["allow_gid"] != "") ||
					($item["deny_cid"] != "") ||
					($item["deny_gid"] != "") ||
					$item["private"]);

	if ($item['thr-parent'] == $item['uri']) {
		$owner_user = api_get_user($a, $item["owner-link"]);
	} else {
		$owner_user = $status_user;
	}

	return (array($status_user, $owner_user));
}

/**
 * @brief walks recursively through an array with the possibility to change value and key
 *
 * @param array  $array    The array to walk through
 * @param string $callback The callback function
 *
 * @return array the transformed array
 */
function api_walk_recursive(array &$array, callable $callback)
{
	$new_array = array();

	foreach ($array as $k => $v) {
		if (is_array($v)) {
			if ($callback($v, $k)) {
				$new_array[$k] = api_walk_recursive($v, $callback);
			}
		} else {
			if ($callback($v, $k)) {
				$new_array[$k] = $v;
			}
		}
	}
	$array = $new_array;

	return $array;
}

/**
 * @brief Callback function to transform the array in an array that can be transformed in a XML file
 *
 * @param mixed  $item Array item value
 * @param string $key  Array key
 *
 * @return boolean Should the array item be deleted?
 */
function api_reformat_xml(&$item, &$key)
{
	if (is_bool($item)) {
		$item = ($item ? "true" : "false");
	}

	if (substr($key, 0, 10) == "statusnet_") {
		$key = "statusnet:".substr($key, 10);
	} elseif (substr($key, 0, 10) == "friendica_") {
		$key = "friendica:".substr($key, 10);
	}
	/// @TODO old-lost code?
	//else
	//	$key = "default:".$key;

	return true;
}

/**
 * @brief Creates the XML from a JSON style array
 *
 * @param array  $data         JSON style array
 * @param string $root_element Name of the root element
 *
 * @return string The XML data
 */
function api_create_xml($data, $root_element)
{
	$childname = key($data);
	$data2 = array_pop($data);
	$key = key($data2);

	$namespaces = array("" => "http://api.twitter.com",
				"statusnet" => "http://status.net/schema/api/1/",
				"friendica" => "http://friendi.ca/schema/api/1/",
				"georss" => "http://www.georss.org/georss");

	/// @todo Auto detection of needed namespaces
	if (in_array($root_element, array("ok", "hash", "config", "version", "ids", "notes", "photos"))) {
		$namespaces = array();
	}

	if (is_array($data2)) {
		api_walk_recursive($data2, "api_reformat_xml");
	}

	if ($key == "0") {
		$data4 = array();
		$i = 1;

		foreach ($data2 as $item) {
			$data4[$i++.":".$childname] = $item;
		}

		$data2 = $data4;
	}

	$data3 = array($root_element => $data2);

	$ret = XML::fromArray($data3, $xml, false, $namespaces);
	return $ret;
}

/**
 * @brief Formats the data according to the data type
 *
 * @param string $root_element Name of the root element
 * @param string $type         Return type (atom, rss, xml, json)
 * @param array  $data         JSON style array
 *
 * @return (string|object) XML data or JSON data
 */
function api_format_data($root_element, $type, $data)
{
	$a = get_app();

	switch ($type) {
		case "atom":
		case "rss":
		case "xml":
			$ret = api_create_xml($data, $root_element);
			break;
		case "json":
			$ret = $data;
			break;
	}

	return $ret;
}

/**
 * TWITTER API
 */

/**
 * Returns an HTTP 200 OK response code and a representation of the requesting user if authentication was successful;
 * returns a 401 status code and an error message if not.
 * http://developer.twitter.com/doc/get/account/verify_credentials
 */
function api_account_verify_credentials($type)
{

	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	unset($_REQUEST["user_id"]);
	unset($_GET["user_id"]);

	unset($_REQUEST["screen_name"]);
	unset($_GET["screen_name"]);

	$skip_status = (x($_REQUEST, 'skip_status')?$_REQUEST['skip_status'] : false);

	$user_info = api_get_user($a);

	// "verified" isn't used here in the standard
	unset($user_info["verified"]);

	// - Adding last status
	if (!$skip_status) {
		$user_info["status"] = api_status_show("raw");
		if (!count($user_info["status"])) {
			unset($user_info["status"]);
		} else {
			unset($user_info["status"]["user"]);
		}
	}

	// "uid" and "self" are only needed for some internal stuff, so remove it from here
	unset($user_info["uid"]);
	unset($user_info["self"]);

	return api_format_data("user", $type, array('user' => $user_info));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/account/verify_credentials', 'api_account_verify_credentials', true);

/**
 * Get data from $_POST or $_GET
 */
function requestdata($k)
{
	if (x($_POST, $k)) {
		return $_POST[$k];
	}
	if (x($_GET, $k)) {
		return $_GET[$k];
	}
	return null;
}

/*Waitman Gobble Mod*/
function api_statuses_mediap($type)
{
	$a = get_app();

	if (api_user() === false) {
		logger('api_statuses_update: no user');
		throw new ForbiddenException();
	}
	$user_info = api_get_user($a);

	$_REQUEST['type'] = 'wall';
	$_REQUEST['profile_uid'] = api_user();
	$_REQUEST['api_source'] = true;
	$txt = requestdata('status');
	/// @TODO old-lost code?
	//$txt = urldecode(requestdata('status'));

	if ((strpos($txt, '<') !== false) || (strpos($txt, '>') !== false)) {
		$txt = html2bb_video($txt);
		$config = HTMLPurifier_Config::createDefault();
		$config->set('Cache.DefinitionImpl', null);
		$purifier = new HTMLPurifier($config);
		$txt = $purifier->purify($txt);
	}
	$txt = html2bbcode($txt);

	$a->argv[1]=$user_info['screen_name']; //should be set to username?

	// tell wall_upload function to return img info instead of echo
	$_REQUEST['hush'] = 'yeah';
	$bebop = wall_upload_post($a);

	// now that we have the img url in bbcode we can add it to the status and insert the wall item.
	$_REQUEST['body'] = $txt . "\n\n" . $bebop;
	item_post($a);

	// this should output the last post (the one we just posted).
	return api_status_show($type);
}

/// @TODO move this to top of file or somewhere better!
api_register_func('api/statuses/mediap', 'api_statuses_mediap', true, API_METHOD_POST);

function api_statuses_update($type)
{

	$a = get_app();

	if (api_user() === false) {
		logger('api_statuses_update: no user');
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	// convert $_POST array items to the form we use for web posts.

	// logger('api_post: ' . print_r($_POST,true));

	if (requestdata('htmlstatus')) {
		$txt = requestdata('htmlstatus');
		if ((strpos($txt, '<') !== false) || (strpos($txt, '>') !== false)) {
			$txt = html2bb_video($txt);

			$config = HTMLPurifier_Config::createDefault();
			$config->set('Cache.DefinitionImpl', null);

			$purifier = new HTMLPurifier($config);
			$txt = $purifier->purify($txt);

			$_REQUEST['body'] = html2bbcode($txt);
		}
	} else {
		$_REQUEST['body'] = requestdata('status');
	}

	$_REQUEST['title'] = requestdata('title');

	$parent = requestdata('in_reply_to_status_id');

	// Twidere sends "-1" if it is no reply ...
	if ($parent == -1) {
		$parent = "";
	}

	if (ctype_digit($parent)) {
		$_REQUEST['parent'] = $parent;
	} else {
		$_REQUEST['parent_uri'] = $parent;
	}

	if (requestdata('lat') && requestdata('long')) {
		$_REQUEST['coord'] = sprintf("%s %s", requestdata('lat'), requestdata('long'));
	}
	$_REQUEST['profile_uid'] = api_user();

	if ($parent) {
		$_REQUEST['type'] = 'net-comment';
	} else {
		// Check for throttling (maximum posts per day, week and month)
		$throttle_day = Config::get('system', 'throttle_limit_day');
		if ($throttle_day > 0) {
			$datefrom = date("Y-m-d H:i:s", time() - 24*60*60);

			$r = q(
				"SELECT COUNT(*) AS `posts_day` FROM `item` WHERE `uid`=%d AND `wall`
				AND `created` > '%s' AND `id` = `parent`",
				intval(api_user()),
				dbesc($datefrom)
			);

			if (DBM::is_result($r)) {
				$posts_day = $r[0]["posts_day"];
			} else {
				$posts_day = 0;
			}

			if ($posts_day > $throttle_day) {
				logger('Daily posting limit reached for user '.api_user(), LOGGER_DEBUG);
				// die(api_error($type, sprintf(t("Daily posting limit of %d posts reached. The post was rejected."), $throttle_day)));
				throw new TooManyRequestsException(sprintf(t("Daily posting limit of %d posts reached. The post was rejected."), $throttle_day));
			}
		}

		$throttle_week = Config::get('system', 'throttle_limit_week');
		if ($throttle_week > 0) {
			$datefrom = date("Y-m-d H:i:s", time() - 24*60*60*7);

			$r = q(
				"SELECT COUNT(*) AS `posts_week` FROM `item` WHERE `uid`=%d AND `wall`
				AND `created` > '%s' AND `id` = `parent`",
				intval(api_user()),
				dbesc($datefrom)
			);

			if (DBM::is_result($r)) {
				$posts_week = $r[0]["posts_week"];
			} else {
				$posts_week = 0;
			}

			if ($posts_week > $throttle_week) {
				logger('Weekly posting limit reached for user '.api_user(), LOGGER_DEBUG);
				// die(api_error($type, sprintf(t("Weekly posting limit of %d posts reached. The post was rejected."), $throttle_week)));
				throw new TooManyRequestsException(sprintf(t("Weekly posting limit of %d posts reached. The post was rejected."), $throttle_week));
			}
		}

		$throttle_month = Config::get('system', 'throttle_limit_month');
		if ($throttle_month > 0) {
			$datefrom = date("Y-m-d H:i:s", time() - 24*60*60*30);

			$r = q(
				"SELECT COUNT(*) AS `posts_month` FROM `item` WHERE `uid`=%d AND `wall`
				AND `created` > '%s' AND `id` = `parent`",
				intval(api_user()),
				dbesc($datefrom)
			);

			if (DBM::is_result($r)) {
				$posts_month = $r[0]["posts_month"];
			} else {
				$posts_month = 0;
			}

			if ($posts_month > $throttle_month) {
				logger('Monthly posting limit reached for user '.api_user(), LOGGER_DEBUG);
				// die(api_error($type, sprintf(t("Monthly posting limit of %d posts reached. The post was rejected."), $throttle_month)));
				throw new TooManyRequestsException(sprintf(t("Monthly posting limit of %d posts reached. The post was rejected."), $throttle_month));
			}
		}

		$_REQUEST['type'] = 'wall';
	}

	if (x($_FILES, 'media')) {
		// upload the image if we have one
		$_REQUEST['hush'] = 'yeah'; //tell wall_upload function to return img info instead of echo
		$media = wall_upload_post($a);
		if (strlen($media) > 0) {
			$_REQUEST['body'] .= "\n\n" . $media;
		}
	}

	// To-Do: Multiple IDs
	if (requestdata('media_ids')) {
		$r = q(
			"SELECT `resource-id`, `scale`, `nickname`, `type` FROM `photo` INNER JOIN `user` ON `user`.`uid` = `photo`.`uid` WHERE `resource-id` IN (SELECT `resource-id` FROM `photo` WHERE `id` = %d) AND `scale` > 0 AND `photo`.`uid` = %d ORDER BY `photo`.`width` DESC LIMIT 1",
			intval(requestdata('media_ids')),
			api_user()
		);
		if (DBM::is_result($r)) {
			$phototypes = Photo::supportedTypes();
			$ext = $phototypes[$r[0]['type']];
			$_REQUEST['body'] .= "\n\n" . '[url=' . System::baseUrl() . '/photos/' . $r[0]['nickname'] . '/image/' . $r[0]['resource-id'] . ']';
			$_REQUEST['body'] .= '[img]' . System::baseUrl() . '/photo/' . $r[0]['resource-id'] . '-' . $r[0]['scale'] . '.' . $ext . '[/img][/url]';
		}
	}

	// set this so that the item_post() function is quiet and doesn't redirect or emit json

	$_REQUEST['api_source'] = true;

	if (!x($_REQUEST, "source")) {
		$_REQUEST["source"] = api_source();
	}

	// call out normal post function
	item_post($a);

	// this should output the last post (the one we just posted).
	return api_status_show($type);
}

/// @TODO move to top of file or somwhere better
api_register_func('api/statuses/update', 'api_statuses_update', true, API_METHOD_POST);
api_register_func('api/statuses/update_with_media', 'api_statuses_update', true, API_METHOD_POST);

function api_media_upload($type)
{
	$a = get_app();

	if (api_user() === false) {
		logger('no user');
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	if (!x($_FILES, 'media')) {
		// Output error
		throw new BadRequestException("No media.");
	}

	$media = wall_upload_post($a, false);
	if (!$media) {
		// Output error
		throw new InternalServerErrorException();
	}

	$returndata = array();
	$returndata["media_id"] = $media["id"];
	$returndata["media_id_string"] = (string)$media["id"];
	$returndata["size"] = $media["size"];
	$returndata["image"] = array("w" => $media["width"],
					"h" => $media["height"],
					"image_type" => $media["type"]);

	logger("Media uploaded: " . print_r($returndata, true), LOGGER_DEBUG);

	return array("media" => $returndata);
}

/// @TODO move to top of file or somwhere better
api_register_func('api/media/upload', 'api_media_upload', true, API_METHOD_POST);

function api_status_show($type)
{
	$a = get_app();

	$user_info = api_get_user($a);

	logger('api_status_show: user_info: '.print_r($user_info, true), LOGGER_DEBUG);

	if ($type == "raw") {
		$privacy_sql = "AND `item`.`allow_cid`='' AND `item`.`allow_gid`='' AND `item`.`deny_cid`='' AND `item`.`deny_gid`=''";
	} else {
		$privacy_sql = "";
	}

	// get last public wall message
	$lastwall = q(
		"SELECT `item`.*
			FROM `item`
			WHERE `item`.`contact-id` = %d AND `item`.`uid` = %d
				AND ((`item`.`author-link` IN ('%s', '%s')) OR (`item`.`owner-link` IN ('%s', '%s')))
				AND `item`.`type` != 'activity' $privacy_sql
			ORDER BY `item`.`id` DESC
			LIMIT 1",
		intval($user_info['cid']),
		intval(api_user()),
		dbesc($user_info['url']),
		dbesc(normalise_link($user_info['url'])),
		dbesc($user_info['url']),
		dbesc(normalise_link($user_info['url']))
	);

	if (DBM::is_result($lastwall)) {
		$lastwall = $lastwall[0];

		$in_reply_to = api_in_reply_to($lastwall);

		$converted = api_convert_item($lastwall);

		if ($type == "xml") {
			$geo = "georss:point";
		} else {
			$geo = "geo";
		}

		$status_info = array(
			'created_at' => api_date($lastwall['created']),
			'id' => intval($lastwall['id']),
			'id_str' => (string) $lastwall['id'],
			'text' => $converted["text"],
			'source' => (($lastwall['app']) ? $lastwall['app'] : 'web'),
			'truncated' => false,
			'in_reply_to_status_id' => $in_reply_to['status_id'],
			'in_reply_to_status_id_str' => $in_reply_to['status_id_str'],
			'in_reply_to_user_id' => $in_reply_to['user_id'],
			'in_reply_to_user_id_str' => $in_reply_to['user_id_str'],
			'in_reply_to_screen_name' => $in_reply_to['screen_name'],
			'user' => $user_info,
			$geo => null,
			'coordinates' => "",
			'place' => "",
			'contributors' => "",
			'is_quote_status' => false,
			'retweet_count' => 0,
			'favorite_count' => 0,
			'favorited' => $lastwall['starred'] ? true : false,
			'retweeted' => false,
			'possibly_sensitive' => false,
			'lang' => "",
			'statusnet_html'		=> $converted["html"],
			'statusnet_conversation_id'	=> $lastwall['parent'],
		);

		if (count($converted["attachments"]) > 0) {
			$status_info["attachments"] = $converted["attachments"];
		}

		if (count($converted["entities"]) > 0) {
			$status_info["entities"] = $converted["entities"];
		}

		if (($lastwall['item_network'] != "") && ($status["source"] == 'web')) {
			$status_info["source"] = network_to_name($lastwall['item_network'], $user_info['url']);
		} elseif (($lastwall['item_network'] != "") && (network_to_name($lastwall['item_network'], $user_info['url']) != $status_info["source"])) {
			$status_info["source"] = trim($status_info["source"].' ('.network_to_name($lastwall['item_network'], $user_info['url']).')');
		}

		// "uid" and "self" are only needed for some internal stuff, so remove it from here
		unset($status_info["user"]["uid"]);
		unset($status_info["user"]["self"]);
	}

	logger('status_info: '.print_r($status_info, true), LOGGER_DEBUG);

	if ($type == "raw") {
		return $status_info;
	}

	return api_format_data("statuses", $type, array('status' => $status_info));
}

/**
 * Returns extended information of a given user, specified by ID or screen name as per the required id parameter.
 * The author's most recent status will be returned inline.
 * http://developer.twitter.com/doc/get/users/show
 */
function api_users_show($type)
{
	$a = get_app();

	$user_info = api_get_user($a);
	$lastwall = q(
		"SELECT `item`.*
			FROM `item`
			INNER JOIN `contact` ON `contact`.`id`=`item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			WHERE `item`.`uid` = %d AND `verb` = '%s' AND `item`.`contact-id` = %d
				AND ((`item`.`author-link` IN ('%s', '%s')) OR (`item`.`owner-link` IN ('%s', '%s')))
				AND `type`!='activity'
				AND `item`.`allow_cid`='' AND `item`.`allow_gid`='' AND `item`.`deny_cid`='' AND `item`.`deny_gid`=''
			ORDER BY `id` DESC
			LIMIT 1",
		intval(api_user()),
		dbesc(ACTIVITY_POST),
		intval($user_info['cid']),
		dbesc($user_info['url']),
		dbesc(normalise_link($user_info['url'])),
		dbesc($user_info['url']),
		dbesc(normalise_link($user_info['url']))
	);

	if (DBM::is_result($lastwall)) {
		$lastwall = $lastwall[0];

		$in_reply_to = api_in_reply_to($lastwall);

		$converted = api_convert_item($lastwall);

		if ($type == "xml") {
			$geo = "georss:point";
		} else {
			$geo = "geo";
		}

		$user_info['status'] = array(
			'text' => $converted["text"],
			'truncated' => false,
			'created_at' => api_date($lastwall['created']),
			'in_reply_to_status_id' => $in_reply_to['status_id'],
			'in_reply_to_status_id_str' => $in_reply_to['status_id_str'],
			'source' => (($lastwall['app']) ? $lastwall['app'] : 'web'),
			'id' => intval($lastwall['contact-id']),
			'id_str' => (string) $lastwall['contact-id'],
			'in_reply_to_user_id' => $in_reply_to['user_id'],
			'in_reply_to_user_id_str' => $in_reply_to['user_id_str'],
			'in_reply_to_screen_name' => $in_reply_to['screen_name'],
			$geo => null,
			'favorited' => $lastwall['starred'] ? true : false,
			'statusnet_html' => $converted["html"],
			'statusnet_conversation_id'	=> $lastwall['parent'],
		);

		if (count($converted["attachments"]) > 0) {
			$user_info["status"]["attachments"] = $converted["attachments"];
		}

		if (count($converted["entities"]) > 0) {
			$user_info["status"]["entities"] = $converted["entities"];
		}

		if (($lastwall['item_network'] != "") && ($user_info["status"]["source"] == 'web')) {
			$user_info["status"]["source"] = network_to_name($lastwall['item_network'], $user_info['url']);
		}

		if (($lastwall['item_network'] != "") && (network_to_name($lastwall['item_network'], $user_info['url']) != $user_info["status"]["source"])) {
			$user_info["status"]["source"] = trim($user_info["status"]["source"] . ' (' . network_to_name($lastwall['item_network'], $user_info['url']) . ')');
		}
	}

	// "uid" and "self" are only needed for some internal stuff, so remove it from here
	unset($user_info["uid"]);
	unset($user_info["self"]);

	return api_format_data("user", $type, array('user' => $user_info));
}

/// @TODO move to top of file or somewhere better
api_register_func('api/users/show', 'api_users_show');
api_register_func('api/externalprofile/show', 'api_users_show');

function api_users_search($type)
{
	$a = get_app();

	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] - 1 : 0);

	$userlist = array();

	if (x($_GET, 'q')) {
		$r = q("SELECT id FROM `contact` WHERE `uid` = 0 AND `name` = '%s'", dbesc($_GET["q"]));

		if (!DBM::is_result($r)) {
			$r = q("SELECT `id` FROM `contact` WHERE `uid` = 0 AND `nick` = '%s'", dbesc($_GET["q"]));
		}

		if (DBM::is_result($r)) {
			$k = 0;
			foreach ($r as $user) {
				$user_info = api_get_user($a, $user["id"], "json");

				if ($type == "xml") {
					$userlist[$k++.":user"] = $user_info;
				} else {
					$userlist[] = $user_info;
				}
			}
			$userlist = array("users" => $userlist);
		} else {
			throw new BadRequestException("User not found.");
		}
	} else {
		throw new BadRequestException("User not found.");
	}
	return api_format_data("users", $type, $userlist);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/users/search', 'api_users_search');

/**
 *
 * http://developer.twitter.com/doc/get/statuses/home_timeline
 *
 * TODO: Optional parameters
 * TODO: Add reply info
 */
function api_statuses_home_timeline($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	unset($_REQUEST["user_id"]);
	unset($_GET["user_id"]);

	unset($_REQUEST["screen_name"]);
	unset($_GET["screen_name"]);

	$user_info = api_get_user($a);
	// get last newtork messages

	// params
	$count = (x($_REQUEST, 'count') ? $_REQUEST['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] - 1 : 0);
	if ($page < 0) {
		$page = 0;
	}
	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);
	//$since_id = 0;//$since_id = (x($_REQUEST, 'since_id')?$_REQUEST['since_id'] : 0);
	$exclude_replies = (x($_REQUEST, 'exclude_replies') ? 1 : 0);
	$conversation_id = (x($_REQUEST, 'conversation_id') ? $_REQUEST['conversation_id'] : 0);

	$start = $page * $count;

	$sql_extra = '';
	if ($max_id > 0) {
		$sql_extra .= ' AND `item`.`id` <= ' . intval($max_id);
	}
	if ($exclude_replies > 0) {
		$sql_extra .= ' AND `item`.`parent` = `item`.`id`';
	}
	if ($conversation_id > 0) {
		$sql_extra .= ' AND `item`.`parent` = ' . intval($conversation_id);
	}

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item`
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`uid` = %d AND `verb` = '%s'
		AND `item`.`visible` AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		$sql_extra
		AND `item`.`id`>%d
		ORDER BY `item`.`id` DESC LIMIT %d ,%d ",
		intval(api_user()),
		dbesc(ACTIVITY_POST),
		intval($since_id),
		intval($start),
		intval($count)
	);

	$ret = api_format_items($r, $user_info, false, $type);

	// Set all posts from the query above to seen
	$idarray = array();
	foreach ($r as $item) {
		$idarray[] = intval($item["id"]);
	}

	$idlist = implode(",", $idarray);

	if ($idlist != "") {
		$unseen = q("SELECT `id` FROM `item` WHERE `unseen` AND `id` IN (%s)", $idlist);

		if ($unseen) {
			$r = q("UPDATE `item` SET `unseen` = 0 WHERE `unseen` AND `id` IN (%s)", $idlist);
		}
	}

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
			break;
	}

	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/home_timeline', 'api_statuses_home_timeline', true);
api_register_func('api/statuses/friends_timeline', 'api_statuses_home_timeline', true);

function api_statuses_public_timeline($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);
	// get last newtork messages

	// params
	$count = (x($_REQUEST, 'count') ? $_REQUEST['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] -1 : 0);
	if ($page < 0) {
		$page = 0;
	}
	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);
	//$since_id = 0;//$since_id = (x($_REQUEST, 'since_id')?$_REQUEST['since_id'] : 0);
	$exclude_replies = (x($_REQUEST, 'exclude_replies') ? 1 : 0);
	$conversation_id = (x($_REQUEST, 'conversation_id') ? $_REQUEST['conversation_id'] : 0);

	$start = $page * $count;

	if ($max_id > 0) {
		$sql_extra = 'AND `item`.`id` <= ' . intval($max_id);
	}
	if ($exclude_replies > 0) {
		$sql_extra .= ' AND `item`.`parent` = `item`.`id`';
	}
	if ($conversation_id > 0) {
		$sql_extra .= ' AND `item`.`parent` = ' . intval($conversation_id);
	}

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`self`, `contact`.`writable`,
		`contact`.`id` AS `cid`,
		`user`.`nickname`, `user`.`hidewall`
		FROM `item`
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		STRAIGHT_JOIN `user` ON `user`.`uid` = `item`.`uid`
			AND NOT `user`.`hidewall`
		WHERE `verb` = '%s' AND `item`.`visible` AND NOT `item`.`deleted` AND NOT `item`.`moderated`
		AND `item`.`allow_cid` = ''  AND `item`.`allow_gid` = ''
		AND `item`.`deny_cid`  = '' AND `item`.`deny_gid`  = ''
		AND NOT `item`.`private` AND `item`.`wall`
		$sql_extra
		AND `item`.`id`>%d
		ORDER BY `item`.`id` DESC LIMIT %d, %d ",
		dbesc(ACTIVITY_POST),
		intval($since_id),
		intval($start),
		intval($count)
	);

	$ret = api_format_items($r, $user_info, false, $type);

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
			break;
	}

	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/public_timeline', 'api_statuses_public_timeline', true);

/**
 * @TODO nothing to say?
 */
function api_statuses_show($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	// params
	$id = intval($a->argv[3]);

	if ($id == 0) {
		$id = intval($_REQUEST["id"]);
	}

	// Hotot workaround
	if ($id == 0) {
		$id = intval($a->argv[4]);
	}

	logger('API: api_statuses_show: ' . $id);

	$conversation = (x($_REQUEST, 'conversation') ? 1 : 0);

	$sql_extra = '';
	if ($conversation) {
		$sql_extra .= " AND `item`.`parent` = %d ORDER BY `id` ASC ";
	} else {
		$sql_extra .= " AND `item`.`id` = %d";
	}

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item`
		INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`visible` AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		AND `item`.`uid` = %d AND `item`.`verb` = '%s'
		$sql_extra",
		intval(api_user()),
		dbesc(ACTIVITY_POST),
		intval($id)
	);

	/// @TODO How about copying this to above methods which don't check $r ?
	if (!DBM::is_result($r)) {
		throw new BadRequestException("There is no status with this id.");
	}

	$ret = api_format_items($r, $user_info, false, $type);

	if ($conversation) {
		$data = array('status' => $ret);
		return api_format_data("statuses", $type, $data);
	} else {
		$data = array('status' => $ret[0]);
		return api_format_data("status", $type, $data);
	}
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/show', 'api_statuses_show', true);

/**
 * @TODO nothing to say?
 */
function api_conversation_show($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	// params
	$id = intval($a->argv[3]);
	$count = (x($_REQUEST, 'count') ? $_REQUEST['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] - 1 : 0);
	if ($page < 0) {
		$page = 0;
	}
	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);

	$start = $page*$count;

	if ($id == 0) {
		$id = intval($_REQUEST["id"]);
	}

	// Hotot workaround
	if ($id == 0) {
		$id = intval($a->argv[4]);
	}

	logger('API: api_conversation_show: '.$id);

	$r = q("SELECT `parent` FROM `item` WHERE `id` = %d", intval($id));
	if (DBM::is_result($r)) {
		$id = $r[0]["parent"];
	}

	$sql_extra = '';

	if ($max_id > 0) {
		$sql_extra = ' AND `item`.`id` <= ' . intval($max_id);
	}

	// Not sure why this query was so complicated. We should keep it here for a while,
	// just to make sure that we really don't need it.
	//	FROM `item` INNER JOIN (SELECT `uri`,`parent` FROM `item` WHERE `id` = %d) AS `temp1`
	//	ON (`item`.`thr-parent` = `temp1`.`uri` AND `item`.`parent` = `temp1`.`parent`)

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item`
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`parent` = %d AND `item`.`visible`
		AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		AND `item`.`uid` = %d AND `item`.`verb` = '%s'
		AND `item`.`id`>%d $sql_extra
		ORDER BY `item`.`id` DESC LIMIT %d ,%d",
		intval($id), intval(api_user()),
		dbesc(ACTIVITY_POST),
		intval($since_id),
		intval($start), intval($count)
	);

	if (!DBM::is_result($r)) {
		throw new BadRequestException("There is no status with this id.");
	}

	$ret = api_format_items($r, $user_info, false, $type);

	$data = array('status' => $ret);
	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/conversation/show', 'api_conversation_show', true);
api_register_func('api/statusnet/conversation', 'api_conversation_show', true);

/**
 * @TODO nothing to say?
 */
function api_statuses_repeat($type)
{
	global $called_api;

	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	// params
	$id = intval($a->argv[3]);

	if ($id == 0) {
		$id = intval($_REQUEST["id"]);
	}

	// Hotot workaround
	if ($id == 0) {
		$id = intval($a->argv[4]);
	}

	logger('API: api_statuses_repeat: '.$id);

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`, `contact`.`nick` as `reply_author`,
		`contact`.`name`, `contact`.`photo` as `reply_photo`, `contact`.`url` as `reply_url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item`
		INNER JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`visible` AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		AND NOT `item`.`private` AND `item`.`allow_cid` = '' AND `item`.`allow_gid` = ''
		AND `item`.`deny_cid` = '' AND `item`.`deny_gid` = ''
		$sql_extra
		AND `item`.`id`=%d",
		intval($id)
	);

	/// @TODO other style than above functions!
	if (DBM::is_result($r) && $r[0]['body'] != "") {
		if (strpos($r[0]['body'], "[/share]") !== false) {
			$pos = strpos($r[0]['body'], "[share");
			$post = substr($r[0]['body'], $pos);
		} else {
			$post = share_header($r[0]['author-name'], $r[0]['author-link'], $r[0]['author-avatar'], $r[0]['guid'], $r[0]['created'], $r[0]['plink']);

			$post .= $r[0]['body'];
			$post .= "[/share]";
		}
		$_REQUEST['body'] = $post;
		$_REQUEST['profile_uid'] = api_user();
		$_REQUEST['type'] = 'wall';
		$_REQUEST['api_source'] = true;

		if (!x($_REQUEST, "source")) {
			$_REQUEST["source"] = api_source();
		}

		item_post($a);
	} else {
		throw new ForbiddenException();
	}

	// this should output the last post (the one we just posted).
	$called_api = null;
	return api_status_show($type);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/retweet', 'api_statuses_repeat', true, API_METHOD_POST);

/**
 * @TODO nothing to say?
 */
function api_statuses_destroy($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	// params
	$id = intval($a->argv[3]);

	if ($id == 0) {
		$id = intval($_REQUEST["id"]);
	}

	// Hotot workaround
	if ($id == 0) {
		$id = intval($a->argv[4]);
	}

	logger('API: api_statuses_destroy: '.$id);

	$ret = api_statuses_show($type);

	drop_item($id, false);

	return $ret;
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/destroy', 'api_statuses_destroy', true, API_METHOD_DELETE);

/**
 * @TODO Nothing more than an URL to say?
 * http://developer.twitter.com/doc/get/statuses/mentions
 */
function api_statuses_mentions($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	unset($_REQUEST["user_id"]);
	unset($_GET["user_id"]);

	unset($_REQUEST["screen_name"]);
	unset($_GET["screen_name"]);

	$user_info = api_get_user($a);
	// get last newtork messages


	// params
	$count = (x($_REQUEST, 'count') ? $_REQUEST['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] -1 : 0);
	if ($page < 0) {
		$page = 0;
	}
	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);
	//$since_id = 0;//$since_id = (x($_REQUEST, 'since_id')?$_REQUEST['since_id'] : 0);

	$start = $page * $count;

	// Ugly code - should be changed
	$myurl = System::baseUrl() . '/profile/'. $a->user['nickname'];
	$myurl = substr($myurl, strpos($myurl, '://') + 3);
	//$myurl = str_replace(array('www.','.'),array('','\\.'),$myurl);
	$myurl = str_replace('www.', '', $myurl);
	$diasp_url = str_replace('/profile/', '/u/', $myurl);

	if ($max_id > 0) {
		$sql_extra = ' AND `item`.`id` <= ' . intval($max_id);
	}

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item` FORCE INDEX (`uid_id`)
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`uid` = %d AND `verb` = '%s'
		AND NOT (`item`.`author-link` IN ('https://%s', 'http://%s'))
		AND `item`.`visible` AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		AND `item`.`parent` IN (SELECT `iid` FROM `thread` WHERE `uid` = %d AND `mention` AND !`ignored`)
		$sql_extra
		AND `item`.`id`>%d
		ORDER BY `item`.`id` DESC LIMIT %d ,%d ",
		intval(api_user()),
		dbesc(ACTIVITY_POST),
		dbesc(protect_sprintf($myurl)),
		dbesc(protect_sprintf($myurl)),
		intval(api_user()),
		intval($since_id),
		intval($start),
		intval($count)
	);

	$ret = api_format_items($r, $user_info, false, $type);

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
			break;
	}

	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/mentions', 'api_statuses_mentions', true);
api_register_func('api/statuses/replies', 'api_statuses_mentions', true);

function api_statuses_user_timeline($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);
	// get last network messages

	logger(
		"api_statuses_user_timeline: api_user: ". api_user() .
			"\nuser_info: ".print_r($user_info, true) .
			"\n_REQUEST:  ".print_r($_REQUEST, true),
		LOGGER_DEBUG
	);

	// params
	$count = (x($_REQUEST, 'count') ? $_REQUEST['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] -1 : 0);
	if ($page < 0) {
		$page = 0;
	}
	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	//$since_id = 0;//$since_id = (x($_REQUEST, 'since_id')?$_REQUEST['since_id'] : 0);
	$exclude_replies = (x($_REQUEST, 'exclude_replies') ? 1 : 0);
	$conversation_id = (x($_REQUEST, 'conversation_id') ? $_REQUEST['conversation_id'] : 0);

	$start = $page * $count;

	$sql_extra = '';
	if ($user_info['self'] == 1) {
		$sql_extra .= " AND `item`.`wall` = 1 ";
	}

	if ($exclude_replies > 0) {
		$sql_extra .= ' AND `item`.`parent` = `item`.`id`';
	}
	if ($conversation_id > 0) {
		$sql_extra .= ' AND `item`.`parent` = ' . intval($conversation_id);
	}

	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item` FORCE INDEX (`uid_contactid_id`)
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`uid` = %d AND `verb` = '%s'
		AND `item`.`contact-id` = %d
		AND `item`.`visible` AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		$sql_extra
		AND `item`.`id`>%d
		ORDER BY `item`.`id` DESC LIMIT %d ,%d ",
		intval(api_user()),
		dbesc(ACTIVITY_POST),
		intval($user_info['cid']),
		intval($since_id),
		intval($start),
		intval($count)
	);

	$ret = api_format_items($r, $user_info, true, $type);

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
			break;
	}

	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somwhere better
api_register_func('api/statuses/user_timeline','api_statuses_user_timeline', true);

/**
 * Star/unstar an item
 * param: id : id of the item
 *
 * api v1 : https://web.archive.org/web/20131019055350/https://dev.twitter.com/docs/api/1/post/favorites/create/%3Aid
 */
function api_favorites_create_destroy($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// for versioned api.
	/// @TODO We need a better global soluton
	$action_argv_id = 2;
	if ($a->argv[1] == "1.1") {
		$action_argv_id = 3;
	}

	if ($a->argc <= $action_argv_id) {
		throw new BadRequestException("Invalid request.");
	}
	$action = str_replace("." . $type, "", $a->argv[$action_argv_id]);
	if ($a->argc == $action_argv_id + 2) {
		$itemid = intval($a->argv[$action_argv_id + 1]);
	} else {
		///  @TODO use x() to check if _REQUEST contains 'id'
		$itemid = intval($_REQUEST['id']);
	}

	$item = q("SELECT * FROM `item` WHERE `id`=%d AND `uid`=%d LIMIT 1", $itemid, api_user());

	if (!DBM::is_result($item) || count($item) == 0) {
		throw new BadRequestException("Invalid item.");
	}

	switch ($action) {
		case "create":
			$item[0]['starred'] = 1;
			break;
		case "destroy":
			$item[0]['starred'] = 0;
			break;
		default:
			throw new BadRequestException("Invalid action ".$action);
	}

	$r = q("UPDATE item SET starred=%d WHERE id=%d AND uid=%d",	$item[0]['starred'], $itemid, api_user());

	q("UPDATE thread SET starred=%d WHERE iid=%d AND uid=%d", $item[0]['starred'], $itemid, api_user());

	if ($r === false) {
		throw new InternalServerErrorException("DB error");
	}


	$user_info = api_get_user($a);
	$rets = api_format_items($item, $user_info, false, $type);
	$ret = $rets[0];

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
	}

	return api_format_data("status", $type, $data);
}

/// @TODO move to top of file or somwhere better
api_register_func('api/favorites/create', 'api_favorites_create_destroy', true, API_METHOD_POST);
api_register_func('api/favorites/destroy', 'api_favorites_create_destroy', true, API_METHOD_DELETE);

function api_favorites($type)
{
	global $called_api;

	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$called_api = array();

	$user_info = api_get_user($a);

	// in friendica starred item are private
	// return favorites only for self
	logger('api_favorites: self:' . $user_info['self']);

	if ($user_info['self'] == 0) {
		$ret = array();
	} else {
		$sql_extra = "";

		// params
		$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
		$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);
		$count = (x($_GET, 'count') ? $_GET['count'] : 20);
		$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] -1 : 0);
		if ($page < 0) {
			$page = 0;
		}

		$start = $page*$count;

		if ($max_id > 0) {
			$sql_extra .= ' AND `item`.`id` <= ' . intval($max_id);
		}

		$r = q(
			"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
			`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
			`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
			`contact`.`id` AS `cid`
			FROM `item`, `contact`
			WHERE `item`.`uid` = %d
			AND `item`.`visible` = 1 AND `item`.`moderated` = 0 AND `item`.`deleted` = 0
			AND `item`.`starred` = 1
			AND `contact`.`id` = `item`.`contact-id`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
			$sql_extra
			AND `item`.`id`>%d
			ORDER BY `item`.`id` DESC LIMIT %d ,%d ",
			intval(api_user()),
			intval($since_id),
			intval($start),
			intval($count)
		);

		$ret = api_format_items($r, $user_info, false, $type);
	}

	$data = array('status' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
	}

	return api_format_data("statuses", $type, $data);
}

/// @TODO move to top of file or somwhere better
api_register_func('api/favorites', 'api_favorites', true);

function api_format_messages($item, $recipient, $sender)
{
	// standard meta information
	$ret = array(
			'id'                    => $item['id'],
			'sender_id'             => $sender['id'] ,
			'text'                  => "",
			'recipient_id'          => $recipient['id'],
			'created_at'            => api_date($item['created']),
			'sender_screen_name'    => $sender['screen_name'],
			'recipient_screen_name' => $recipient['screen_name'],
			'sender'                => $sender,
			'recipient'             => $recipient,
			'title'                 => "",
			'friendica_seen'        => $item['seen'],
			'friendica_parent_uri'  => $item['parent-uri'],
	);

	// "uid" and "self" are only needed for some internal stuff, so remove it from here
	unset($ret["sender"]["uid"]);
	unset($ret["sender"]["self"]);
	unset($ret["recipient"]["uid"]);
	unset($ret["recipient"]["self"]);

	//don't send title to regular StatusNET requests to avoid confusing these apps
	if (x($_GET, 'getText')) {
		$ret['title'] = $item['title'];
		if ($_GET['getText'] == 'html') {
			$ret['text'] = bbcode($item['body'], false, false);
		} elseif ($_GET['getText'] == 'plain') {
			//$ret['text'] = html2plain(bbcode($item['body'], false, false, true), 0);
			$ret['text'] = trim(html2plain(bbcode(api_clean_plain_items($item['body']), false, false, 2, true), 0));
		}
	} else {
		$ret['text'] = $item['title'] . "\n" . html2plain(bbcode(api_clean_plain_items($item['body']), false, false, 2, true), 0);
	}
	if (x($_GET, 'getUserObjects') && $_GET['getUserObjects'] == 'false') {
		unset($ret['sender']);
		unset($ret['recipient']);
	}

	return $ret;
}

function api_convert_item($item)
{
	$body = $item['body'];
	$attachments = api_get_attachments($body);

	// Workaround for ostatus messages where the title is identically to the body
	$html = bbcode(api_clean_plain_items($body), false, false, 2, true);
	$statusbody = trim(html2plain($html, 0));

	// handle data: images
	$statusbody = api_format_items_embeded_images($item, $statusbody);

	$statustitle = trim($item['title']);

	if (($statustitle != '') && (strpos($statusbody, $statustitle) !== false)) {
		$statustext = trim($statusbody);
	} else {
		$statustext = trim($statustitle."\n\n".$statusbody);
	}

	if (($item["network"] == NETWORK_FEED) && (strlen($statustext)> 1000)) {
		$statustext = substr($statustext, 0, 1000)."... \n".$item["plink"];
	}

	$statushtml = trim(bbcode($body, false, false));

	// Workaround for clients with limited HTML parser functionality
	$search = array("<br>", "<blockquote>", "</blockquote>",
			"<h1>", "</h1>", "<h2>", "</h2>",
			"<h3>", "</h3>", "<h4>", "</h4>",
			"<h5>", "</h5>", "<h6>", "</h6>");
	$replace = array("<br>", "<br><blockquote>", "</blockquote><br>",
			"<br><h1>", "</h1><br>", "<br><h2>", "</h2><br>",
			"<br><h3>", "</h3><br>", "<br><h4>", "</h4><br>",
			"<br><h5>", "</h5><br>", "<br><h6>", "</h6><br>");
	$statushtml = str_replace($search, $replace, $statushtml);

	if ($item['title'] != "") {
		$statushtml = "<br><h4>" . bbcode($item['title']) . "</h4><br>" . $statushtml;
	}

	do {
		$oldtext = $statushtml;
		$statushtml = str_replace("<br><br>", "<br>", $statushtml);
	} while ($oldtext != $statushtml);

	if (substr($statushtml, 0, 4) == '<br>') {
		$statushtml = substr($statushtml, 4);
	}

	if (substr($statushtml, 0, -4) == '<br>') {
		$statushtml = substr($statushtml, -4);
	}

	// feeds without body should contain the link
	if (($item['network'] == NETWORK_FEED) && (strlen($item['body']) == 0)) {
		$statushtml .= bbcode($item['plink']);
	}

	$entities = api_get_entitities($statustext, $body);

	return array(
		"text" => $statustext,
		"html" => $statushtml,
		"attachments" => $attachments,
		"entities" => $entities
	);
}

function api_get_attachments(&$body)
{
	$text = $body;
	$text = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $text);

	$URLSearchString = "^\[\]";
	$ret = preg_match_all("/\[img\]([$URLSearchString]*)\[\/img\]/ism", $text, $images);

	if (!$ret) {
		return false;
	}

	$attachments = array();

	foreach ($images[1] as $image) {
		$imagedata = Photo::getPhotoInfo($image);

		if ($imagedata) {
			$attachments[] = array("url" => $image, "mimetype" => $imagedata["mime"], "size" => $imagedata["size"]);
		}
	}

	if (strstr($_SERVER['HTTP_USER_AGENT'], "AndStatus")) {
		foreach ($images[0] as $orig) {
			$body = str_replace($orig, "", $body);
		}
	}

	return $attachments;
}

function api_get_entitities(&$text, $bbcode)
{
	/*
	To-Do:
	* Links at the first character of the post
	*/

	$a = get_app();

	$include_entities = strtolower(x($_REQUEST, 'include_entities') ? $_REQUEST['include_entities'] : "false");

	if ($include_entities != "true") {
		preg_match_all("/\[img](.*?)\[\/img\]/ism", $bbcode, $images);

		foreach ($images[1] as $image) {
			$replace = proxy_url($image);
			$text = str_replace($image, $replace, $text);
		}
		return array();
	}

	$bbcode = bb_CleanPictureLinks($bbcode);

	// Change pure links in text to bbcode uris
	$bbcode = preg_replace("/([^\]\='".'"'."]|^)(https?\:\/\/[a-zA-Z0-9\:\/\-\?\&\;\.\=\_\~\#\%\$\!\+\,]+)/ism", '$1[url=$2]$2[/url]', $bbcode);

	$entities = array();
	$entities["hashtags"] = array();
	$entities["symbols"] = array();
	$entities["urls"] = array();
	$entities["user_mentions"] = array();

	$URLSearchString = "^\[\]";

	$bbcode = preg_replace("/#\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '#$2', $bbcode);

	$bbcode = preg_replace("/\[bookmark\=([$URLSearchString]*)\](.*?)\[\/bookmark\]/ism", '[url=$1]$2[/url]', $bbcode);
	//$bbcode = preg_replace("/\[url\](.*?)\[\/url\]/ism",'[url=$1]$1[/url]',$bbcode);
	$bbcode = preg_replace("/\[video\](.*?)\[\/video\]/ism", '[url=$1]$1[/url]', $bbcode);

	$bbcode = preg_replace(
		"/\[youtube\]([A-Za-z0-9\-_=]+)(.*?)\[\/youtube\]/ism",
		'[url=https://www.youtube.com/watch?v=$1]https://www.youtube.com/watch?v=$1[/url]',
		$bbcode
	);
	$bbcode = preg_replace("/\[youtube\](.*?)\[\/youtube\]/ism", '[url=$1]$1[/url]', $bbcode);

	$bbcode = preg_replace(
		"/\[vimeo\]([0-9]+)(.*?)\[\/vimeo\]/ism",
		'[url=https://vimeo.com/$1]https://vimeo.com/$1[/url]',
		$bbcode
	);
	$bbcode = preg_replace("/\[vimeo\](.*?)\[\/vimeo\]/ism", '[url=$1]$1[/url]', $bbcode);

	$bbcode = preg_replace("/\[img\=([0-9]*)x([0-9]*)\](.*?)\[\/img\]/ism", '[img]$3[/img]', $bbcode);

	//preg_match_all("/\[url\]([$URLSearchString]*)\[\/url\]/ism", $bbcode, $urls1);
	preg_match_all("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", $bbcode, $urls);

	$ordered_urls = array();
	foreach ($urls[1] as $id => $url) {
		//$start = strpos($text, $url, $offset);
		$start = iconv_strpos($text, $url, 0, "UTF-8");
		if (!($start === false)) {
			$ordered_urls[$start] = array("url" => $url, "title" => $urls[2][$id]);
		}
	}

	ksort($ordered_urls);

	$offset = 0;
	//foreach ($urls[1] AS $id=>$url) {
	foreach ($ordered_urls as $url) {
		if ((substr($url["title"], 0, 7) != "http://") && (substr($url["title"], 0, 8) != "https://")
			&& !strpos($url["title"], "http://") && !strpos($url["title"], "https://")
		)
			$display_url = $url["title"];
		else {
			$display_url = str_replace(array("http://www.", "https://www."), array("", ""), $url["url"]);
			$display_url = str_replace(array("http://", "https://"), array("", ""), $display_url);

			if (strlen($display_url) > 26)
				$display_url = substr($display_url, 0, 25)."…";
		}

		//$start = strpos($text, $url, $offset);
		$start = iconv_strpos($text, $url["url"], $offset, "UTF-8");
		if (!($start === false)) {
			$entities["urls"][] = array("url" => $url["url"],
							"expanded_url" => $url["url"],
							"display_url" => $display_url,
							"indices" => array($start, $start+strlen($url["url"])));
			$offset = $start + 1;
		}
	}

	preg_match_all("/\[img](.*?)\[\/img\]/ism", $bbcode, $images);
	$ordered_images = array();
	foreach ($images[1] as $image) {
		//$start = strpos($text, $url, $offset);
		$start = iconv_strpos($text, $image, 0, "UTF-8");
		if (!($start === false))
			$ordered_images[$start] = $image;
	}
	//$entities["media"] = array();
	$offset = 0;

	foreach ($ordered_images as $url) {
		$display_url = str_replace(array("http://www.", "https://www."), array("", ""), $url);
		$display_url = str_replace(array("http://", "https://"), array("", ""), $display_url);

		if (strlen($display_url) > 26)
			$display_url = substr($display_url, 0, 25)."…";

		$start = iconv_strpos($text, $url, $offset, "UTF-8");
		if (!($start === false)) {
			$image = Photo::getPhotoInfo($url);
			if ($image) {
				// If image cache is activated, then use the following sizes:
				// thumb  (150), small (340), medium (600) and large (1024)
				if (!Config::get("system", "proxy_disabled")) {
					$media_url = proxy_url($url);

					$sizes = array();
					$scale = Photo::scaleImageTo($image[0], $image[1], 150);
					$sizes["thumb"] = array("w" => $scale["width"], "h" => $scale["height"], "resize" => "fit");

					if (($image[0] > 150) || ($image[1] > 150)) {
						$scale = Photo::scaleImageTo($image[0], $image[1], 340);
						$sizes["small"] = array("w" => $scale["width"], "h" => $scale["height"], "resize" => "fit");
					}

					$scale = Photo::scaleImageTo($image[0], $image[1], 600);
					$sizes["medium"] = array("w" => $scale["width"], "h" => $scale["height"], "resize" => "fit");

					if (($image[0] > 600) || ($image[1] > 600)) {
						$scale = Photo::scaleImageTo($image[0], $image[1], 1024);
						$sizes["large"] = array("w" => $scale["width"], "h" => $scale["height"], "resize" => "fit");
					}
				} else {
					$media_url = $url;
					$sizes["medium"] = array("w" => $image[0], "h" => $image[1], "resize" => "fit");
				}

				$entities["media"][] = array(
							"id" => $start+1,
							"id_str" => (string)$start+1,
							"indices" => array($start, $start+strlen($url)),
							"media_url" => normalise_link($media_url),
							"media_url_https" => $media_url,
							"url" => $url,
							"display_url" => $display_url,
							"expanded_url" => $url,
							"type" => "photo",
							"sizes" => $sizes);
			}
			$offset = $start + 1;
		}
	}

	return $entities;
}
function api_format_items_embeded_images(&$item, $text)
{
	$text = preg_replace_callback(
		"|data:image/([^;]+)[^=]+=*|m",
		function ($match) use ($item) {
			return System::baseUrl()."/display/".$item['guid'];
		},
		$text
	);
	return $text;
}


/**
 * @brief return <a href='url'>name</a> as array
 *
 * @param string $txt text
 * @return array
 * 			name => 'name'
 * 			'url => 'url'
 */
function api_contactlink_to_array($txt)
{
	$match = array();
	$r = preg_match_all('|<a href="([^"]*)">([^<]*)</a>|', $txt, $match);
	if ($r && count($match)==3) {
		$res = array(
			'name' => $match[2],
			'url' => $match[1]
		);
	} else {
		$res = array(
			'name' => $text,
			'url' => ""
		);
	}
	return $res;
}


/**
 * @brief return likes, dislikes and attend status for item
 *
 * @param array $item array
 * @return array
 * 			likes => int count
 * 			dislikes => int count
 */
function api_format_items_activities(&$item, $type = "json")
{
	$a = get_app();

	$activities = array(
		'like' => array(),
		'dislike' => array(),
		'attendyes' => array(),
		'attendno' => array(),
		'attendmaybe' => array(),
	);

	$items = q(
		'SELECT * FROM item
			WHERE uid=%d AND `thr-parent`="%s" AND visible AND NOT deleted',
		intval($item['uid']),
		dbesc($item['uri'])
	);

	foreach ($items as $i) {
		// not used as result should be structured like other user data
		//builtin_activity_puller($i, $activities);

		// get user data and add it to the array of the activity
		$user = api_get_user($a, $i['author-link']);
		switch ($i['verb']) {
			case ACTIVITY_LIKE:
				$activities['like'][] = $user;
				break;
			case ACTIVITY_DISLIKE:
				$activities['dislike'][] = $user;
				break;
			case ACTIVITY_ATTEND:
				$activities['attendyes'][] = $user;
				break;
			case ACTIVITY_ATTENDNO:
				$activities['attendno'][] = $user;
				break;
			case ACTIVITY_ATTENDMAYBE:
				$activities['attendmaybe'][] = $user;
				break;
			default:
				break;
		}
	}

	if ($type == "xml") {
		$xml_activities = array();
		foreach ($activities as $k => $v) {
			// change xml element from "like" to "friendica:like"
			$xml_activities["friendica:".$k] = $v;
			// add user data into xml output
			$k_user = 0;
			foreach ($v as $user)
				$xml_activities["friendica:".$k][$k_user++.":user"] = $user;
		}
		$activities = $xml_activities;
	}

	return $activities;
}


/**
 * @brief return data from profiles
 *
 * @param array  $profile array containing data from db table 'profile'
 * @param string $type    Known types are 'atom', 'rss', 'xml' and 'json'
 * @return array
 */
function api_format_items_profiles(&$profile = null, $type = "json")
{
	if ($profile != null) {
		$profile = array('profile_id' => $profile['id'],
						'profile_name' => $profile['profile-name'],
						'is_default' => $profile['is-default'] ? true : false,
						'hide_friends'=> $profile['hide-friends'] ? true : false,
						'profile_photo' => $profile['photo'],
						'profile_thumb' => $profile['thumb'],
						'publish' => $profile['publish'] ? true : false,
						'net_publish' => $profile['net-publish'] ? true : false,
						'description' => $profile['pdesc'],
						'date_of_birth' => $profile['dob'],
						'address' => $profile['address'],
						'city' => $profile['locality'],
						'region' => $profile['region'],
						'postal_code' => $profile['postal-code'],
						'country' => $profile['country-name'],
						'hometown' => $profile['hometown'],
						'gender' => $profile['gender'],
						'marital' => $profile['marital'],
						'marital_with' => $profile['with'],
						'marital_since' => $profile['howlong'],
						'sexual' => $profile['sexual'],
						'politic' => $profile['politic'],
						'religion' => $profile['religion'],
						'public_keywords' => $profile['pub_keywords'],
						'private_keywords' => $profile['prv_keywords'],
						'likes' => bbcode(api_clean_plain_items($profile['likes']), false, false, 2, false),
						'dislikes' => bbcode(api_clean_plain_items($profile['dislikes']), false, false, 2, false),
						'about' => bbcode(api_clean_plain_items($profile['about']), false, false, 2, false),
						'music' => bbcode(api_clean_plain_items($profile['music']), false, false, 2, false),
						'book' => bbcode(api_clean_plain_items($profile['book']), false, false, 2, false),
						'tv' => bbcode(api_clean_plain_items($profile['tv']), false, false, 2, false),
						'film' => bbcode(api_clean_plain_items($profile['film']), false, false, 2, false),
						'interest' => bbcode(api_clean_plain_items($profile['interest']), false, false, 2, false),
						'romance' => bbcode(api_clean_plain_items($profile['romance']), false, false, 2, false),
						'work' => bbcode(api_clean_plain_items($profile['work']), false, false, 2, false),
						'education' => bbcode(api_clean_plain_items($profile['education']), false, false, 2, false),
						'social_networks' => bbcode(api_clean_plain_items($profile['contact']), false, false, 2, false),
						'homepage' => $profile['homepage'],
						'users' => null);
		return $profile;
	}
}

/**
 * @brief format items to be returned by api
 *
 * @param array $r array of items
 * @param array $user_info
 * @param bool $filter_user filter items by $user_info
 */
function api_format_items($r, $user_info, $filter_user = false, $type = "json")
{
	$a = get_app();

	$ret = array();

	foreach ($r as $item) {
		localize_item($item);
		list($status_user, $owner_user) = api_item_get_user($a, $item);

		// Look if the posts are matching if they should be filtered by user id
		if ($filter_user && ($status_user["id"] != $user_info["id"])) {
			continue;
		}

		$in_reply_to = api_in_reply_to($item);

		$converted = api_convert_item($item);

		if ($type == "xml") {
			$geo = "georss:point";
		} else {
			$geo = "geo";
		}

		$status = array(
			'text'		=> $converted["text"],
			'truncated' => false,
			'created_at'=> api_date($item['created']),
			'in_reply_to_status_id' => $in_reply_to['status_id'],
			'in_reply_to_status_id_str' => $in_reply_to['status_id_str'],
			'source'    => (($item['app']) ? $item['app'] : 'web'),
			'id'		=> intval($item['id']),
			'id_str'	=> (string) intval($item['id']),
			'in_reply_to_user_id' => $in_reply_to['user_id'],
			'in_reply_to_user_id_str' => $in_reply_to['user_id_str'],
			'in_reply_to_screen_name' => $in_reply_to['screen_name'],
			$geo => null,
			'favorited' => $item['starred'] ? true : false,
			'user' =>  $status_user ,
			'friendica_owner' => $owner_user,
			//'entities' => NULL,
			'statusnet_html'		=> $converted["html"],
			'statusnet_conversation_id'	=> $item['parent'],
			'friendica_activities' => api_format_items_activities($item, $type),
		);

		if (count($converted["attachments"]) > 0) {
			$status["attachments"] = $converted["attachments"];
		}

		if (count($converted["entities"]) > 0) {
			$status["entities"] = $converted["entities"];
		}

		if (($item['item_network'] != "") && ($status["source"] == 'web')) {
			$status["source"] = network_to_name($item['item_network'], $user_info['url']);
		} elseif (($item['item_network'] != "") && (network_to_name($item['item_network'], $user_info['url']) != $status["source"])) {
			$status["source"] = trim($status["source"].' ('.network_to_name($item['item_network'], $user_info['url']).')');
		}


		// Retweets are only valid for top postings
		// It doesn't work reliable with the link if its a feed
		//$IsRetweet = ($item['owner-link'] != $item['author-link']);
		//if ($IsRetweet)
		//	$IsRetweet = (($item['owner-name'] != $item['author-name']) || ($item['owner-avatar'] != $item['author-avatar']));


		if ($item["id"] == $item["parent"]) {
			$retweeted_item = api_share_as_retweet($item);
			if ($retweeted_item !== false) {
				$retweeted_status = $status;
				try {
					$retweeted_status["user"] = api_get_user($a, $retweeted_item["author-link"]);
				} catch (BadRequestException $e) {
					// user not found. should be found?
					/// @todo check if the user should be always found
					$retweeted_status["user"] = array();
				}

				$rt_converted = api_convert_item($retweeted_item);

				$retweeted_status['text'] = $rt_converted["text"];
				$retweeted_status['statusnet_html'] = $rt_converted["html"];
				$retweeted_status['friendica_activities'] = api_format_items_activities($retweeted_item, $type);
				$retweeted_status['created_at'] =  api_date($retweeted_item['created']);
				$status['retweeted_status'] = $retweeted_status;
			}
		}

		// "uid" and "self" are only needed for some internal stuff, so remove it from here
		unset($status["user"]["uid"]);
		unset($status["user"]["self"]);

		if ($item["coord"] != "") {
			$coords = explode(' ', $item["coord"]);
			if (count($coords) == 2) {
				if ($type == "json")
					$status["geo"] = array('type' => 'Point',
							'coordinates' => array((float) $coords[0],
										(float) $coords[1]));
				else // Not sure if this is the official format - if someone founds a documentation we can check
					$status["georss:point"] = $item["coord"];
			}
		}
		$ret[] = $status;
	};
	return $ret;
}

function api_account_rate_limit_status($type)
{
	if ($type == "xml") {
		$hash = array(
				'remaining-hits' => '150',
				'@attributes' => array("type" => "integer"),
				'hourly-limit' => '150',
				'@attributes2' => array("type" => "integer"),
				'reset-time' => datetime_convert('UTC', 'UTC', 'now + 1 hour', ATOM_TIME),
				'@attributes3' => array("type" => "datetime"),
				'reset_time_in_seconds' => strtotime('now + 1 hour'),
				'@attributes4' => array("type" => "integer"),
			);
	} else {
		$hash = array(
				'reset_time_in_seconds' => strtotime('now + 1 hour'),
				'remaining_hits' => '150',
				'hourly_limit' => '150',
				'reset_time' => api_date(datetime_convert('UTC', 'UTC', 'now + 1 hour', ATOM_TIME)),
			);
	}

	return api_format_data('hash', $type, array('hash' => $hash));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/account/rate_limit_status', 'api_account_rate_limit_status', true);

function api_help_test($type)
{
	if ($type == 'xml') {
		$ok = "true";
	} else {
		$ok = "ok";
	}

	return api_format_data('ok', $type, array("ok" => $ok));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/help/test', 'api_help_test', false);

function api_lists($type)
{
	$ret = array();
	/// @TODO $ret is not filled here?
	return api_format_data('lists', $type, array("lists_list" => $ret));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/lists', 'api_lists', true);

function api_lists_list($type)
{
	$ret = array();
	/// @TODO $ret is not filled here?
	return api_format_data('lists', $type, array("lists_list" => $ret));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/lists/list', 'api_lists_list', true);

/**
 * https://dev.twitter.com/docs/api/1/get/statuses/friends
 * This function is deprecated by Twitter
 * returns: json, xml
 */
function api_statuses_f($type, $qtype)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	if (x($_GET, 'cursor') && $_GET['cursor']=='undefined') {
		/* this is to stop Hotot to load friends multiple times
		*  I'm not sure if I'm missing return something or
		*  is a bug in hotot. Workaround, meantime
		*/

		/*$ret=Array();
		return array('$users' => $ret);*/
		return false;
	}

	if ($qtype == 'friends') {
		$sql_extra = sprintf(" AND ( `rel` = %d OR `rel` = %d ) ", intval(CONTACT_IS_SHARING), intval(CONTACT_IS_FRIEND));
	}
	if ($qtype == 'followers') {
		$sql_extra = sprintf(" AND ( `rel` = %d OR `rel` = %d ) ", intval(CONTACT_IS_FOLLOWER), intval(CONTACT_IS_FRIEND));
	}

	// friends and followers only for self
	if ($user_info['self'] == 0) {
		$sql_extra = " AND false ";
	}

	$r = q(
		"SELECT `nurl` FROM `contact` WHERE `uid` = %d AND NOT `self` AND (NOT `blocked` OR `pending`) $sql_extra ORDER BY `nick`",
		intval(api_user())
	);

	$ret = array();
	foreach ($r as $cid) {
		$user = api_get_user($a, $cid['nurl']);
		// "uid" and "self" are only needed for some internal stuff, so remove it from here
		unset($user["uid"]);
		unset($user["self"]);

		if ($user) {
			$ret[] = $user;
		}
	}

	return array('user' => $ret);

}

function api_statuses_friends($type)
{
	$data =  api_statuses_f($type, "friends");
	if ($data === false) {
		return false;
	}
	return api_format_data("users", $type, $data);
}

function api_statuses_followers($type)
{
	$data = api_statuses_f($type, "followers");
	if ($data === false) {
		return false;
	}
	return api_format_data("users", $type, $data);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/statuses/friends', 'api_statuses_friends', true);
api_register_func('api/statuses/followers', 'api_statuses_followers', true);

function api_statusnet_config($type)
{
	$a = get_app();

	$name = $a->config['sitename'];
	$server = $a->get_hostname();
	$logo = System::baseUrl() . '/images/friendica-64.png';
	$email = $a->config['admin_email'];
	$closed = (($a->config['register_policy'] == REGISTER_CLOSED) ? 'true' : 'false');
	$private = ((Config::get('system', 'block_public')) ? 'true' : 'false');
	$textlimit = (string) (($a->config['max_import_size']) ? $a->config['max_import_size'] : 200000);
	if ($a->config['api_import_size']) {
		$texlimit = string($a->config['api_import_size']);
	}
	$ssl = ((Config::get('system', 'have_ssl')) ? 'true' : 'false');
	$sslserver = (($ssl === 'true') ? str_replace('http:', 'https:', System::baseUrl()) : '');

	$config = array(
		'site' => array('name' => $name,'server' => $server, 'theme' => 'default', 'path' => '',
			'logo' => $logo, 'fancy' => true, 'language' => 'en', 'email' => $email, 'broughtby' => '',
			'broughtbyurl' => '', 'timezone' => 'UTC', 'closed' => $closed, 'inviteonly' => false,
			'private' => $private, 'textlimit' => $textlimit, 'sslserver' => $sslserver, 'ssl' => $ssl,
			'shorturllength' => '30',
			'friendica' => array(
					'FRIENDICA_PLATFORM' => FRIENDICA_PLATFORM,
					'FRIENDICA_VERSION' => FRIENDICA_VERSION,
					'DFRN_PROTOCOL_VERSION' => DFRN_PROTOCOL_VERSION,
					'DB_UPDATE_VERSION' => DB_UPDATE_VERSION
					)
		),
	);

	return api_format_data('config', $type, array('config' => $config));
}

/// @TODO move to top of file or somewhere better
api_register_func('api/gnusocial/config', 'api_statusnet_config', false);
api_register_func('api/statusnet/config', 'api_statusnet_config', false);

function api_statusnet_version($type)
{
	// liar
	$fake_statusnet_version = "0.9.7";

	return api_format_data('version', $type, array('version' => $fake_statusnet_version));
}

/// @TODO move to top of file or somewhere better
api_register_func('api/gnusocial/version', 'api_statusnet_version', false);
api_register_func('api/statusnet/version', 'api_statusnet_version', false);

/**
 * @todo use api_format_data() to return data
 */
function api_ff_ids($type,$qtype)
{
	$a = get_app();

	if (! api_user()) {
		throw new ForbiddenException();
	}

	$user_info = api_get_user($a);

	if ($qtype == 'friends') {
		$sql_extra = sprintf(" AND ( `rel` = %d OR `rel` = %d ) ", intval(CONTACT_IS_SHARING), intval(CONTACT_IS_FRIEND));
	}
	if ($qtype == 'followers') {
		$sql_extra = sprintf(" AND ( `rel` = %d OR `rel` = %d ) ", intval(CONTACT_IS_FOLLOWER), intval(CONTACT_IS_FRIEND));
	}

	if (!$user_info["self"]) {
		$sql_extra = " AND false ";
	}

	$stringify_ids = (x($_REQUEST, 'stringify_ids') ? $_REQUEST['stringify_ids'] : false);

	$r = q(
		"SELECT `pcontact`.`id` FROM `contact`
			INNER JOIN `contact` AS `pcontact` ON `contact`.`nurl` = `pcontact`.`nurl` AND `pcontact`.`uid` = 0
			WHERE `contact`.`uid` = %s AND NOT `contact`.`self`",
		intval(api_user())
	);

	if (!DBM::is_result($r)) {
		return;
	}

	$ids = array();
	foreach ($r as $rr) {
		if ($stringify_ids) {
			$ids[] = $rr['id'];
		} else {
			$ids[] = intval($rr['id']);
		}
	}

	return api_format_data("ids", $type, array('id' => $ids));
}

function api_friends_ids($type)
{
	return api_ff_ids($type, 'friends');
}

function api_followers_ids($type)
{
	return api_ff_ids($type, 'followers');
}

/// @TODO move to top of file or somewhere better
api_register_func('api/friends/ids', 'api_friends_ids', true);
api_register_func('api/followers/ids', 'api_followers_ids', true);

function api_direct_messages_new($type)
{

	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();

	if (!x($_POST, "text") || (!x($_POST, "screen_name") && !x($_POST, "user_id"))) return;

	$sender = api_get_user($a);

	if ($_POST['screen_name']) {
		$r = q(
			"SELECT `id`, `nurl`, `network` FROM `contact` WHERE `uid`=%d AND `nick`='%s'",
			intval(api_user()),
			dbesc($_POST['screen_name'])
		);

		// Selecting the id by priority, friendica first
		api_best_nickname($r);

		$recipient = api_get_user($a, $r[0]['nurl']);
	} else {
		$recipient = api_get_user($a, $_POST['user_id']);
	}

	$replyto = '';
	$sub     = '';
	if (x($_REQUEST, 'replyto')) {
		$r = q(
			'SELECT `parent-uri`, `title` FROM `mail` WHERE `uid`=%d AND `id`=%d',
			intval(api_user()),
			intval($_REQUEST['replyto'])
		);
		$replyto = $r[0]['parent-uri'];
		$sub     = $r[0]['title'];
	} else {
		if (x($_REQUEST, 'title')) {
			$sub = $_REQUEST['title'];
		} else {
			$sub = ((strlen($_POST['text'])>10) ? substr($_POST['text'], 0, 10)."...":$_POST['text']);
		}
	}

	$id = send_message($recipient['cid'], $_POST['text'], $sub, $replyto);

	if ($id > -1) {
		$r = q("SELECT * FROM `mail` WHERE id=%d", intval($id));
		$ret = api_format_messages($r[0], $recipient, $sender);
	} else {
		$ret = array("error"=>$id);
	}

	$data = array('direct_message'=>$ret);

	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
	}

	return api_format_data("direct-messages", $type, $data);

}

/// @TODO move to top of file or somewhere better
api_register_func('api/direct_messages/new', 'api_direct_messages_new', true, API_METHOD_POST);

/**
 * @brief delete a direct_message from mail table through api
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_direct_messages_destroy($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// params
	$user_info = api_get_user($a);
	//required
	$id = (x($_REQUEST, 'id') ? $_REQUEST['id'] : 0);
	// optional
	$parenturi = (x($_REQUEST, 'friendica_parenturi') ? $_REQUEST['friendica_parenturi'] : "");
	$verbose = (x($_GET, 'friendica_verbose') ? strtolower($_GET['friendica_verbose']) : "false");
	/// @todo optional parameter 'include_entities' from Twitter API not yet implemented

	$uid = $user_info['uid'];
	// error if no id or parenturi specified (for clients posting parent-uri as well)
	if ($verbose == "true" && ($id == 0 || $parenturi == "")) {
		$answer = array('result' => 'error', 'message' => 'message id or parenturi not specified');
		return api_format_data("direct_messages_delete", $type, array('$result' => $answer));
	}

	// BadRequestException if no id specified (for clients using Twitter API)
	if ($id == 0) {
		throw new BadRequestException('Message id not specified');
	}

	// add parent-uri to sql command if specified by calling app
	$sql_extra = ($parenturi != "" ? " AND `parent-uri` = '" . dbesc($parenturi) . "'" : "");

	// get data of the specified message id
	$r = q(
		"SELECT `id` FROM `mail` WHERE `uid` = %d AND `id` = %d" . $sql_extra,
		intval($uid),
		intval($id)
	);

	// error message if specified id is not in database
	if (!DBM::is_result($r)) {
		if ($verbose == "true") {
			$answer = array('result' => 'error', 'message' => 'message id not in database');
			return api_format_data("direct_messages_delete", $type, array('$result' => $answer));
		}
		/// @todo BadRequestException ok for Twitter API clients?
		throw new BadRequestException('message id not in database');
	}

	// delete message
	$result = q(
		"DELETE FROM `mail` WHERE `uid` = %d AND `id` = %d" . $sql_extra,
		intval($uid),
		intval($id)
	);

	if ($verbose == "true") {
		if ($result) {
			// return success
			$answer = array('result' => 'ok', 'message' => 'message deleted');
			return api_format_data("direct_message_delete", $type, array('$result' => $answer));
		} else {
			$answer = array('result' => 'error', 'message' => 'unknown error');
			return api_format_data("direct_messages_delete", $type, array('$result' => $answer));
		}
	}
	/// @todo return JSON data like Twitter API not yet implemented

}

/// @TODO move to top of file or somewhere better
api_register_func('api/direct_messages/destroy', 'api_direct_messages_destroy', true, API_METHOD_DELETE);

function api_direct_messages_box($type, $box, $verbose)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// params
	$count = (x($_GET, 'count') ? $_GET['count'] : 20);
	$page = (x($_REQUEST, 'page') ? $_REQUEST['page'] -1 : 0);
	if ($page < 0) {
		$page = 0;
	}

	$since_id = (x($_REQUEST, 'since_id') ? $_REQUEST['since_id'] : 0);
	$max_id = (x($_REQUEST, 'max_id') ? $_REQUEST['max_id'] : 0);

	$user_id = (x($_REQUEST, 'user_id') ? $_REQUEST['user_id'] : "");
	$screen_name = (x($_REQUEST, 'screen_name') ? $_REQUEST['screen_name'] : "");

	//  caller user info
	unset($_REQUEST["user_id"]);
	unset($_GET["user_id"]);

	unset($_REQUEST["screen_name"]);
	unset($_GET["screen_name"]);

	$user_info = api_get_user($a);
	$profile_url = $user_info["url"];

	// pagination
	$start = $page * $count;

	// filters
	if ($box=="sentbox") {
		$sql_extra = "`mail`.`from-url`='" . dbesc($profile_url) . "'";
	} elseif ($box == "conversation") {
		$sql_extra = "`mail`.`parent-uri`='" . dbesc($_GET["uri"])  . "'";
	} elseif ($box == "all") {
		$sql_extra = "true";
	} elseif ($box == "inbox") {
		$sql_extra = "`mail`.`from-url`!='" . dbesc($profile_url) . "'";
	}

	if ($max_id > 0) {
		$sql_extra .= ' AND `mail`.`id` <= ' . intval($max_id);
	}

	if ($user_id != "") {
		$sql_extra .= ' AND `mail`.`contact-id` = ' . intval($user_id);
	} elseif ($screen_name !="") {
		$sql_extra .= " AND `contact`.`nick` = '" . dbesc($screen_name). "'";
	}

	$r = q(
		"SELECT `mail`.*, `contact`.`nurl` AS `contact-url` FROM `mail`,`contact` WHERE `mail`.`contact-id` = `contact`.`id` AND `mail`.`uid`=%d AND $sql_extra AND `mail`.`id` > %d ORDER BY `mail`.`id` DESC LIMIT %d,%d",
		intval(api_user()),
		intval($since_id),
		intval($start),
		intval($count)
	);
	if ($verbose == "true" && !DBM::is_result($r)) {
		$answer = array('result' => 'error', 'message' => 'no mails available');
		return api_format_data("direct_messages_all", $type, array('$result' => $answer));
	}

	$ret = array();
	foreach ($r as $item) {
		if ($box == "inbox" || $item['from-url'] != $profile_url) {
			$recipient = $user_info;
			$sender = api_get_user($a, normalise_link($item['contact-url']));
		} elseif ($box == "sentbox" || $item['from-url'] == $profile_url) {
			$recipient = api_get_user($a, normalise_link($item['contact-url']));
			$sender = $user_info;
		}

		$ret[] = api_format_messages($item, $recipient, $sender);
	}


	$data = array('direct_message' => $ret);
	switch ($type) {
		case "atom":
		case "rss":
			$data = api_rss_extra($a, $data, $user_info);
	}

	return api_format_data("direct-messages", $type, $data);
}

function api_direct_messages_sentbox($type)
{
	$verbose = (x($_GET, 'friendica_verbose') ? strtolower($_GET['friendica_verbose']) : "false");
	return api_direct_messages_box($type, "sentbox", $verbose);
}

function api_direct_messages_inbox($type)
{
	$verbose = (x($_GET, 'friendica_verbose') ? strtolower($_GET['friendica_verbose']) : "false");
	return api_direct_messages_box($type, "inbox", $verbose);
}

function api_direct_messages_all($type)
{
	$verbose = (x($_GET, 'friendica_verbose') ? strtolower($_GET['friendica_verbose']) : "false");
	return api_direct_messages_box($type, "all", $verbose);
}

function api_direct_messages_conversation($type)
{
	$verbose = (x($_GET, 'friendica_verbose') ? strtolower($_GET['friendica_verbose']) : "false");
	return api_direct_messages_box($type, "conversation", $verbose);
}

/// @TODO move to top of file or somewhere better
api_register_func('api/direct_messages/conversation', 'api_direct_messages_conversation', true);
api_register_func('api/direct_messages/all', 'api_direct_messages_all', true);
api_register_func('api/direct_messages/sent', 'api_direct_messages_sentbox', true);
api_register_func('api/direct_messages', 'api_direct_messages_inbox', true);

function api_oauth_request_token($type)
{
	try {
		$oauth = new FKOAuth1();
		$r = $oauth->fetch_request_token(OAuthRequest::from_request());
	} catch (Exception $e) {
		echo "error=" . OAuthUtil::urlencode_rfc3986($e->getMessage());
		killme();
	}
	echo $r;
	killme();
}

function api_oauth_access_token($type)
{
	try {
		$oauth = new FKOAuth1();
		$r = $oauth->fetch_access_token(OAuthRequest::from_request());
	} catch (Exception $e) {
		echo "error=". OAuthUtil::urlencode_rfc3986($e->getMessage());
		killme();
	}
	echo $r;
	killme();
}

/// @TODO move to top of file or somewhere better
api_register_func('api/oauth/request_token', 'api_oauth_request_token', false);
api_register_func('api/oauth/access_token', 'api_oauth_access_token', false);


/**
 * @brief delete a complete photoalbum with all containing photos from database through api
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photoalbum_delete($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	// input params
	$album = (x($_REQUEST, 'album') ? $_REQUEST['album'] : "");

	// we do not allow calls without album string
	if ($album == "") {
		throw new BadRequestException("no albumname specified");
	}
	// check if album is existing
	$r = q(
		"SELECT DISTINCT `resource-id` FROM `photo` WHERE `uid` = %d AND `album` = '%s'",
		intval(api_user()),
		dbesc($album)
	);
	if (!DBM::is_result($r))
		throw new BadRequestException("album not available");

	// function for setting the items to "deleted = 1" which ensures that comments, likes etc. are not shown anymore
	// to the user and the contacts of the users (drop_items() performs the federation of the deletion to other networks
	foreach ($r as $rr) {
		$photo_item = q(
			"SELECT `id` FROM `item` WHERE `uid` = %d AND `resource-id` = '%s' AND `type` = 'photo'",
			intval(local_user()),
			dbesc($rr['resource-id'])
		);

		if (!DBM::is_result($photo_item)) {
			throw new InternalServerErrorException("problem with deleting items occured");
		}
		drop_item($photo_item[0]['id'], false);
	}

	// now let's delete all photos from the album
	$result = dba::delete('photo', array('uid' => api_user(), 'album' => $album));

	// return success of deletion or error message
	if ($result) {
		$answer = array('result' => 'deleted', 'message' => 'album `' . $album . '` with all containing photos has been deleted.');
		return api_format_data("photoalbum_delete", $type, array('$result' => $answer));
	} else {
		throw new InternalServerErrorException("unknown error - deleting from database failed");
	}
}

/**
 * @brief update the name of the album for all photos of an album
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photoalbum_update($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	// input params
	$album = (x($_REQUEST, 'album') ? $_REQUEST['album'] : "");
	$album_new = (x($_REQUEST, 'album_new') ? $_REQUEST['album_new'] : "");

	// we do not allow calls without album string
	if ($album == "") {
		throw new BadRequestException("no albumname specified");
	}
	if ($album_new == "") {
		throw new BadRequestException("no new albumname specified");
	}
	// check if album is existing
	$r = q(
		"SELECT `id` FROM `photo` WHERE `uid` = %d AND `album` = '%s'",
		intval(api_user()),
		dbesc($album)
	);
	if (!DBM::is_result($r)) {
		throw new BadRequestException("album not available");
	}
	// now let's update all photos to the albumname
	$result = q(
		"UPDATE `photo` SET `album` = '%s' WHERE `uid` = %d AND `album` = '%s'",
		dbesc($album_new),
		intval(api_user()),
		dbesc($album)
	);

	// return success of updating or error message
	if ($result) {
		$answer = array('result' => 'updated', 'message' => 'album `' . $album . '` with all containing photos has been renamed to `' . $album_new . '`.');
		return api_format_data("photoalbum_update", $type, array('$result' => $answer));
	} else {
		throw new InternalServerErrorException("unknown error - updating in database failed");
	}
}


/**
 * @brief list all photos of the authenticated user
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photos_list($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	$r = q(
		"SELECT `resource-id`, MAX(scale) AS `scale`, `album`, `filename`, `type`, MAX(`created`) AS `created`,
		MAX(`edited`) AS `edited`, MAX(`desc`) AS `desc` FROM `photo`
		WHERE `uid` = %d AND `album` != 'Contact Photos' GROUP BY `resource-id`",
		intval(local_user())
	);
	$typetoext = array(
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif'
	);
	$data = array('photo'=>array());
	if (DBM::is_result($r)) {
		foreach ($r as $rr) {
			$photo = array();
			$photo['id'] = $rr['resource-id'];
			$photo['album'] = $rr['album'];
			$photo['filename'] = $rr['filename'];
			$photo['type'] = $rr['type'];
			$thumb = System::baseUrl() . "/photo/" . $rr['resource-id'] . "-" . $rr['scale'] . "." . $typetoext[$rr['type']];
			$photo['created'] = $rr['created'];
			$photo['edited'] = $rr['edited'];
			$photo['desc'] = $rr['desc'];

			if ($type == "xml") {
				$data['photo'][] = array("@attributes" => $photo, "1" => $thumb);
			} else {
				$photo['thumb'] = $thumb;
				$data['photo'][] = $photo;
			}
		}
	}
	return api_format_data("photos", $type, $data);
}

/**
 * @brief upload a new photo or change an existing photo
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photo_create_update($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	// input params
	$photo_id = (x($_REQUEST, 'photo_id') ? $_REQUEST['photo_id'] : null);
	$desc = (x($_REQUEST, 'desc') ? $_REQUEST['desc'] : (array_key_exists('desc', $_REQUEST) ? "" : null)); // extra check necessary to distinguish between 'not provided' and 'empty string'
	$album = (x($_REQUEST, 'album') ? $_REQUEST['album'] : null);
	$album_new = (x($_REQUEST, 'album_new') ? $_REQUEST['album_new'] : null);
	$allow_cid = (x($_REQUEST, 'allow_cid') ? $_REQUEST['allow_cid'] : (array_key_exists('allow_cid', $_REQUEST) ? " " : null));
	$deny_cid = (x($_REQUEST, 'deny_cid') ? $_REQUEST['deny_cid'] : (array_key_exists('deny_cid', $_REQUEST) ? " " : null));
	$allow_gid = (x($_REQUEST, 'allow_gid') ? $_REQUEST['allow_gid'] : (array_key_exists('allow_gid', $_REQUEST) ? " " : null));
	$deny_gid = (x($_REQUEST, 'deny_gid') ? $_REQUEST['deny_gid'] : (array_key_exists('deny_gid', $_REQUEST) ? " " : null));
	$visibility = (x($_REQUEST, 'visibility') ? (($_REQUEST['visibility'] == "true" || $_REQUEST['visibility'] == 1) ? true : false) : false);

	// do several checks on input parameters
	// we do not allow calls without album string
	if ($album == null) {
		throw new BadRequestException("no albumname specified");
	}
	// if photo_id == null --> we are uploading a new photo
	if ($photo_id == null) {
		$mode = "create";

		// error if no media posted in create-mode
		if (!x($_FILES, 'media')) {
			// Output error
			throw new BadRequestException("no media data submitted");
		}

		// album_new will be ignored in create-mode
		$album_new = "";
	} else {
		$mode = "update";

		// check if photo is existing in database
		$r = q(
			"SELECT `id` FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s' AND `album` = '%s'",
			intval(api_user()),
			dbesc($photo_id),
			dbesc($album)
		);
		if (!DBM::is_result($r)) {
			throw new BadRequestException("photo not available");
		}
	}

	// checks on acl strings provided by clients
	$acl_input_error = false;
	$acl_input_error |= check_acl_input($allow_cid);
	$acl_input_error |= check_acl_input($deny_cid);
	$acl_input_error |= check_acl_input($allow_gid);
	$acl_input_error |= check_acl_input($deny_gid);
	if ($acl_input_error) {
		throw new BadRequestException("acl data invalid");
	}
	// now let's upload the new media in create-mode
	if ($mode == "create") {
		$media = $_FILES['media'];
		$data = save_media_to_database("photo", $media, $type, $album, trim($allow_cid), trim($deny_cid), trim($allow_gid), trim($deny_gid), $desc, $visibility);

		// return success of updating or error message
		if (!is_null($data)) {
			return api_format_data("photo_create", $type, $data);
		} else {
			throw new InternalServerErrorException("unknown error - uploading photo failed, see Friendica log for more information");
		}
	}

	// now let's do the changes in update-mode
	if ($mode == "update") {
		$sql_extra = "";

		if (!is_null($desc)) {
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`desc` = '$desc'";
		}

		if (!is_null($album_new)) {
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`album` = '$album_new'";
		}

		if (!is_null($allow_cid)) {
			$allow_cid = trim($allow_cid);
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`allow_cid` = '$allow_cid'";
		}

		if (!is_null($deny_cid)) {
			$deny_cid = trim($deny_cid);
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`deny_cid` = '$deny_cid'";
		}

		if (!is_null($allow_gid)) {
			$allow_gid = trim($allow_gid);
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`allow_gid` = '$allow_gid'";
		}

		if (!is_null($deny_gid)) {
			$deny_gid = trim($deny_gid);
			$sql_extra .= (($sql_extra != "") ? " ," : "") . "`deny_gid` = '$deny_gid'";
		}

		$result = false;
		if ($sql_extra != "") {
			$nothingtodo = false;
			$result = q(
				"UPDATE `photo` SET %s, `edited`='%s' WHERE `uid` = %d AND `resource-id` = '%s' AND `album` = '%s'",
				$sql_extra,
				datetime_convert(),   // update edited timestamp
				intval(api_user()),
				dbesc($photo_id),
				dbesc($album)
			);
		} else {
			$nothingtodo = true;
		}

		if (x($_FILES, 'media')) {
			$nothingtodo = false;
			$media = $_FILES['media'];
			$data = save_media_to_database("photo", $media, $type, $album, $allow_cid, $deny_cid, $allow_gid, $deny_gid, $desc, 0, $visibility, $photo_id);
			if (!is_null($data)) {
				return api_format_data("photo_update", $type, $data);
			}
		}

		// return success of updating or error message
		if ($result) {
			$answer = array('result' => 'updated', 'message' => 'Image id `' . $photo_id . '` has been updated.');
			return api_format_data("photo_update", $type, array('$result' => $answer));
		} else {
			if ($nothingtodo) {
				$answer = array('result' => 'cancelled', 'message' => 'Nothing to update for image id `' . $photo_id . '`.');
				return api_format_data("photo_update", $type, array('$result' => $answer));
			}
			throw new InternalServerErrorException("unknown error - update photo entry in database failed");
		}
	}
	throw new InternalServerErrorException("unknown error - this error on uploading or updating a photo should never happen");
}


/**
 * @brief delete a single photo from the database through api
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photo_delete($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	// input params
	$photo_id = (x($_REQUEST, 'photo_id') ? $_REQUEST['photo_id'] : null);

	// do several checks on input parameters
	// we do not allow calls without photo id
	if ($photo_id == null) {
		throw new BadRequestException("no photo_id specified");
	}
	// check if photo is existing in database
	$r = q(
		"SELECT `id` FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s'",
		intval(api_user()),
		dbesc($photo_id)
	);
	if (!DBM::is_result($r)) {
		throw new BadRequestException("photo not available");
	}
	// now we can perform on the deletion of the photo
	$result = dba::delete('photo', array('uid' => api_user(), 'resource-id' => $photo_id));

	// return success of deletion or error message
	if ($result) {
		// retrieve the id of the parent element (the photo element)
		$photo_item = q(
			"SELECT `id` FROM `item` WHERE `uid` = %d AND `resource-id` = '%s' AND `type` = 'photo'",
			intval(local_user()),
			dbesc($photo_id)
		);

		if (!DBM::is_result($photo_item)) {
			throw new InternalServerErrorException("problem with deleting items occured");
		}
		// function for setting the items to "deleted = 1" which ensures that comments, likes etc. are not shown anymore
		// to the user and the contacts of the users (drop_items() do all the necessary magic to avoid orphans in database and federate deletion)
		drop_item($photo_item[0]['id'], false);

		$answer = array('result' => 'deleted', 'message' => 'photo with id `' . $photo_id . '` has been deleted from server.');
		return api_format_data("photo_delete", $type, array('$result' => $answer));
	} else {
		throw new InternalServerErrorException("unknown error on deleting photo from database table");
	}
}


/**
 * @brief returns the details of a specified photo id, if scale is given, returns the photo data in base 64
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_fr_photo_detail($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	if (!x($_REQUEST, 'photo_id')) {
		throw new BadRequestException("No photo id.");
	}

	$scale = (x($_REQUEST, 'scale') ? intval($_REQUEST['scale']) : false);
	$photo_id = $_REQUEST['photo_id'];

	// prepare json/xml output with data from database for the requested photo
	$data = prepare_photo_data($type, $scale, $photo_id);

	return api_format_data("photo_detail", $type, $data);
}


/**
 * @brief updates the profile image for the user (either a specified profile or the default profile)
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_account_update_profile_image($type)
{
	if (api_user() === false) {
		throw new ForbiddenException();
	}
	// input params
	$profileid = (x($_REQUEST, 'profile_id') ? $_REQUEST['profile_id'] : 0);

	// error if image data is missing
	if (!x($_FILES, 'image')) {
		throw new BadRequestException("no media data submitted");
	}

	// check if specified profile id is valid
	if ($profileid != 0) {
		$r = q(
			"SELECT `id` FROM `profile` WHERE `uid` = %d AND `id` = %d",
			intval(api_user()),
			intval($profileid)
		);
		// error message if specified profile id is not in database
		if (!DBM::is_result($r)) {
			throw new BadRequestException("profile_id not available");
		}
		$is_default_profile = $r['profile'];
	} else {
		$is_default_profile = 1;
	}

	// get mediadata from image or media (Twitter call api/account/update_profile_image provides image)
	$media = null;
	if (x($_FILES, 'image')) {
		$media = $_FILES['image'];
	} elseif (x($_FILES, 'media')) {
		$media = $_FILES['media'];
	}
	// save new profile image
	$data = save_media_to_database("profileimage", $media, $type, t('Profile Photos'), "", "", "", "", "", $is_default_profile);

	// get filetype
	if (is_array($media['type'])) {
		$filetype = $media['type'][0];
	} else {
		$filetype = $media['type'];
	}
	if ($filetype == "image/jpeg") {
		$fileext = "jpg";
	} elseif ($filetype == "image/png") {
		$fileext = "png";
	}
	// change specified profile or all profiles to the new resource-id
	if ($is_default_profile) {
		$r = q(
			"UPDATE `photo` SET `profile` = 0 WHERE `profile` = 1 AND `resource-id` != '%s' AND `uid` = %d",
			dbesc($data['photo']['id']),
			intval(local_user())
		);

		$r = q(
			"UPDATE `contact` SET `photo` = '%s', `thumb` = '%s', `micro` = '%s'  WHERE `self` AND `uid` = %d",
			dbesc(System::baseUrl() . '/photo/' . $data['photo']['id'] . '-4.' . $fileext),
			dbesc(System::baseUrl() . '/photo/' . $data['photo']['id'] . '-5.' . $fileext),
			dbesc(System::baseUrl() . '/photo/' . $data['photo']['id'] . '-6.' . $fileext),
			intval(local_user())
		);
	} else {
		$r = q(
			"UPDATE `profile` SET `photo` = '%s', `thumb` = '%s' WHERE `id` = %d AND `uid` = %d",
			dbesc(System::baseUrl() . '/photo/' . $data['photo']['id'] . '-4.' . $filetype),
			dbesc(System::baseUrl() . '/photo/' . $data['photo']['id'] . '-5.' . $filetype),
			intval($_REQUEST['profile']),
			intval(local_user())
		);
	}

	// we'll set the updated profile-photo timestamp even if it isn't the default profile,
	// so that browsers will do a cache update unconditionally

	$r = q(
		"UPDATE `contact` SET `avatar-date` = '%s' WHERE `self` = 1 AND `uid` = %d",
		dbesc(datetime_convert()),
		intval(local_user())
	);

	// Update global directory in background
	//$user = api_get_user(get_app());
	$url = System::baseUrl() . '/profile/' . get_app()->user['nickname'];
	if ($url && strlen(Config::get('system', 'directory'))) {
		Worker::add(PRIORITY_LOW, "Directory", $url);
	}

	Worker::add(PRIORITY_LOW, 'ProfileUpdate', api_user());

	// output for client
	if ($data) {
		return api_account_verify_credentials($type);
	} else {
		// SaveMediaToDatabase failed for some reason
		throw new InternalServerErrorException("image upload failed");
	}
}

// place api-register for photoalbum calls before 'api/friendica/photo', otherwise this function is never reached
api_register_func('api/friendica/photoalbum/delete', 'api_fr_photoalbum_delete', true, API_METHOD_DELETE);
api_register_func('api/friendica/photoalbum/update', 'api_fr_photoalbum_update', true, API_METHOD_POST);
api_register_func('api/friendica/photos/list', 'api_fr_photos_list', true);
api_register_func('api/friendica/photo/create', 'api_fr_photo_create_update', true, API_METHOD_POST);
api_register_func('api/friendica/photo/update', 'api_fr_photo_create_update', true, API_METHOD_POST);
api_register_func('api/friendica/photo/delete', 'api_fr_photo_delete', true, API_METHOD_DELETE);
api_register_func('api/friendica/photo', 'api_fr_photo_detail', true);
api_register_func('api/account/update_profile_image', 'api_account_update_profile_image', true, API_METHOD_POST);


function check_acl_input($acl_string)
{
	if ($acl_string == null || $acl_string == " ") {
		return false;
	}
	$contact_not_found = false;

	// split <x><y><z> into array of cid's
	preg_match_all("/<[A-Za-z0-9]+>/", $acl_string, $array);

	// check for each cid if it is available on server
	$cid_array = $array[0];
	foreach ($cid_array as $cid) {
		$cid = str_replace("<", "", $cid);
		$cid = str_replace(">", "", $cid);
		$contact = q(
			"SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d",
			intval($cid),
			intval(api_user())
		);
		$contact_not_found |= !DBM::is_result($contact);
	}
	return $contact_not_found;
}

function save_media_to_database($mediatype, $media, $type, $album, $allow_cid, $deny_cid, $allow_gid, $deny_gid, $desc, $profile = 0, $visibility = false, $photo_id = null)
{
	$visitor   = 0;
	$src = "";
	$filetype = "";
	$filename = "";
	$filesize = 0;

	if (is_array($media)) {
		if (is_array($media['tmp_name'])) {
			$src = $media['tmp_name'][0];
		} else {
			$src = $media['tmp_name'];
		}
		if (is_array($media['name'])) {
			$filename = basename($media['name'][0]);
		} else {
			$filename = basename($media['name']);
		}
		if (is_array($media['size'])) {
			$filesize = intval($media['size'][0]);
		} else {
			$filesize = intval($media['size']);
		}
		if (is_array($media['type'])) {
			$filetype = $media['type'][0];
		} else {
			$filetype = $media['type'];
		}
	}

	if ($filetype == "") {
		$filetype=Photo::guessImageType($filename);
	}
	$imagedata = getimagesize($src);
	if ($imagedata) {
		$filetype = $imagedata['mime'];
	}
	logger(
		"File upload src: " . $src . " - filename: " . $filename .
		" - size: " . $filesize . " - type: " . $filetype, LOGGER_DEBUG
	);

	// check if there was a php upload error
	if ($filesize == 0 && $media['error'] == 1) {
		throw new InternalServerErrorException("image size exceeds PHP config settings, file was rejected by server");
	}
	// check against max upload size within Friendica instance
	$maximagesize = Config::get('system', 'maximagesize');
	if (($maximagesize) && ($filesize > $maximagesize)) {
		$formattedBytes = formatBytes($maximagesize);
		throw new InternalServerErrorException("image size exceeds Friendica config setting (uploaded size: $formattedBytes)");
	}

	// create Photo instance with the data of the image
	$imagedata = @file_get_contents($src);
	$ph = new Photo($imagedata, $filetype);
	if (! $ph->isValid()) {
		throw new InternalServerErrorException("unable to process image data");
	}

	// check orientation of image
	$ph->orient($src);
	@unlink($src);

	// check max length of images on server
	$max_length = Config::get('system', 'max_image_length');
	if (! $max_length) {
		$max_length = MAX_IMAGE_LENGTH;
	}
	if ($max_length > 0) {
		$ph->scaleImage($max_length);
		logger("File upload: Scaling picture to new size " . $max_length, LOGGER_DEBUG);
	}
	$width = $ph->getWidth();
	$height = $ph->getHeight();

	// create a new resource-id if not already provided
	$hash = ($photo_id == null) ? photo_new_resource() : $photo_id;

	if ($mediatype == "photo") {
		// upload normal image (scales 0, 1, 2)
		logger("photo upload: starting new photo upload", LOGGER_DEBUG);

		$r =$ph->store(local_user(), $visitor, $hash, $filename, $album, 0, 0, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
		if (! $r) {
			logger("photo upload: image upload with scale 0 (original size) failed");
		}
		if ($width > 640 || $height > 640) {
			$ph->scaleImage(640);
			$r = $ph->store(local_user(), $visitor, $hash, $filename, $album, 1, 0, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
			if (! $r) {
				logger("photo upload: image upload with scale 1 (640x640) failed");
			}
		}

		if ($width > 320 || $height > 320) {
			$ph->scaleImage(320);
			$r = $ph->store(local_user(), $visitor, $hash, $filename, $album, 2, 0, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
			if (! $r) {
				logger("photo upload: image upload with scale 2 (320x320) failed");
			}
		}
		logger("photo upload: new photo upload ended", LOGGER_DEBUG);
	} elseif ($mediatype == "profileimage") {
		// upload profile image (scales 4, 5, 6)
		logger("photo upload: starting new profile image upload", LOGGER_DEBUG);

		if ($width > 175 || $height > 175) {
			$ph->scaleImage(175);
			$r = $ph->store(local_user(), $visitor, $hash, $filename, $album, 4, $profile, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
			if (! $r) {
				logger("photo upload: profile image upload with scale 4 (175x175) failed");
			}
		}

		if ($width > 80 || $height > 80) {
			$ph->scaleImage(80);
			$r = $ph->store(local_user(), $visitor, $hash, $filename, $album, 5, $profile, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
			if (! $r) {
				logger("photo upload: profile image upload with scale 5 (80x80) failed");
			}
		}

		if ($width > 48 || $height > 48) {
			$ph->scaleImage(48);
			$r = $ph->store(local_user(), $visitor, $hash, $filename, $album, 6, $profile, $allow_cid, $allow_gid, $deny_cid, $deny_gid, $desc);
			if (! $r) {
				logger("photo upload: profile image upload with scale 6 (48x48) failed");
			}
		}
		$ph->__destruct();
		logger("photo upload: new profile image upload ended", LOGGER_DEBUG);
	}

	if ($r) {
		// create entry in 'item'-table on new uploads to enable users to comment/like/dislike the photo
		if ($photo_id == null && $mediatype == "photo") {
			post_photo_item($hash, $allow_cid, $deny_cid, $allow_gid, $deny_gid, $filetype, $visibility);
		}
		// on success return image data in json/xml format (like /api/friendica/photo does when no scale is given)
		return prepare_photo_data($type, false, $hash);
	} else {
		throw new InternalServerErrorException("image upload failed");
	}
}

function post_photo_item($hash, $allow_cid, $deny_cid, $allow_gid, $deny_gid, $filetype, $visibility = false)
{
	// get data about the api authenticated user
	$uri = item_new_uri(get_app()->get_hostname(), intval(api_user()));
	$owner_record = q("SELECT * FROM `contact` WHERE `uid`= %d AND `self` LIMIT 1", intval(api_user()));

	$arr = array();
	$arr['guid']          = get_guid(32);
	$arr['uid']           = intval(api_user());
	$arr['uri']           = $uri;
	$arr['parent-uri']    = $uri;
	$arr['type']          = 'photo';
	$arr['wall']          = 1;
	$arr['resource-id']   = $hash;
	$arr['contact-id']    = $owner_record[0]['id'];
	$arr['owner-name']    = $owner_record[0]['name'];
	$arr['owner-link']    = $owner_record[0]['url'];
	$arr['owner-avatar']  = $owner_record[0]['thumb'];
	$arr['author-name']   = $owner_record[0]['name'];
	$arr['author-link']   = $owner_record[0]['url'];
	$arr['author-avatar'] = $owner_record[0]['thumb'];
	$arr['title']         = "";
	$arr['allow_cid']     = $allow_cid;
	$arr['allow_gid']     = $allow_gid;
	$arr['deny_cid']      = $deny_cid;
	$arr['deny_gid']      = $deny_gid;
	$arr['last-child']    = 1;
	$arr['visible']       = $visibility;
	$arr['origin']        = 1;

	$typetoext = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif'
			);

	// adds link to the thumbnail scale photo
	$arr['body'] = '[url=' . System::baseUrl() . '/photos/' . $owner_record[0]['nick'] . '/image/' . $hash . ']'
				. '[img]' . System::baseUrl() . '/photo/' . $hash . '-' . "2" . '.'. $typetoext[$filetype] . '[/img]'
				. '[/url]';

	// do the magic for storing the item in the database and trigger the federation to other contacts
	item_store($arr);
}

function prepare_photo_data($type, $scale, $photo_id)
{
	$scale_sql = ($scale === false ? "" : sprintf("AND scale=%d", intval($scale)));
	$data_sql = ($scale === false ? "" : "data, ");

	// added allow_cid, allow_gid, deny_cid, deny_gid to output as string like stored in database
	// clients needs to convert this in their way for further processing
	$r = q(
		"SELECT %s `resource-id`, `created`, `edited`, `title`, `desc`, `album`, `filename`,
					`type`, `height`, `width`, `datasize`, `profile`, `allow_cid`, `deny_cid`, `allow_gid`, `deny_gid`,
					MIN(`scale`) AS `minscale`, MAX(`scale`) AS `maxscale`
			FROM `photo` WHERE `uid` = %d AND `resource-id` = '%s' %s GROUP BY `resource-id`",
		$data_sql,
		intval(local_user()),
		dbesc($photo_id),
		$scale_sql
	);

	$typetoext = array(
		'image/jpeg' => 'jpg',
		'image/png' => 'png',
		'image/gif' => 'gif'
	);

	// prepare output data for photo
	if (DBM::is_result($r)) {
		$data = array('photo' => $r[0]);
		$data['photo']['id'] = $data['photo']['resource-id'];
		if ($scale !== false) {
			$data['photo']['data'] = base64_encode($data['photo']['data']);
		} else {
			unset($data['photo']['datasize']); //needed only with scale param
		}
		if ($type == "xml") {
			$data['photo']['links'] = array();
			for ($k = intval($data['photo']['minscale']); $k <= intval($data['photo']['maxscale']); $k++) {
				$data['photo']['links'][$k . ":link"]["@attributes"] = array("type" => $data['photo']['type'],
										"scale" => $k,
										"href" => System::baseUrl() . "/photo/" . $data['photo']['resource-id'] . "-" . $k . "." . $typetoext[$data['photo']['type']]);
			}
		} else {
			$data['photo']['link'] = array();
			// when we have profile images we could have only scales from 4 to 6, but index of array always needs to start with 0
			$i = 0;
			for ($k = intval($data['photo']['minscale']); $k <= intval($data['photo']['maxscale']); $k++) {
				$data['photo']['link'][$i] = System::baseUrl() . "/photo/" . $data['photo']['resource-id'] . "-" . $k . "." . $typetoext[$data['photo']['type']];
				$i++;
			}
		}
		unset($data['photo']['resource-id']);
		unset($data['photo']['minscale']);
		unset($data['photo']['maxscale']);
	} else {
		throw new NotFoundException();
	}

	// retrieve item element for getting activities (like, dislike etc.) related to photo
	$item = q(
		"SELECT * FROM `item` WHERE `uid` = %d AND `resource-id` = '%s' AND `type` = 'photo'",
		intval(local_user()),
		dbesc($photo_id)
	);
	$data['photo']['friendica_activities'] = api_format_items_activities($item[0], $type);

	// retrieve comments on photo
	$r = q(
		"SELECT `item`.*, `item`.`id` AS `item_id`, `item`.`network` AS `item_network`,
		`contact`.`name`, `contact`.`photo`, `contact`.`url`, `contact`.`rel`,
		`contact`.`network`, `contact`.`thumb`, `contact`.`dfrn-id`, `contact`.`self`,
		`contact`.`id` AS `cid`
		FROM `item`
		STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`contact-id` AND `contact`.`uid` = `item`.`uid`
			AND (NOT `contact`.`blocked` OR `contact`.`pending`)
		WHERE `item`.`parent` = %d AND `item`.`visible`
		AND NOT `item`.`moderated` AND NOT `item`.`deleted`
		AND `item`.`uid` = %d AND (`item`.`verb`='%s' OR `type`='photo')",
		intval($item[0]['parent']),
		intval(api_user()),
		dbesc(ACTIVITY_POST)
	);

	// prepare output of comments
	$commentData = api_format_items($r, api_get_user(get_app()), false, $type);
	$comments = array();
	if ($type == "xml") {
		$k = 0;
		foreach ($commentData as $comment) {
			$comments[$k++ . ":comment"] = $comment;
		}
	} else {
		foreach ($commentData as $comment) {
			$comments[] = $comment;
		}
	}
	$data['photo']['friendica_comments'] = $comments;

	// include info if rights on photo and rights on item are mismatching
	$rights_mismatch = $data['photo']['allow_cid'] != $item[0]['allow_cid'] ||
		$data['photo']['deny_cid'] != $item[0]['deny_cid'] ||
		$data['photo']['allow_gid'] != $item[0]['allow_gid'] ||
		$data['photo']['deny_cid'] != $item[0]['deny_cid'];
	$data['photo']['rights_mismatch'] = $rights_mismatch;

	return $data;
}


/**
 * Similar as /mod/redir.php
 * redirect to 'url' after dfrn auth
 *
 * Why this when there is mod/redir.php already?
 * This use api_user() and api_login()
 *
 * params
 * 		c_url: url of remote contact to auth to
 * 		url: string, url to redirect after auth
 */
function api_friendica_remoteauth()
{
	$url = ((x($_GET, 'url')) ? $_GET['url'] : '');
	$c_url = ((x($_GET, 'c_url')) ? $_GET['c_url'] : '');

	if ($url === '' || $c_url === '') {
		throw new BadRequestException("Wrong parameters.");
	}

	$c_url = normalise_link($c_url);

	// traditional DFRN

	$r = q(
		"SELECT * FROM `contact` WHERE `id` = %d AND `nurl` = '%s' LIMIT 1",
		dbesc($c_url),
		intval(api_user())
	);

	if ((! DBM::is_result($r)) || ($r[0]['network'] !== NETWORK_DFRN)) {
		throw new BadRequestException("Unknown contact");
	}

	$cid = $r[0]['id'];

	$dfrn_id = $orig_id = (($r[0]['issued-id']) ? $r[0]['issued-id'] : $r[0]['dfrn-id']);

	if ($r[0]['duplex'] && $r[0]['issued-id']) {
		$orig_id = $r[0]['issued-id'];
		$dfrn_id = '1:' . $orig_id;
	}
	if ($r[0]['duplex'] && $r[0]['dfrn-id']) {
		$orig_id = $r[0]['dfrn-id'];
		$dfrn_id = '0:' . $orig_id;
	}

	$sec = random_string();

	q(
		"INSERT INTO `profile_check` ( `uid`, `cid`, `dfrn_id`, `sec`, `expire`)
		VALUES( %d, %s, '%s', '%s', %d )",
		intval(api_user()),
		intval($cid),
		dbesc($dfrn_id),
		dbesc($sec),
		intval(time() + 45)
	);

	logger($r[0]['name'] . ' ' . $sec, LOGGER_DEBUG);
	$dest = (($url) ? '&destination_url=' . $url : '');
	goaway(
		$r[0]['poll'] . '?dfrn_id=' . $dfrn_id
		. '&dfrn_version=' . DFRN_PROTOCOL_VERSION
		. '&type=profile&sec=' . $sec . $dest . $quiet
	);
}
api_register_func('api/friendica/remoteauth', 'api_friendica_remoteauth', true);

/**
 * @brief Return the item shared, if the item contains only the [share] tag
 *
 * @param array $item Sharer item
 * @return array Shared item or false if not a reshare
 */
function api_share_as_retweet(&$item)
{
	$body = trim($item["body"]);

	if (Diaspora::isReshare($body, false)===false) {
		return false;
	}

	/// @TODO "$1" should maybe mean '$1' ?
	$attributes = preg_replace("/\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "$1", $body);
	/*
		* Skip if there is no shared message in there
		* we already checked this in diaspora::isReshare()
		* but better one more than one less...
		*/
	if ($body == $attributes) {
		return false;
	}


	// build the fake reshared item
	$reshared_item = $item;

	$author = "";
	preg_match("/author='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "") {
		$author = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
	}

	preg_match('/author="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "") {
		$author = $matches[1];
	}

	$profile = "";
	preg_match("/profile='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "") {
		$profile = $matches[1];
	}

	preg_match('/profile="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "") {
		$profile = $matches[1];
	}

	$avatar = "";
	preg_match("/avatar='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "") {
		$avatar = $matches[1];
	}

	preg_match('/avatar="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "") {
		$avatar = $matches[1];
	}

	$link = "";
	preg_match("/link='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "") {
		$link = $matches[1];
	}

	preg_match('/link="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "") {
		$link = $matches[1];
	}

	$posted = "";
	preg_match("/posted='(.*?)'/ism", $attributes, $matches);
	if ($matches[1] != "")
		$posted = $matches[1];

	preg_match('/posted="(.*?)"/ism', $attributes, $matches);
	if ($matches[1] != "") {
		$posted = $matches[1];
	}

	$shared_body = preg_replace("/\[share(.*?)\]\s?(.*?)\s?\[\/share\]\s?/ism", "$2", $body);

	if (($shared_body == "") || ($profile == "") || ($author == "") || ($avatar == "") || ($posted == "")) {
		return false;
	}

	$reshared_item["body"] = $shared_body;
	$reshared_item["author-name"] = $author;
	$reshared_item["author-link"] = $profile;
	$reshared_item["author-avatar"] = $avatar;
	$reshared_item["plink"] = $link;
	$reshared_item["created"] = $posted;
	$reshared_item["edited"] = $posted;

	return $reshared_item;

}

function api_get_nick($profile)
{
	/* To-Do:
		- remove trailing junk from profile url
		- pump.io check has to check the website
	*/

	$nick = "";

	$r = q(
		"SELECT `nick` FROM `contact` WHERE `uid` = 0 AND `nurl` = '%s'",
		dbesc(normalise_link($profile))
	);

	if (DBM::is_result($r)) {
		$nick = $r[0]["nick"];
	}

	if (!$nick == "") {
		$r = q(
			"SELECT `nick` FROM `contact` WHERE `uid` = 0 AND `nurl` = '%s'",
			dbesc(normalise_link($profile))
		);

		if (DBM::is_result($r)) {
			$nick = $r[0]["nick"];
		}
	}

	if (!$nick == "") {
		$friendica = preg_replace("=https?://(.*)/profile/(.*)=ism", "$2", $profile);
		if ($friendica != $profile) {
			$nick = $friendica;
		}
	}

	if (!$nick == "") {
		$diaspora = preg_replace("=https?://(.*)/u/(.*)=ism", "$2", $profile);
		if ($diaspora != $profile) {
			$nick = $diaspora;
		}
	}

	if (!$nick == "") {
		$twitter = preg_replace("=https?://twitter.com/(.*)=ism", "$1", $profile);
		if ($twitter != $profile) {
			$nick = $twitter;
		}
	}


	if (!$nick == "") {
		$StatusnetHost = preg_replace("=https?://(.*)/user/(.*)=ism", "$1", $profile);
		if ($StatusnetHost != $profile) {
			$StatusnetUser = preg_replace("=https?://(.*)/user/(.*)=ism", "$2", $profile);
			if ($StatusnetUser != $profile) {
				$UserData = fetch_url("http://".$StatusnetHost."/api/users/show.json?user_id=".$StatusnetUser);
				$user = json_decode($UserData);
				if ($user) {
					$nick = $user->screen_name;
				}
			}
		}
	}

	// To-Do: look at the page if its really a pumpio site
	//if (!$nick == "") {
	//	$pumpio = preg_replace("=https?://(.*)/(.*)/=ism", "$2", $profile."/");
	//	if ($pumpio != $profile)
	//		$nick = $pumpio;
		//      <div class="media" id="profile-block" data-profile-id="acct:kabniel@microca.st">

	//}

	if ($nick != "") {
		return $nick;
	}

	return false;
}

function api_in_reply_to($item)
{
	$in_reply_to = array();

	$in_reply_to['status_id'] = null;
	$in_reply_to['user_id'] = null;
	$in_reply_to['status_id_str'] = null;
	$in_reply_to['user_id_str'] = null;
	$in_reply_to['screen_name'] = null;

	if (($item['thr-parent'] != $item['uri']) && (intval($item['parent']) != intval($item['id']))) {
		$r = q("SELECT `id` FROM `item` WHERE `uid` = %d AND `uri` = '%s' LIMIT 1",
			intval($item['uid']),
			dbesc($item['thr-parent']));

		if (DBM::is_result($r)) {
			$in_reply_to['status_id'] = intval($r[0]['id']);
		} else {
			$in_reply_to['status_id'] = intval($item['parent']);
		}

		$in_reply_to['status_id_str'] = (string) intval($in_reply_to['status_id']);

		$r = q("SELECT `contact`.`nick`, `contact`.`name`, `contact`.`id`, `contact`.`url` FROM item
			STRAIGHT_JOIN `contact` ON `contact`.`id` = `item`.`author-id`
			WHERE `item`.`id` = %d LIMIT 1",
			intval($in_reply_to['status_id'])
		);

		if (DBM::is_result($r)) {
			if ($r[0]['nick'] == "") {
				$r[0]['nick'] = api_get_nick($r[0]["url"]);
			}

			$in_reply_to['screen_name'] = (($r[0]['nick']) ? $r[0]['nick'] : $r[0]['name']);
			$in_reply_to['user_id'] = intval($r[0]['id']);
			$in_reply_to['user_id_str'] = (string) intval($r[0]['id']);
		}

		// There seems to be situation, where both fields are identical:
		// https://github.com/friendica/friendica/issues/1010
		// This is a bugfix for that.
		if (intval($in_reply_to['status_id']) == intval($item['id'])) {
			logger('this message should never appear: id: '.$item['id'].' similar to reply-to: '.$in_reply_to['status_id'], LOGGER_DEBUG);
			$in_reply_to['status_id'] = null;
			$in_reply_to['user_id'] = null;
			$in_reply_to['status_id_str'] = null;
			$in_reply_to['user_id_str'] = null;
			$in_reply_to['screen_name'] = null;
		}
	}

	return $in_reply_to;
}

function api_clean_plain_items($Text)
{
	$include_entities = strtolower(x($_REQUEST, 'include_entities') ? $_REQUEST['include_entities'] : "false");

	$Text = bb_CleanPictureLinks($Text);
	$URLSearchString = "^\[\]";

	$Text = preg_replace("/([!#@])\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '$1$3', $Text);

	if ($include_entities == "true") {
		$Text = preg_replace("/\[url\=([$URLSearchString]*)\](.*?)\[\/url\]/ism", '[url=$1]$1[/url]', $Text);
	}

	// Simplify "attachment" element
	$Text = api_clean_attachments($Text);

	return($Text);
}

/**
 * @brief Removes most sharing information for API text export
 *
 * @param string $body The original body
 *
 * @return string Cleaned body
 */
function api_clean_attachments($body)
{
	$data = get_attachment_data($body);

	if (!$data)
		return $body;

	$body = "";

	if (isset($data["text"]))
		$body = $data["text"];

	if (($body == "") && (isset($data["title"])))
		$body = $data["title"];

	if (isset($data["url"]))
		$body .= "\n".$data["url"];

	$body .= $data["after"];

	return $body;
}

function api_best_nickname(&$contacts)
{
	$best_contact = array();

	if (count($contact) == 0)
		return;

	foreach ($contacts as $contact)
		if ($contact["network"] == "") {
			$contact["network"] = "dfrn";
			$best_contact = array($contact);
		}

	if (sizeof($best_contact) == 0)
		foreach ($contacts as $contact)
			if ($contact["network"] == "dfrn")
				$best_contact = array($contact);

	if (sizeof($best_contact) == 0)
		foreach ($contacts as $contact)
			if ($contact["network"] == "dspr")
				$best_contact = array($contact);

	if (sizeof($best_contact) == 0)
		foreach ($contacts as $contact)
			if ($contact["network"] == "stat")
				$best_contact = array($contact);

	if (sizeof($best_contact) == 0)
		foreach ($contacts as $contact)
			if ($contact["network"] == "pump")
				$best_contact = array($contact);

	if (sizeof($best_contact) == 0)
		foreach ($contacts as $contact)
			if ($contact["network"] == "twit")
				$best_contact = array($contact);

	if (sizeof($best_contact) == 1) {
		$contacts = $best_contact;
	} else {
		$contacts = array($contacts[0]);
	}
}

// return all or a specified group of the user with the containing contacts
function api_friendica_group_show($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();

	// params
	$user_info = api_get_user($a);
	$gid = (x($_REQUEST, 'gid') ? $_REQUEST['gid'] : 0);
	$uid = $user_info['uid'];

	// get data of the specified group id or all groups if not specified
	if ($gid != 0) {
		$r = q(
			"SELECT * FROM `group` WHERE `deleted` = 0 AND `uid` = %d AND `id` = %d",
			intval($uid),
			intval($gid)
		);
		// error message if specified gid is not in database
		if (!DBM::is_result($r))
			throw new BadRequestException("gid not available");
	} else {
		$r = q(
			"SELECT * FROM `group` WHERE `deleted` = 0 AND `uid` = %d",
			intval($uid)
		);
	}

	// loop through all groups and retrieve all members for adding data in the user array
	foreach ($r as $rr) {
		$members = group_get_members($rr['id']);
		$users = array();

		if ($type == "xml") {
			$user_element = "users";
			$k = 0;
			foreach ($members as $member) {
				$user = api_get_user($a, $member['nurl']);
				$users[$k++.":user"] = $user;
			}
		} else {
			$user_element = "user";
			foreach ($members as $member) {
				$user = api_get_user($a, $member['nurl']);
				$users[] = $user;
			}
		}
		$grps[] = array('name' => $rr['name'], 'gid' => $rr['id'], $user_element => $users);
	}
	return api_format_data("groups", $type, array('group' => $grps));
}
api_register_func('api/friendica/group_show', 'api_friendica_group_show', true);


// delete the specified group of the user
function api_friendica_group_delete($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();

	// params
	$user_info = api_get_user($a);
	$gid = (x($_REQUEST, 'gid') ? $_REQUEST['gid'] : 0);
	$name = (x($_REQUEST, 'name') ? $_REQUEST['name'] : "");
	$uid = $user_info['uid'];

	// error if no gid specified
	if ($gid == 0 || $name == "")
		throw new BadRequestException('gid or name not specified');

	// get data of the specified group id
	$r = q(
		"SELECT * FROM `group` WHERE `uid` = %d AND `id` = %d",
		intval($uid),
		intval($gid)
	);
	// error message if specified gid is not in database
	if (!DBM::is_result($r))
		throw new BadRequestException('gid not available');

	// get data of the specified group id and group name
	$rname = q(
		"SELECT * FROM `group` WHERE `uid` = %d AND `id` = %d AND `name` = '%s'",
		intval($uid),
		intval($gid),
		dbesc($name)
	);
	// error message if specified gid is not in database
	if (!DBM::is_result($rname))
		throw new BadRequestException('wrong group name');

	// delete group
	$ret = group_rmv($uid, $name);
	if ($ret) {
		// return success
		$success = array('success' => $ret, 'gid' => $gid, 'name' => $name, 'status' => 'deleted', 'wrong users' => array());
		return api_format_data("group_delete", $type, array('result' => $success));
	} else {
		throw new BadRequestException('other API error');
	}
}
api_register_func('api/friendica/group_delete', 'api_friendica_group_delete', true, API_METHOD_DELETE);


// create the specified group with the posted array of contacts
function api_friendica_group_create($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();

	// params
	$user_info = api_get_user($a);
	$name = (x($_REQUEST, 'name') ? $_REQUEST['name'] : "");
	$uid = $user_info['uid'];
	$json = json_decode($_POST['json'], true);
	$users = $json['user'];

	// error if no name specified
	if ($name == "")
		throw new BadRequestException('group name not specified');

	// get data of the specified group name
	$rname = q(
		"SELECT * FROM `group` WHERE `uid` = %d AND `name` = '%s' AND `deleted` = 0",
		intval($uid),
		dbesc($name)
	);
	// error message if specified group name already exists
	if (DBM::is_result($rname))
		throw new BadRequestException('group name already exists');

	// check if specified group name is a deleted group
	$rname = q(
		"SELECT * FROM `group` WHERE `uid` = %d AND `name` = '%s' AND `deleted` = 1",
		intval($uid),
		dbesc($name)
	);
	// error message if specified group name already exists
	if (DBM::is_result($rname))
		$reactivate_group = true;

	// create group
	$ret = group_add($uid, $name);
	if ($ret) {
		$gid = group_byname($uid, $name);
	} else {
		throw new BadRequestException('other API error');
	}

	// add members
	$erroraddinguser = false;
	$errorusers = array();
	foreach ($users as $user) {
		$cid = $user['cid'];
		// check if user really exists as contact
		$contact = q(
			"SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d",
			intval($cid),
			intval($uid)
		);
		if (count($contact))
			$result = group_add_member($uid, $name, $cid, $gid);
		else {
			$erroraddinguser = true;
			$errorusers[] = $cid;
		}
	}

	// return success message incl. missing users in array
	$status = ($erroraddinguser ? "missing user" : ($reactivate_group ? "reactivated" : "ok"));
	$success = array('success' => true, 'gid' => $gid, 'name' => $name, 'status' => $status, 'wrong users' => $errorusers);
	return api_format_data("group_create", $type, array('result' => $success));
}
api_register_func('api/friendica/group_create', 'api_friendica_group_create', true, API_METHOD_POST);


// update the specified group with the posted array of contacts
function api_friendica_group_update($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();

	// params
	$user_info = api_get_user($a);
	$uid = $user_info['uid'];
	$gid = (x($_REQUEST, 'gid') ? $_REQUEST['gid'] : 0);
	$name = (x($_REQUEST, 'name') ? $_REQUEST['name'] : "");
	$json = json_decode($_POST['json'], true);
	$users = $json['user'];

	// error if no name specified
	if ($name == "")
		throw new BadRequestException('group name not specified');

	// error if no gid specified
	if ($gid == "")
		throw new BadRequestException('gid not specified');

	// remove members
	$members = group_get_members($gid);
	foreach ($members as $member) {
		$cid = $member['id'];
		foreach ($users as $user) {
			$found = ($user['cid'] == $cid ? true : false);
		}
		if (!$found) {
			$ret = group_rmv_member($uid, $name, $cid);
		}
	}

	// add members
	$erroraddinguser = false;
	$errorusers = array();
	foreach ($users as $user) {
		$cid = $user['cid'];
		// check if user really exists as contact
		$contact = q(
			"SELECT * FROM `contact` WHERE `id` = %d AND `uid` = %d",
			intval($cid),
			intval($uid)
		);

		if (count($contact)) {
			$result = group_add_member($uid, $name, $cid, $gid);
		} else {
			$erroraddinguser = true;
			$errorusers[] = $cid;
		}
	}

	// return success message incl. missing users in array
	$status = ($erroraddinguser ? "missing user" : "ok");
	$success = array('success' => true, 'gid' => $gid, 'name' => $name, 'status' => $status, 'wrong users' => $errorusers);
	return api_format_data("group_update", $type, array('result' => $success));
}

api_register_func('api/friendica/group_update', 'api_friendica_group_update', true, API_METHOD_POST);

function api_friendica_activity($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();
	$verb = strtolower($a->argv[3]);
	$verb = preg_replace("|\..*$|", "", $verb);

	$id = (x($_REQUEST, 'id') ? $_REQUEST['id'] : 0);

	$res = do_like($id, $verb);

	if ($res) {
		if ($type == "xml") {
			$ok = "true";
		} else {
			$ok = "ok";
		}
		return api_format_data('ok', $type, array('ok' => $ok));
	} else {
		throw new BadRequestException('Error adding activity');
	}
}

/// @TODO move to top of file or somwhere better
api_register_func('api/friendica/activity/like', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/dislike', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/attendyes', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/attendno', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/attendmaybe', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/unlike', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/undislike', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/unattendyes', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/unattendno', 'api_friendica_activity', true, API_METHOD_POST);
api_register_func('api/friendica/activity/unattendmaybe', 'api_friendica_activity', true, API_METHOD_POST);

/**
 * @brief Returns notifications
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
*/
function api_friendica_notification($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();
	if ($a->argc!==3) throw new BadRequestException("Invalid argument count");
	$nm = new NotificationsManager();

	$notes = $nm->getAll(array(), "+seen -date", 50);

	if ($type == "xml") {
		$xmlnotes = array();
		foreach ($notes as $note)
			$xmlnotes[] = array("@attributes" => $note);

		$notes = $xmlnotes;
	}

	return api_format_data("notes", $type, array('note' => $notes));
}

/**
 * @brief Set notification as seen and returns associated item (if possible)
 *
 * POST request with 'id' param as notification id
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_friendica_notification_seen($type)
{
	$a = get_app();

	if (api_user() === false) throw new ForbiddenException();
	if ($a->argc!==4) throw new BadRequestException("Invalid argument count");

	$id = (x($_REQUEST, 'id') ? intval($_REQUEST['id']) : 0);

	$nm = new NotificationsManager();
	$note = $nm->getByID($id);
	if (is_null($note)) throw new BadRequestException("Invalid argument");

	$nm->setSeen($note);
	if ($note['otype']=='item') {
		// would be really better with an ItemsManager and $im->getByID() :-P
		$r = q(
			"SELECT * FROM `item` WHERE `id`=%d AND `uid`=%d",
			intval($note['iid']),
			intval(local_user())
		);
		if ($r!==false) {
			// we found the item, return it to the user
			$user_info = api_get_user($a);
			$ret = api_format_items($r, $user_info, false, $type);
			$data = array('status' => $ret);
			return api_format_data("status", $type, $data);
		}
		// the item can't be found, but we set the note as seen, so we count this as a success
	}
	return api_format_data('result', $type, array('result' => "success"));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/friendica/notification/seen', 'api_friendica_notification_seen', true, API_METHOD_POST);
api_register_func('api/friendica/notification', 'api_friendica_notification', true, API_METHOD_GET);

/**
 * @brief update a direct_message to seen state
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string (success result=ok, error result=error with error message)
 */
function api_friendica_direct_messages_setseen($type)
{
	$a = get_app();
	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// params
	$user_info = api_get_user($a);
	$uid = $user_info['uid'];
	$id = (x($_REQUEST, 'id') ? $_REQUEST['id'] : 0);

	// return error if id is zero
	if ($id == "") {
		$answer = array('result' => 'error', 'message' => 'message id not specified');
		return api_format_data("direct_messages_setseen", $type, array('$result' => $answer));
	}

	// get data of the specified message id
	$r = q(
		"SELECT `id` FROM `mail` WHERE `id` = %d AND `uid` = %d",
		intval($id),
		intval($uid)
	);

	// error message if specified id is not in database
	if (!DBM::is_result($r)) {
		$answer = array('result' => 'error', 'message' => 'message id not in database');
		return api_format_data("direct_messages_setseen", $type, array('$result' => $answer));
	}

	// update seen indicator
	$result = q(
		"UPDATE `mail` SET `seen` = 1 WHERE `id` = %d AND `uid` = %d",
		intval($id),
		intval($uid)
	);

	if ($result) {
		// return success
		$answer = array('result' => 'ok', 'message' => 'message set to seen');
		return api_format_data("direct_message_setseen", $type, array('$result' => $answer));
	} else {
		$answer = array('result' => 'error', 'message' => 'unknown error');
		return api_format_data("direct_messages_setseen", $type, array('$result' => $answer));
	}
}

/// @TODO move to top of file or somwhere better
api_register_func('api/friendica/direct_messages_setseen', 'api_friendica_direct_messages_setseen', true);

/**
 * @brief search for direct_messages containing a searchstring through api
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string (success: success=true if found and search_result contains found messages
 *                          success=false if nothing was found, search_result='nothing found',
 * 		   error: result=error with error message)
 */
function api_friendica_direct_messages_search($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// params
	$user_info = api_get_user($a);
	$searchstring = (x($_REQUEST, 'searchstring') ? $_REQUEST['searchstring'] : "");
	$uid = $user_info['uid'];

	// error if no searchstring specified
	if ($searchstring == "") {
		$answer = array('result' => 'error', 'message' => 'searchstring not specified');
		return api_format_data("direct_messages_search", $type, array('$result' => $answer));
	}

	// get data for the specified searchstring
	$r = q(
		"SELECT `mail`.*, `contact`.`nurl` AS `contact-url` FROM `mail`,`contact` WHERE `mail`.`contact-id` = `contact`.`id` AND `mail`.`uid`=%d AND `body` LIKE '%s' ORDER BY `mail`.`id` DESC",
		intval($uid),
		dbesc('%'.$searchstring.'%')
	);

	$profile_url = $user_info["url"];

	// message if nothing was found
	if (!DBM::is_result($r)) {
		$success = array('success' => false, 'search_results' => 'problem with query');
	} elseif (count($r) == 0) {
		$success = array('success' => false, 'search_results' => 'nothing found');
	} else {
		$ret = array();
		foreach ($r as $item) {
			if ($box == "inbox" || $item['from-url'] != $profile_url) {
				$recipient = $user_info;
				$sender = api_get_user($a, normalise_link($item['contact-url']));
			} elseif ($box == "sentbox" || $item['from-url'] == $profile_url) {
				$recipient = api_get_user($a, normalise_link($item['contact-url']));
				$sender = $user_info;
			}

			$ret[] = api_format_messages($item, $recipient, $sender);
		}
		$success = array('success' => true, 'search_results' => $ret);
	}

	return api_format_data("direct_message_search", $type, array('$result' => $success));
}

/// @TODO move to top of file or somwhere better
api_register_func('api/friendica/direct_messages_search', 'api_friendica_direct_messages_search', true);

/**
 * @brief return data of all the profiles a user has to the client
 *
 * @param string $type Known types are 'atom', 'rss', 'xml' and 'json'
 * @return string
 */
function api_friendica_profile_show($type)
{
	$a = get_app();

	if (api_user() === false) {
		throw new ForbiddenException();
	}

	// input params
	$profileid = (x($_REQUEST, 'profile_id') ? $_REQUEST['profile_id'] : 0);

	// retrieve general information about profiles for user
	$multi_profiles = feature_enabled(api_user(), 'multi_profiles');
	$directory = Config::get('system', 'directory');

	// get data of the specified profile id or all profiles of the user if not specified
	if ($profileid != 0) {
		$r = q(
			"SELECT * FROM `profile` WHERE `uid` = %d AND `id` = %d",
			intval(api_user()),
			intval($profileid)
		);

		// error message if specified gid is not in database
		if (!DBM::is_result($r)) {
			throw new BadRequestException("profile_id not available");
		}
	} else {
		$r = q(
			"SELECT * FROM `profile` WHERE `uid` = %d",
			intval(api_user())
		);
	}
	// loop through all returned profiles and retrieve data and users
	$k = 0;
	foreach ($r as $rr) {
		$profile = api_format_items_profiles($rr, $type);

		// select all users from contact table, loop and prepare standard return for user data
		$users = array();
		$r = q(
			"SELECT `id`, `nurl` FROM `contact` WHERE `uid`= %d AND `profile-id` = %d",
			intval(api_user()),
			intval($rr['profile_id'])
		);

		foreach ($r as $rr) {
			$user = api_get_user($a, $rr['nurl']);
			($type == "xml") ? $users[$k++ . ":user"] = $user : $users[] = $user;
		}
		$profile['users'] = $users;

		// add prepared profile data to array for final return
		if ($type == "xml") {
			$profiles[$k++ . ":profile"] = $profile;
		} else {
			$profiles[] = $profile;
		}
	}

	// return settings, authenticated user and profiles data
	$self = q("SELECT `nurl` FROM `contact` WHERE `uid`= %d AND `self` LIMIT 1", intval(api_user()));

	$result = array('multi_profiles' => $multi_profiles ? true : false,
					'global_dir' => $directory,
					'friendica_owner' => api_get_user($a, $self[0]['nurl']),
					'profiles' => $profiles);
	return api_format_data("friendica_profiles", $type, array('$result' => $result));
}
api_register_func('api/friendica/profile/show', 'api_friendica_profile_show', true, API_METHOD_GET);

/*
@TODO Maybe open to implement?
To.Do:
    [pagename] => api/1.1/statuses/lookup.json
    [id] => 605138389168451584
    [include_cards] => true
    [cards_platform] => Android-12
    [include_entities] => true
    [include_my_retweet] => 1
    [include_rts] => 1
    [include_reply_count] => true
    [include_descendent_reply_count] => true
(?)


Not implemented by now:
statuses/retweets_of_me
friendships/create
friendships/destroy
friendships/exists
friendships/show
account/update_location
account/update_profile_background_image
blocks/create
blocks/destroy
friendica/profile/update
friendica/profile/create
friendica/profile/delete

Not implemented in status.net:
statuses/retweeted_to_me
statuses/retweeted_by_me
direct_messages/destroy
account/end_session
account/update_delivery_device
notifications/follow
notifications/leave
blocks/exists
blocks/blocking
lists
*/
