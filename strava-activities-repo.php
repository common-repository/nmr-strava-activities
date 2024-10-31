<?php
include_once 'base-repo.php';
include_once 'strava-client-nmr.php';

class StravaActivitiesRepo extends BaseRepo
{
    private $strava_client;
    private $options = [];
    public function __construct($method, $data, $options)
    {
        parent::__construct($method, $data);
        $this->options = $options;
    }

    public function add_strava_update($dataString)
    {
        global $wpdb;
        $result = $wpdb->insert(self::$tables['updates'], ['posted_data' => $dataString]);
        if (false === $result) {
            $this->set_error("could not insert strava update: {dataString}");
        }
    }

    public function init_oauth($token_array, $strava_user_id)
    {
        $this->strava_client = new StravaClientNmr($token_array, $strava_user_id, $this->options);
    }

    public function import_activity($activity_id, $user_id, $strava_user_id)
    {
        $client = $this->strava_client;
        $activity = $client->getActivity($activity_id);
        if (!$activity) {
            $this->set_error("Could not get strava activity {$activity_id}", $user_id, $strava_user_id);
            return false;
        }
        $save_nmr_strava_activity = apply_filters("nmr_strava_save_activity", $activity['type']);
        if (!$save_nmr_strava_activity) {
            $this->set_error("ignored activity {$activity_id} due to type: {$activity['type']}", $user_id, $strava_user_id);
            return false;
        }
        $save_nmr_strava_activity = apply_filters("nmr_strava_save_activity_full", $activity);
        if (!$save_nmr_strava_activity) {
            $this->set_error("ignored activity {$activity_id} due to filter nmr_strava_save_activity_full", $user_id, $strava_user_id);
            return false;
        }
        global $wpdb;
        //delete existing record, will be replace with current one.
        $wpdb->delete(self::$tables['activities'], [
            'id' => $activity['id'],
            'upload_id' => intval($activity['upload_id']),
            'athlete_id' => intval($activity['athlete']['id']),
        ], ['%d', '%d', '%d']);
        $toInsert = [
            'id' => $activity['id'],
            'external_id' => intval($activity['external_id']),
            'upload_id' => intval($activity['upload_id']),
            'athlete_id' => intval($activity['athlete']['id']),
            'name' => $activity['name'],
            'distance' => $activity['distance'],
            'moving_time' => $activity['moving_time'],
            'elapsed_time' => $activity['elapsed_time'],
            'total_elevation_gain' => $activity['total_elevation_gain'],
            'type' => $activity['type'],
            'start_date' => $activity['start_date'],
            'start_date_local' => $activity['start_date_local'],
            'timezone' => $activity['timezone'],
            'raw_activity' => json_encode($activity),
        ];
        $added = $wpdb->insert(
            self::$tables['activities'],
            $toInsert,
            ['%d', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if (!$added) {
            $this->set_error(
                "Could not save to strava activity table",
                ['last_error' => $wpdb->print_error()]
            );
            return false;
        }
        $toInsert['user_id'] = $user_id;
        do_action('strava_nmr_activity_changed', 'update', $toInsert);
        return $added;
    }

    public function delete_segment($activity_id, $strava_user_id, $user_id)
    {
        global $wpdb;
        $deleted = $wpdb->delete(
            self::$tables['activities'],
            ['athlete_id' => $strava_user_id, 'id' => $activity_id],
            ['%d', '%d']
        );
        if ($deleted) {
            do_action(
                'strava_nmr_activity_changed',
                'delete',
                [
                    'activity_id' => $activity_id, 'strava_user_id' => $strava_user_id, 'user_id' => $user_id
                ]
            );
        }
        return $deleted;
    }

    public function delete_token($owner_id)
    {
        global $wpdb;
        $updated = $wpdb->update(
            self::$tables['users'],
            ['strava_token' => null],
            ['strava_user_id' => $owner_id],
            ['%s'],
            ['%d']
        );
        if ($updated) {
            do_action(
                'strava_nmr_user_deauthorized',
                [
                    'strava_user_id' => $owner_id,
                ]
            );
        }
    }
}
