<?php

namespace okapi\lib;

use okapi\core\Exception\OkapiExceptionHandler;
use okapi\Settings;

/**
 * Container for remote URL call implementation
 */
class RemoteURLCaller
{

    /**
     * Makes a POST call to a remote HTTP URL, passing supplied data but
     * ignoring returned content.
     * The timeout is set to apropriate value if 'REMOTE_URL_CALL_TIMEOUT' is
     * present in Settings and is non-zero.
     * The 'OKAPI-key' header is set to apropriate key if 'REMOTE_URL_CALL_KEY'
     * is present in Settings and is non-empty.
     * In case of error the apropriate strategy of its handling is taken,
     * according to 'REMOTE_URL_CALL_EXCEPTION_HANDLING' Setting.
     *
     * @param string $url The URL to call, must be of 'http://' or 'https://'
     *      scheme.
     * @param array $data Array of parameters and values to be passed as POST
     *      content ('application/x-www-form-urlencoded' MIME type). See
     *      documentation of built-in 'http_build_query' function for details.
     */
    public static function call_url($url, $data)
    {
        if (
            !is_string($url)
            || empty($url)
            || !preg_match('/^https?\:\/\//', $url)
        )
        {
            # $url is not valid, therefore we skip the processing silently
            return;
        }

        try
        {
            if (function_exists("stream_context_create"))
            {
                $stream_options = [
                    'http' => [
                        'method' => 'POST',
                        'header' => [
                            'Connection: close',
                        ],
                    ]
                ];

                $ttl = Settings::get('REMOTE_URL_CALL_TIMEOUT');
                if (!empty($ttl) && $ttl > 0)
                {
                    # if timeout is set, the connection should automatically
                    # be closed after $ttl seconds;
                    # $ttl division by 2 is based on observation that the real
                    # timeout takes 2 times longer than the declared one,
                    # whatever $ttl value is; it dependends not on
                    # default_socket_timeout ini set
                    $stream_options['http']['timeout'] = $ttl / 2;
                }

                if (!empty(Settings::get('REMOTE_URL_CALL_KEY')))
                {
                    # adding 'OKAPI-Key' header if key is non-empty
                    array_push(
                        $stream_options['http']['header'],
                        'OKAPI-Key: ' . Settings::get('REMOTE_URL_CALL_KEY')
                    );
                }

                $is_https = (substr($url, 0, 8) === "https://");
                if ($is_https)
                {
                    # the call is intended to be made to a local service, so in
                    # case of https connection, we trust the certificate and do
                    # not verify peer
                    $stream_options['ssl'] = [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ];
                }

                if (isset($data) && is_array($data) && sizeof($data) > 0)
                {
                    # there will be post parameters, lets include them
                    array_push(
                        $stream_options['http']['header'],
                        'Content-type: application/x-www-form-urlencoded'
                    );
                    $stream_options['http']['content'] =
                        http_build_query($data, '', '&');
                }

                $stream_context = stream_context_create($stream_options);

                # let's call the service, skipping the return content if any
                @file_get_contents($url, false, $stream_context);
            }
        }
        catch (\Throwable $e) {
            # PHP 7
            self::handle_exception($e);
        }
        catch (\Exception $e) {
            # PHP 5 only
            self::handle_exception($e);
        }
    }

    private static function handle_exception($e)
    {
        switch(Settings::get('REMOTE_URL_CALL_EXCEPTION_HANDLING'))
        {
            case 'skip':
                break;
            case 'log':
                error_log(OkapiExceptionHandler::get_exception_info($e));
                break;
            case 'common':
                OkapiExceptionHandler::handle($e);
                break;
            default:
                # capturing the error output
                ob_start();
                OkapiExceptionHandler::handle($e);
                # do not pass the error handler output, it should not interfere
                # with service call result
                ob_end_clean();
        }
    }

}
