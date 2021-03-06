<?php

namespace okapi\services\caches\geocache;

use okapi\core\Db;
use okapi\core\Exception\InvalidParam;
use okapi\core\Exception\ParamMissing;
use okapi\core\Okapi;
use okapi\core\OkapiServiceRunner;
use okapi\core\Request\OkapiInternalRequest;
use okapi\core\Request\OkapiRequest;

class WebService
{
    public static function options()
    {
        return array(
            'min_auth_level' => 1
        );
    }

    public static function call(OkapiRequest $request)
    {
        $cache_code = $request->get_parameter('cache_code');
        if (!$cache_code) throw new ParamMissing('cache_code');
        if (strpos($cache_code, "|") !== false) throw new InvalidParam('cache_code');
        $langpref = $request->get_parameter('langpref');
        if (!$langpref) $langpref = "en";
        $fields = $request->get_parameter('fields');
        if (!$fields) $fields = "code|name|location|type|status";
        $owner_fields = $request->get_parameter('owner_fields');
        if (!$owner_fields) $owner_fields = "uuid|username|profile_url";
        $log_fields = $request->get_parameter('log_fields');
        if (!$log_fields) $log_fields = "uuid|date|user|type|comment";
        $log_user_fields = $request->get_parameter('log_user_fields');
        if (!$log_user_fields) $log_user_fields = "uuid|username|profile_url";
        $lpc = $request->get_parameter('lpc');
        if (!$lpc) $lpc = 10;
        $user_logs_only = $request->get_parameter('user_logs_only');
        if ($user_logs_only === null) $user_logs_only = 'false';
        $attribution_append = $request->get_parameter('attribution_append');
        if (!$attribution_append) $attribution_append = 'full';
        $oc_team_annotation = $request->get_parameter('oc_team_annotation');
        if (!$oc_team_annotation) $oc_team_annotation = 'description';
        $params = array(
            'cache_codes' => $cache_code,
            'langpref' => $langpref,
            'fields' => $fields,
            'owner_fields' => $owner_fields,
            'attribution_append' => $attribution_append,
            'oc_team_annotation' => $oc_team_annotation,
            'lpc' => $lpc,
            'log_fields' => $log_fields,
            'log_user_fields' => $log_user_fields,
            'user_logs_only' => $user_logs_only,
        );
        $my_location = $request->get_parameter('my_location');
        if ($my_location)
            $params['my_location'] = $my_location;
        $user_uuid = $request->get_parameter('user_uuid');
        if ($user_uuid)
            $params['user_uuid'] = $user_uuid;

        # There's no need to validate the fields/lpc parameters as the 'geocaches'
        # method does this (it will raise a proper exception on invalid values).

        $results = OkapiServiceRunner::call('services/caches/geocaches', new OkapiInternalRequest(
            $request->consumer, $request->token, $params));
        $result = $results[$cache_code];
        if ($result === null)
        {
            # Two errors messages (for OCDE). Makeshift solution for issue #350.

            $exists = Db::select_value("
                select 1
                from caches
                where wp_oc='".Db::escape_string($cache_code)."'
            ");
            if ($exists) {
                throw new InvalidParam('cache_code', "This cache is not accessible via OKAPI.");
            } else {
                throw new InvalidParam('cache_code', "This cache does not exist.");
            }
        }
        return Okapi::formatted_response($request, $result);
    }
}
