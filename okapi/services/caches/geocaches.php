<?php

namespace okapi\services\caches\geocaches;

use Exception;
use okapi\Okapi;
use okapi\OkapiRequest;
use okapi\ParamMissing;
use okapi\InvalidParam;
use okapi\OkapiInternalRequest;
use okapi\OkapiServiceRunner;
use okapi\services\caches\search\SearchAssistant;

class WebService
{
	public static function options()
	{
		return array(
			'min_auth_level' => 1
		);
	}
	
	public static $valid_field_names = array('code', 'name', 'names', 'location', 'type',
		'status', 'url', 'owner', 'founds', 'notfounds', 'size', 'difficulty', 'terrain',
		'rating', 'rating_votes', 'recommendations', 'description', 'descriptions', 'hint',
		'hints', 'images', 'latest_logs', 'last_found', 'last_modified', 'date_created',
		'date_hidden', 'internal_id');
	
	public static function call(OkapiRequest $request)
	{
		$cache_codes = $request->get_parameter('cache_codes');
		if (!$cache_codes) throw new ParamMissing('cache_codes');
		$cache_codes = explode("|", $cache_codes);
		if (count($cache_codes) > 500)
			throw new InvalidParam('cache_codes', "Maximum allowed number of referenced ".
				"caches is 500. You provided ".count($cache_codes)." cache codes.");
		$langpref = $request->get_parameter('langpref');
		if (!$langpref) $langpref = "en";
		$langpref = explode("|", $langpref);
		$fields = $request->get_parameter('fields');
		if (!$fields) $fields = "code|name|location|type|status";
		$fields = explode("|", $fields);
		foreach ($fields as $field)
			if (!in_array($field, self::$valid_field_names))
				throw new InvalidParam('fields', "'$field' is not a valid field code.");
		$rs = sql("
			select
				c.cache_id, c.name, c.longitude, c.latitude, c.last_modified,
				c.date_created, c.type, c.status, c.date_hidden, c.founds, c.notfounds, c.last_found,
				c.size, c.difficulty, c.terrain, c.wp_oc, c.topratings, c.votes, c.score,
				u.uuid as user_uuid, u.username, u.user_id
			from
				caches c,
				user u
			where
				wp_oc in ('".implode("','", array_map('mysql_real_escape_string', $cache_codes))."')
				and c.user_id = u.user_id
		");
		$results = array();
		$cacheid2wptcode = array();
		while ($row = sql_fetch_assoc($rs))
		{
			$entry = array();
			$cacheid2wptcode[$row['cache_id']] = $row['wp_oc'];
			foreach ($fields as $field)
			{
				switch ($field)
				{
					case 'code': $entry['code'] = $row['wp_oc']; break;
					case 'name': $entry['name'] = $row['name']; break;
					case 'names': $entry['name'] = array('pl' => $row['name']); break; // for the future
					case 'location': $entry['location'] = round($row['latitude'], 6)."|".round($row['longitude'], 6); break;
					case 'type': $entry['type'] = Okapi::cache_type_id2name($row['type']); break;
					case 'status': $entry['status'] = Okapi::cache_status_id2name($row['status']); break;
					case 'url': $entry['url'] = $GLOBALS['absolute_server_URI']."viewcache.php?cacheid=".$row['cache_id']; break;
					case 'owner':
						$entry['owner'] = array(
							'uuid' => $row['user_uuid'],
							'username' => $row['username'],
							'profile_url' => $GLOBALS['absolute_server_URI']."viewprofile?userid=".$row['user_id']
						);
						break;
					case 'founds': $entry['founds'] = $row['founds'] + 0; break;
					case 'notfounds': $entry['notfounds'] = $row['notfounds'] + 0; break;
					case 'size': $entry['size'] = ($row['size'] < 7) ? $row['size'] - 1 : null; break;
					case 'difficulty': $entry['difficulty'] = round($row['difficulty'] / 2.0, 1); break;
					case 'terrain': $entry['terrain'] = round($row['terrain'] / 2.0, 1); break;
					case 'rating':
						if ($row['votes'] <= 3) $entry['rating'] = null;
						elseif ($row['score'] >= 2.2) $entry['rating'] = 5;
						elseif ($row['score'] >= 1.4) $entry['rating'] = 4;
						elseif ($row['score'] >= 0.1) $entry['rating'] = 3;
						elseif ($row['score'] >= -1.0) $entry['rating'] = 2;
						else $entry['score'] = 1;
						break;
					case 'rating_votes': $entry['rating_votes'] = $row['votes'] + 0; break;
					case 'recommendations': $entry['recommendations'] = $row['topratings'] + 0; break;
					case 'description': /* handled separately */ break;
					case 'descriptions': /* handled separately */ break;
					case 'hint': /* handled separately */ break;
					case 'hints': /* handled separately */ break;
					case 'images': /* handled separately */ break;
					case 'latest_logs': /* handled separately */ break;
					case 'last_found': $entry['last_found'] = $row['last_found'] ? date('c', strtotime($row['last_found'])) : null; break;
					case 'last_modified': $entry['last_modified'] = date('c', strtotime($row['last_modified'])); break;
					case 'date_created': $entry['date_created'] = date('c', strtotime($row['date_created'])); break;
					case 'date_hidden': $entry['date_hidden'] = date('c', strtotime($row['date_hidden'])); break;
					case 'internal_id': $entry['internal_id'] = $row['cache_id']; break;
					default: throw new Exception("Missing field case: ".$field);
				}
			}
			$results[$row['wp_oc']] = $entry;
		}
		mysql_free_result($rs);
		
		# Descriptions and hints.
		
		if (in_array('description', $fields) || in_array('descriptions', $fields)
			|| in_array('hint', $fields) || in_array('hints', $fields))
		{
			# At first, we will fill all those 4 fields, even if user requested just one
			# of them. We will chop off the remaining three at the end.
			
			foreach ($results as &$result_ref)
				$result_ref['descriptions'] = array();
			foreach ($results as &$result_ref)
				$result_ref['hints'] = array();
			
			# Get cache descriptions and hints.
			
			$rs = sql("
				select cache_id, language, `desc`, hint
				from cache_desc
				where cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$cache_code = $cacheid2wptcode[$row['cache_id']];
				// strtolower - ISO 639-1 codes are lowercase
				if ($row['desc'])
					$results[$cache_code]['descriptions'][strtolower($row['language'])] = $row['desc'].
						"\n".self::get_cache_attribution_note($row['cache_id'], strtolower($row['language']));
				if ($row['hint'])
					$results[$cache_code]['hints'][strtolower($row['language'])] = $row['hint'];
			}
			foreach ($results as &$result_ref)
			{
				$result_ref['description'] = Okapi::pick_best_language($result_ref['descriptions'], $langpref);
				$result_ref['hint'] = Okapi::pick_best_language($result_ref['hints'], $langpref);
			}
			
			# Remove unwanted fields.
			
			foreach (array('description', 'descriptions', 'hint', 'hints') as $field)
				if (!in_array($field, $fields))
					foreach ($results as &$result_ref)
						unset($result_ref[$field]);
		}
		
		# Images.
		
		if (in_array('images', $fields))
		{
			foreach ($results as &$result_ref)
				$result_ref['images'] = array();
			$rs = sql("
				select object_id, url, thumb_url, title, spoiler
				from pictures
				where
					object_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
					and display = 1
			");
			while ($row = sql_fetch_assoc($rs))
			{
				$cache_code = $cacheid2wptcode[$row['object_id']];
				$results[$cache_code]['images'][] = array(
					'url' => $row['url'],
					'thumb_url' => $row['thumb_url'] ? $row['thumb_url'] : null,
					'caption' => $row['title'],
					'is_spoiler' => ($row['spoiler'] ? true : false),
				);
			}
		}
		
		# Latest log entries.
		
		if (in_array('latest_logs', $fields))
		{
			foreach ($results as &$result_ref)
				$result_ref['latest_logs'] = array();
			
			# Get log IDs and dates. Sort in groups. Filter out latest 20. This is the fastest
			# technique I could think of...
			
			$cachelogs = array();
			$rs = sql("
				select cache_id, id
				from cache_logs
				where
					cache_id in ('".implode("','", array_map('mysql_real_escape_string', array_keys($cacheid2wptcode)))."')
					and deleted = 0
			");
			while ($row = sql_fetch_assoc($rs))
				$cachelogs[$row['cache_id']][] = $row['id']; // @
			$logids = array();
			foreach ($cachelogs as $cache_key => &$logids_ref)
			{
				rsort($logids_ref);
				$logids = array_merge($logids, array_slice($logids_ref, 0, 20));
			}
			
			# Now retrieve text and join.
			
			$rs = sql("
				select cl.cache_id, cl.id, cl.uuid, cl.type, unix_timestamp(cl.date) as date, cl.text,
					u.uuid as user_uuid, u.username, u.user_id
				from cache_logs cl, user u
				where
					cl.id in ('".implode("','", array_map('mysql_real_escape_string', $logids))."')
					and cl.deleted = 0
					and cl.user_id = u.user_id
				order by cl.cache_id, cl.id desc
			");
			$cachelogs = array();
			while ($row = sql_fetch_assoc($rs))
			{
				$results[$cacheid2wptcode[$row['cache_id']]]['latest_logs'][] = array(
					'uuid' => $row['uuid'],
					'date' => date('c', $row['date']),
					'user' => array(
						'uuid' => $row['user_uuid'],
						'username' => $row['username'],
						'profile_url' => $GLOBALS['absolute_server_URI']."viewprofile.php?userid=".$row['user_id'],
					),
					'type' => Okapi::logtypeid2name($row['type']),
					'comment' => $row['text']
				);
			}
		}
		
		# Check which cache codes were not found and mark them with null.
		foreach ($cache_codes as $cache_code)
			if (!isset($results[$cache_code]))
				$results[$cache_code] = null;
		
		return Okapi::formatted_response($request, $results);
	}
	
	public static function get_cache_attribution_note($cache_id, $lang)
	{
		$site_url = $GLOBALS['absolute_server_URI'];
		$site_name = Okapi::get_normalized_site_name();
		$cache_url = $site_url."viewcache.php?cacheid=$cache_id";
		
		# This list if to be extended (opencaching.de, etc.).
		
		switch ($lang)
		{
			case 'pl':
				return "<p>Opis <a href='$cache_url'>skrzynki</a> pochodzi z serwisu <a href='$site_url'>$site_name</a>.</p>";
				break;
			default:
				return "<p>This <a href='$cache_url'>geocache</a> description comes from the <a href='$site_url'>$site_name</a> site.</p>";
				break;
		}
	}
}