<?php

namespace DataDog;

/**
 * Datadog implementation of StatsD
 * Added the ability to Tag!
 *
 * @author Alex Corley <anthroprose@gmail.com>
 * @author Laurence Roberts <lsjroberts@gmail.com>
 **/
class StatsdClient
{

    private $eventUrl = '/api/v1/events';
    private $server = 'localhost';

    private $datadogHost;
    private $apiKey;
    private $applicationKey;

    private $timings = array();

    /** @var resource cURL multi handler */
    private $curl;


    public function __construct($host, $apiKey, $appKey)
    {
        $this->datadogHost = $host;
        $this->apiKey = $apiKey;
        $this->applicationKey = $appKey;
    }

    /**
     * Log timing information
     *
     * @param string $stats The metric to in log timing info for.
     * @param float $time The ellapsed time (ms) to log
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     **/
    public function timing($stat, $time, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$time|ms"), $sampleRate, $tags);
    }

    /**
     * Start a timing log
     *
     * @param  string  $stat       The metric to log timing info for.
     * @param  float|1 $sampleRate The rate (0-1) for sampling
     * @param  array  $tags        Optional tags for the metric
     *
     * @return void
     */
    public function startTiming($stat, $sampleRate = 1, array $tags = null)
    {
        $this->timings[$stat] = array(
            'start' => microtime(true),
            'sampleRate' => $sampleRate,
            'tags' => $tags
        );
    }

    /**
     * End a timing log and send to statsd
     *
     * @param  string $stat The metric to log timing info for.
     *
     * @return void
     */
    public function endTiming($stat)
    {
        if (isset($this->timings[$stat])) {
            $timing = $this->timings[$stat];
            $this->timing($stat, microtime(true) - $timing['start'], $timing['sampleRate'], $timing['tags']);
        } else {
            throw new \Exception("Timing '" . $stat . "' has not been started");
        }
    }

    /**
     * Gauge
     *
     * @param string $stat The metric
     * @param float $value The value
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     **/
    public function gauge($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|g"), $sampleRate, $tags);
    }

    /**
     * Histogram
     *
     * @param string $stat The metric
     * @param float $value The value
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     **/
    public function histogram($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|h"), $sampleRate, $tags);
    }

    /**
     * Set
     *
     * @param string $stat The metric
     * @param float $value The value
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     **/
    public function set($stat, $value, $sampleRate = 1, array $tags = null)
    {
        $this->send(array($stat => "$value|s"), $sampleRate, $tags);
    }


    /**
     * Increments one or more stats counters
     *
     * @param string|array $stats The metric(s) to increment.
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     * @return boolean
     **/
    public function increment($stats, $sampleRate = 1, array $tags = null)
    {
        $this->updateStats($stats, 1, $sampleRate, $tags);
    }

    /**
     * Decrements one or more stats counters.
     *
     * @param string|array $stats The metric(s) to decrement.
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     * @return boolean
     **/
    public function decrement($stats, $sampleRate = 1, array $tags = null)
    {
        $this->updateStats($stats, -1, $sampleRate, $tags);
    }

    /**
     * Updates one or more stats counters by arbitrary amounts.
     *
     * @param string|array $stats The metric(s) to update. Should be either a string or array of metrics.
     * @param int|1 $delta The amount to increment/decrement each metric by.
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return boolean
     **/
    public function updateStats($stats, $delta = 1, $sampleRate = 1, array $tags = null)
    {
        if (!is_array($stats)) {
            $stats = array($stats);
        }

        $data = array();

        foreach($stats as $stat) {
            $data[$stat] = "$delta|c";
        }

        $this->send($data, $sampleRate, $tags);
    }

    /**
     * Squirt the metrics over UDP
     * @param array $data Incoming Data
     * @param float|1 $sampleRate the rate (0-1) for sampling.
     * @param array|string $tags Key Value array of Tag => Value, or single tag as string
     *
     * @return null
     **/
    protected function send($data, $sampleRate = 1, array $tags = null)
    {
        // sampling
        $sampledData = array();

        if ($sampleRate < 1) {
            foreach ($data as $stat => $value) {
                if ((mt_rand() / mt_getrandmax()) <= $sampleRate) {
                    $sampledData[$stat] = "$value|@$sampleRate";
                }
            }
        } else {
            $sampledData = $data;
        }

        if (empty($sampledData)) {
            return;
        }

        // Non - Blocking UDP I/O - Use IP Addresses!
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        socket_set_nonblock($socket);

        foreach ($sampledData as $stat => $value) {
            if ($tags !== NULL && is_array($tags) && count($tags) > 0) {
                $value .= '|';

                foreach ($tags as $tag_key => $tag_val) {
                    $value .= '#' . $tag_key . ':' . $tag_val . ',';
                }

                $value = substr($value, 0, -1);
            } elseif (isset($tags) && !empty($tags)) {
                $value .= '|#' . $tags;
            }

            socket_sendto($socket, "$stat:$value", strlen("$stat:$value"), 0, $this->server, 8125);
        }

        socket_close($socket);
    }

    /**
     * Send an event to the Datadog HTTP api. Potentially slow, so avoid
     * making many call in a row if you don't want to stall your app.
     * Requires PHP >= 5.3.0 and the PECL extension pecl_http
     *
     * @param string $title Title of the event
     * @param array $vals Optional values of the event. See
     *   http://api.datadoghq.com/events for the valid keys
     * @return null
     **/
    public function event($title, $vals = array())
    {
        // Assemble the request
        $vals['title'] = $title;
        // Convert a comma-separated string of tags into an array
        if (array_key_exists('tags', $vals) && is_string($vals['tags'])) {
            $tags = explode(',', $vals['tags']);
            $vals['tags'] = array();
            foreach ($tags as $tag) {
                $vals['tags'][] = trim($tag);
            }
        }

        $body = json_encode($vals); // Added in PHP 5.3.0

        // Get the url to POST to
        $url = $this->datadogHost . $this->eventUrl
            . '?api_key='          . $this->apiKey
            . '&application_key='  . $this->applicationKey;

        // Set up the http request. Need the PECL pecl_http extension
        $this->sendEvent($url, $body);
    }

    /**
     * Sends event asynchronously (without blocking)
     *
     * @param string $url
     * @param string $body
     * @return null
     */
    protected function sendEvent($url, $body) {
        // create the multiple cURL handle
        if (!$this->curl) {
            $this->curl = curl_multi_init();
        }

        // create cURL resource
        $request = curl_init();

        // set URL and other appropriate options
        curl_setopt($request, CURLOPT_URL, $url);
        curl_setopt($request, CURLOPT_POST, TRUE);
        curl_setopt($request, CURLOPT_POSTFIELDS, $body);
        curl_setopt($request, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        // add request
        curl_multi_add_handle($this->curl, $request);

        // execute the request
        $active = null;
        do {
            $mrc = curl_multi_exec($this->curl, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);

        // do not wait for results
    }

}