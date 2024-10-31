<?php

global $wpdb;
class BaseNmr
{
    public static $tables = [];

    public function __construct()
    {
    }

    public function set_error($errMsg, $user_id = null, $strava_user_id = null, $arrDetails = array())
    {
        global $wpdb;
        $dataString = '';
        if (count($arrDetails) > 0) {
            $dataString = print_r($arrDetails, true);
        }
        $wpdb->insert(
            self::$tables['logs'],
            [
                'strava_user_id' => $strava_user_id,
                'user_id' => $user_id,
                'message' => "{$errMsg} {$dataString}"
            ],
            ['%d', '%d', '%s']
        );
    }

    static function log($message, $user_id = null, $strava_user_id = null, $arrDetails = array())
    {
        global $wpdb;
        $dataString = '';
        if (count($arrDetails) > 0) {
            $dataString = print_r($arrDetails, true);
        }
        $wpdb->insert(
            self::$tables['logs'],
            [
                'strava_user_id' => $strava_user_id,
                'user_id' => $user_id,
                'message' => "{$message} {$dataString}"
            ],
            ['%d', '%d', '%s']
        );
    }

    public function update_token($strava_user_id, $token)
    {
        global $wpdb;
        $reuslt = $wpdb->update(
            self::$tables['users'],
            ['strava_token' => $token],
            ['strava_user_id' => $strava_user_id],
            ['%s'],
            ['%d']
        );
        if (false === $reuslt) {
            $this->set_error("could not update strava token", null, $strava_user_id, ['strava_token' => $token]);
        }
    }
}
