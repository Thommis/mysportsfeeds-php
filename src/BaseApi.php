<?php

namespace MySportsFeeds;

class BaseApi
{
    protected $auth;

    protected $baseUrl;
    protected $verbose;
    protected $storeType;
    protected $storeLocation;
    protected $storeOutput;
    protected $version;
    protected $validFeeds = [
        'cumulative_player_stats',
        'full_game_schedule',
        'daily_game_schedule',
        'daily_player_stats',
        'game_boxscore',
        'scoreboard',
        'game_playbyplay',
        'player_gamelogs',
        'team_gamelogs',
        'roster_players',
        'game_startinglineup',
        'active_players',
        'overall_team_standings',
        'conference_team_standings',
        'division_team_standings',
        'playoff_team_standings',
        'player_injuries',
        'daily_dfs',
        'current_season',
        'latest_updates'
    ];

    # Constructor
    public function __construct($version, $verbose, $storeType = null, $storeLocation = null) {

        $this->auth = null;
        $this->verbose = $verbose;
        $this->storeType = $storeType;
        $this->storeLocation = $storeLocation;
        $this->version = $version;
        $this->baseUrl = $this->getBaseUrlForVersion($version);
    }

    protected function getBaseUrlForVersion($version)
    {
        return "https://api.mysportsfeeds.com/v{$version}/pull";
    }

    # Verify a feed
    protected function __verifyFeedName($feed) {
        $isValid = false;

        foreach ( $this->validFeeds as $value ) {
            if ( $value == $feed ) {
                $isValid = true;
                break;
            }
        }

        return $isValid;
    }

    # Verify output format
    protected function __verifyFormat($format) {
        $isValid = true;

        if ( $format != "json" and $format != "xml" and $format != "csv" ) {
            $isValid = false;
        }

        return $isValid;
    }

    # Feed URL (with only a league specified)
    protected function __leagueOnlyUrl($league, $feed, $outputFormat, ...$params) {
        return $this->baseUrl . "/" . $league . "/" . $feed . "." . $outputFormat;
    }

    # Feed URL (with league + season specified)
    protected function __leagueAndSeasonUrl($league, $season, $feed, $outputFormat, ...$params) {
        return $this->baseUrl . "/" . $league . "/" . $season . "/" . $feed . "." . $outputFormat;
    }

    # Generate the appropriate filename for a feed request
    protected function __makeOutputFilename($league, $season, $feed, $outputFormat, ...$params) {
        $filename = $feed . "-" . $league . "-" . $season;

        if ( array_key_exists("gameid", $params[0]) ) {
            $filename .= "-" . $params[0]["gameid"];
        }

        if ( array_key_exists("fordate", $params[0]) ) {
            $filename .= "-" . $params[0]["fordate"];
        }

        $filename .= "." . $outputFormat;

        return $filename;
    }

    # Save a feed response based on the store_type
    protected function __saveFeed($response, $league, $season, $feed, $outputFormat, ...$params) {
        # Save to memory regardless of selected method
        if ( $outputFormat == "json" ) {
            $this->storeOutput = json_decode($response, false);
        } elseif ( $outputFormat == "xml" ) {
            $this->storeOutput = simplexml_load_string($response);
        } elseif ( $outputFormat == "csv" ) {
            $this->storeOutput = $response;
        }

        if ( $this->storeType == "file" ) {
            if ( ! is_dir($this->storeLocation) ) {
                mkdir($this->storeLocation, 0, true);
            }

            $filename = $this->__makeOutputFilename($league, $season, $feed, $outputFormat, $params);

            file_put_contents($this->storeLocation . $filename, $response);
        }
    }

    # Indicate this version does support BASIC auth
    public function supportsBasicAuth() {
        return true;
    }

    # Establish BASIC auth credentials
    public function setAuthCredentials($username, $password) {
        $this->auth = ['username' => $username, 'password' => $password];
    }

    # Request data (and store it if applicable)
    public function getData($league = "", $season = "", $feed = "", $format = "", ...$kvParams) {
        if ( !$this->auth ) {
            throw new \ErrorException("You must authenticate() before making requests.");
        }

        $params = [];

        # iterate over args and assign vars
        foreach ( $kvParams[0] as $kvPair ) {
            $pieces = explode("=", $kvPair);

            $key = trim($pieces[0]);
            $value = trim($pieces[1]);

            if ( $key == 'league' ) {
                $league = $value;
            } elseif ( $key == 'season' ) {
                $season = $value;
            } elseif ( $key == 'feed' ) {
                $feed = $value;
            } elseif ( $key == 'format' ) {
                $format = $value;
            } else {
                $params[$key] = $value;
            }
        }

        # add force=false parameter (helps prevent unnecessary bandwidth use)
	    # Only adds if storeType == file, else you won't have any data to retrieve.
        if ( ! array_key_exists("force", $params) ) {
	        if ( $this->storeType == "file" ) {
		        $params['force'] = 'false';
	        } else {
		        $params['force'] = 'true';
	        }
        }

        if ( !$this->__verifyFeedName($feed) ) {
            throw new \ErrorException("Unknown feed '" . $feed . "'.");
        }

        if ( !$this->__verifyFormat($format) ) {
            throw new \ErrorException("Unsupported format '" . $format . "'.");
        }

        if ( $feed == 'current_season' ) {
            $url = $this->__leagueOnlyUrl($league, $feed, $format, $params);
        } else {
            $url = $this->__leagueAndSeasonUrl($league, $season, $feed, $format, $params);
        }

        $delim = "?";
        if ( strpos($url, '?') !== false ) {
            $delim = "&";
        }

        foreach ( $params as $key => $value ) {
            $url .= $delim . $key . "=" . $value;
            $delim = "&";
        }

        if ( $this->verbose ) {
            print("Making API request to '" . $url . "' ... \n");
        }

        // Establish a curl handle for the request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_ENCODING, "gzip"); // Enable compression
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // If you have issues with SSL verification
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Basic " . base64_encode($this->auth['username'] . ":" . $this->auth['password'])
        ]); // Authenticate using HTTP Basic with account credentials

        // Send the request & retrieve response
        $resp = curl_exec($ch);

        // Uncomment the following if you're having trouble:
        // print(curl_error($ch));

        // Get the response code and then close the curl handle
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = "";

        if ( $httpCode == 200 ) {
	        // Fixes MySportsFeeds/mysportsfeeds-php#1
	        // Remove if storeType == null so data gets stored in memory regardless.
	        $this->__saveFeed($resp, $league, $season, $feed, $format, $params);

            $data = $this->storeOutput;
        } elseif ( $httpCode == 304 ) {
            if ( $this->verbose ) {
                print("Data hasn't changed since last call.\n");
            }

            $filename = $this->__makeOutputFilename($league, $season, $feed, $format, $params);

            $data = file_get_contents($this->storeLocation . $filename);

            if ( $format == "json" ) {
                $this->storeOutput = json_decode($data, false);
            } elseif ( $format == "xml" ) {
                $this->storeOutput = simplexml_load_string($data);
            } elseif ( $format == "csv" ) {
                $this->storeOutput = $data;
            }

            $data = $this->storeOutput;
        } else {
            throw new \ErrorException("API call failed with response code: " . $httpCode);
        }

        return $data;
    }

}
