<?php

namespace okapi\views\devel\dbstruct;

use Exception;
use okapi\Okapi;
use okapi\Settings;
use okapi\Cache;
use okapi\Db;
use okapi\OkapiRequest;
use okapi\OkapiRedirectResponse;
use okapi\OkapiHttpResponse;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiServiceRunner;
use okapi\OkapiInternalRequest;

class View
{
	public static function call()
	{
		# This is a hidden page for OKAPI developers. It will output a complete
		# structure of the database. This is useful for making OKAPI compatible
		# across different OC installations.

		$user = Settings::get('DB_USERNAME');
		$password = Settings::get('DB_PASSWORD');
		$dbname = Settings::get('DB_NAME');
		$struct = shell_exec("mysqldump --no-data -u$user -p$password $dbname");

		# Remove the "AUTO_INCREMENT=..." values. They break the diffs.

		$struct = preg_replace("/ AUTO_INCREMENT=([0-9]+)/i", "", $struct);

		# This method can be invoked with "compare_to" parameter, which points to
		# an alternate database structure (probably generated by the same script
		# in other OKAPI instance). When invoked this way, we will attempt to
		# generate SQL script which alters LOCAL database is such a way that it
		# will become THE OTHER database.

		$response = new OkapiHttpResponse();
		$response->content_type = "text/plain; charset=utf-8";
		if (isset($_GET['compare_to']))
		{
			$scheme = parse_url($_GET['compare_to'], PHP_URL_SCHEME);
			if (in_array($scheme, array('http', 'https')))
			{
				$alternate_struct = @file_get_contents($_GET['compare_to']);
				$response->body =
					"-- Automatically generated database diff. Use with caution!\n".
					"-- Differences obtained with help of cool library by Kirill Gerasimenko.\n\n".
					"-- Note: The following script has some limitations. It will render database\n".
					"-- structure compatible, but not necessarilly EXACTLY the same. It might be\n".
					"-- better to use manual diff instead.\n\n";
				require_once("comparator.inc.php");
				$updater = new \dbStructUpdater();
				if (isset($_GET['reverse']) && ($_GET['reverse'] == 'true'))
				{
					$response->body .=
						"-- REVERSE MODE. The following will alter [2], so that it has the structure of [1].\n".
						"-- 1. ".Settings::get('SITE_URL')."okapi/devel/dbstruct (".md5($struct).")\n".
						"-- 2. ".$_GET['compare_to']." (".md5($alternate_struct).")\n\n";
					$alters = $updater->getUpdates($alternate_struct, $struct);
				}
				else
				{
					$response->body .=
						"-- The following will alter [1], so that it has the structure of [2].\n".
						"-- 1. ".Settings::get('SITE_URL')."okapi/devel/dbstruct (".md5($struct).")\n".
						"-- 2. ".$_GET['compare_to']." (".md5($alternate_struct).")\n\n";
					$alters = $updater->getUpdates($struct, $alternate_struct);
				}
				# Add semicolons
				foreach ($alters as &$alter_ref)
					$alter_ref .= ";";
				# Comment out all differences containing "okapi_". These should be executed
				# by OKAPI update scripts.
				foreach ($alters as &$alter_ref)
				{
					if (strpos($alter_ref, "okapi_") !== false)
					{
						$lines = explode("\n", $alter_ref);
						$alter_ref = "-- Probably you should NOT execute this one. Use okapi/update instead.\n-- {{{\n--   ".
							implode("\n--   ", $lines)."\n-- }}}";
					}
				}
				if (count($alters) > 0)
					$response->body .= implode("\n", $alters)."\n";
				else
					$response->body .= "-- No differences found\n";
			}
			else
			{
				$response->body = "HTTP(S) only!";
			}
		}
		else
		{
			$response->body = $struct;
		}
		return $response;
	}

}
