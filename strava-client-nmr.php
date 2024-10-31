<?php

include_once 'vendor/autoload.php';
include_once 'base-nmr.php';
class StravaClientNmr extends BaseNmr
{
    const METHOD_GET = 'GET';
    private $options = [];
    private $scopes = [
        'scope' => [
            'activity:read',
            // 'write',
            // 'view_private',
        ]
    ];
    private $provider;
    private $token;
    private $strava_user_id;

    public function __construct($token_array, $strava_user_id, $options = [])
    {
        $this->strava_user_id = $strava_user_id;
        $this->options = $options;
        $this->provider = new League\OAuth2\Client\Provider\Strava($this->options);
        if (is_array($token_array)) {
            $this->token = new League\OAuth2\Client\Token\AccessToken($token_array);
        }
    }

    public function get_provider()
    {
        return $this->provider;
    }

    public function getLoggedInAthleteActivities()
    {
        if (!is_user_logged_in()) {
            return false;
        }
        $provider = $this->provider;
        $token = $this->token;
        $after = strtotime('2020-12-31');
        $url = $provider->getResourceOwnerDetailsUrl($token) . "/activities?after={$after}&page=1&per_page=100";
        $request = $provider->getAuthenticatedRequest(self::METHOD_GET, $url, $token);
        //error_log('act request:' . print_r($request, true));
        $response = $provider->getParsedResponse($request);
        if (false === is_array($response)) {
            error_log('no response for request:' . print_r($request, true));
            return false;
            /*
            throw new UnexpectedValueException(
                'Invalid response received from Authorization Server. Expected JSON.'
            );
            */
        }
        return $response;
    }

    public function get_scopes()
    {
        return $this->scopes;
    }

    public function getAthleteIdFromToken()
    {
        $athlete_id = intval($this->token->getValues()['resource_owner_id']);
        return $athlete_id;
    }

    public function getAthlete()
    {
        return $this->getStravaApiResponse(self::METHOD_GET, "/athlete");
    }

    private function getStravaApiResponse($httpMethod, $url_part, $full_url = null)
    {
        $response = false;
        $request_copy = null;
        try {
            $provider = $this->provider;
            $this->validate_token();
            $token = $this->token;
            $url = $this->getBaseUrl() . $url_part;
            if ($full_url) {
                $url = $full_url;
            }
            $request = $provider->getAuthenticatedRequest($httpMethod, $url, $token);
            $request_copy = $request;
            //error_log('act request:' . print_r($request, true));
            $response = $provider->getParsedResponse($request);
            if (false === is_array($response)) {
                error_log('nmr strava: no strava response for request:' . print_r($request, true));
                return false;
            }
        } catch (Exception $e) {
            error_log("nmr strava: {$url} " . $e->getMessage());
            /*
            error_log(print_r($e, true));
            error_log(print_r($request_copy, true));
            */
        }
        return $response;
    }

    private function validate_token()
    {
        if ($this->token && $this->token->hasExpired()) {
            $newAccessToken = $this->provider->getAccessToken('refresh_token', [
                'refresh_token' => $this->token->getRefreshToken()
            ]);
            $this->token = $newAccessToken;
            // Purge old access token and store new access token to your data store.
            $token_array = $newAccessToken->jsonSerialize();
            $this->update_token($this->strava_user_id, json_encode($token_array));
        }
    }

    private function getBaseUrl()
    {
        $athlete_url = $this->provider->getResourceOwnerDetailsUrl($this->token);
        // 8 = len('/athlete')
        return substr($athlete_url, 0, -8);
    }

    public function getActivity($activity_id)
    {
        return $this->getStravaApiResponse(self::METHOD_GET, "/activities/{$activity_id}");
    }

    public function createSubscription()
    {
        $url = 'https://www.strava.com/api/v3/push_subscriptions';
        $args = array(
            'body' => [
                'client_id' => $this->options['clientId'],
                'client_secret' => $this->options['clientSecret'],
                'callback_url' => trim($this->options['webhook_callback_url']),
                'verify_token' => $this->options['verify_token'],
            ]
        );
        //error_log("sent to strava:" . print_r($args, true));
        $result = wp_remote_post($url, $args);
        //error_log("received from strava:" . print_r($result, true));       
        return $result;
    }

    public function get_subscription_status()
    {
        $url = "https://www.strava.com/api/v3/push_subscriptions?client_id={$this->options['clientId']}&client_secret={$this->options['clientSecret']}";
        $args = array(
            'client_id' => $this->options['clientId'],
            'client_secret' => $this->options['clientSecret'],
        );
        //error_log("sent to strava: {$url} " . print_r($args, true));
        $result = wp_remote_get($url, []);
        //error_log("received from strava:" . print_r($result, true));
        return $result;
    }

    public function deleteSubscription($subscription_id)
    {
        $url = "https://www.strava.com/api/v3/push_subscriptions/{$subscription_id}";
        $args = array(
            'method' => 'DELETE',
            'body' => [
                'client_id' => $this->options['clientId'],
                'client_secret' => $this->options['clientSecret'],
            ]
        );
        //error_log("sent to strava:" . print_r($args, true));
        $response = wp_remote_request($url, $args);
        $body = wp_remote_retrieve_body($response);
        if(is_array($response)){
            $response[] = $body;
        }
        //error_log("received from strava:" . print_r($result, true));       
        return $response;
    }

    public function deauthorize()
    {

        $url = 'https://www.strava.com/oauth/deauthorize';
        return $this->getStravaApiResponse('POST', '', $url);
    }
}
