<?php

namespace DataDog;

/**
 * Datadog implementation of StatsD
 * Added the ability to Tag!
 *
 * @author Alex Corley <anthroprose@gmail.com>
 * @author Laurence Roberts <lsjroberts@gmail.com>
 */
class DatadogClient
{

    private $datadogHost = 'https://app.datadoghq.com';
    private $eventUrl = '/api/v1/events';
    private $statsdServer = 'localhost';

    private $apiKey;
    private $applicationKey;

    /** @var array tags for all stats (eg. controler/action name) */
    private $tags = array();

    /** @var array started timings */
    private $timings = array();

    /** @var resource cURL multi handler */
    private $curl;


    public function __construct($apiKey, $appKey)
    {
        $this->apiKey = $apiKey;
        $this->applicationKey = $appKey;
    }

    public function __destruct()
    {
        if ($this->timings)
        {
            trigger_error('DatadogClient: some timings were not properly ended.');
        }
    }

    /**
     * @param string
     */
    public function setDatadogHost($host)
    {
        $this->datadogHost = $host;
    }

    /**
     * @param string - host name or IP address (better)
     */
    public function setStatsdServer($server)
    {
        $this->statsdServer = $server;
    }

    /**
     * Set shared tags for all stats
     *
     * @param array
     */
    public function setTags(array $tags)
    {
        $this->tags = $tags;
    }

    /**
     * Log timing information
     *
     * @param string - the metric to in log timing info for
     * @param float - the ellapsed time (ms) to log
     * @param float - the rate (0-1) for sampling
     */
    public function timing($stat, $time, $sampleRate = 1, array $tags = array())
    {
        return $this->send(array($stat => "$time|ms"), $sampleRate, $tags);
    }

    /**
     * Start a timing log
     *
     * @param string - the metric to log timing info for
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function startTiming($stat, $sampleRate = 1, array $tags = array())
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
     * @param string - the metric to log timing info for
     */
    public function endTiming($stat)
    {
        if (isset($this->timings[$stat])) {
            $timing = $this->timings[$stat];
            unset($this->timings[$stat]);
            return $this->timing($stat, microtime(true) - $timing['start'], $timing['sampleRate'], $timing['tags']);
        } else {
            throw new DatadogClientException("Timing '" . $stat . "' has not been started");
        }
    }

    /**
     * Gauge (eg. disk space, free memory...)
     *
     * @param string - the metric
     * @param float - the value
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function gauge($stat, $value, $sampleRate = 1, array $tags = array())
    {
        return $this->send(array($stat => "$value|g"), $sampleRate, $tags);
    }

    /**
     * Histogram (eg. process time, process used memory...)
     *
     * @param string - the metric
     * @param float - the value
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function histogram($stat, $value, $sampleRate = 1, array $tags = array())
    {
        return $this->send(array($stat => "$value|h"), $sampleRate, $tags);
    }

    /**
     * Set of unique values (eg. unique user ids...)
     *
     * @param string - the metric
     * @param float - the value
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function set($stat, $value, $sampleRate = 1, array $tags = array())
    {
        return $this->send(array($stat => "$value|s"), $sampleRate, $tags);
    }

    /**
     * Increments one or more stats counters (eg. page-view)
     *
     * @param string|array - the metric(s) to increment
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function increment($stats, $sampleRate = 1, array $tags = array())
    {
        return $this->updateStats($stats, 1, $sampleRate, $tags);
    }

    /**
     * Decrements one or more stats counters
     *
     * @param string|array - the metric(s) to decrement
     * @param float - the rate (0-1) for sampling
     * @param array - optional tags for the metric
     */
    public function decrement($stats, $sampleRate = 1, array $tags = array())
    {
        return $this->updateStats($stats, -1, $sampleRate, $tags);
    }

    /**
     * Updates one or more stats counters by arbitrary amounts
     *
     * @param string|array - the metric(s) to update. Should be either a string or array of metrics
     * @param int - the amount to increment/decrement each metric by
     * @param float - the rate (0-1) for sampling
     * @param array - key Value array of Tag => Value
     */
    public function updateStats($stats, $delta = 1, $sampleRate = 1, array $tags = array())
    {
        if (!is_array($stats)) {
            $stats = array($stats);
        }

        $data = array();

        foreach($stats as $stat) {
            $data[$stat] = "$delta|c";
        }

        return $this->send($data, $sampleRate, $tags);
    }

    /**
     * Squirt the metrics over UDP
     *
     * @param array - incoming Data
     * @param float - the rate (0-1) for sampling
     * @param array - key-value array of Tag => Value
     */
    protected function send($data, $sampleRate = 1, array $tags = array())
    {
        $packets = $this->preparePackets($data, $sampleRate, $tags);

        // Non - Blocking UDP I/O - Use IP Addresses!
        $socket = fsockopen('udp://' . $this->statsdServer, 8125, $errno, $error);
        stream_set_blocking($socket, 0);

        foreach ($packets as $packet)
        {
            fwrite($socket, $packet);
        }

        fclose($socket);

        return $packets;
    }

    /**
     * Formats packets for sending
     *
     * @param array
     * @param float - the rate (0-1) for sampling
     * @param array - key-value array of Tag => Value
     * @return string[]
     */
    public function preparePackets($data, $sampleRate = 1, array $tags = array())
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
            return array();
        }

        $tagString = $this->serializeTags($tags);

        $packets = array();
        foreach ($sampledData as $stat => $value) {
            $packet = $stat . ':' . $value;
            if ($tagString)
            {
                $packet .= '|#' . $tagString;
            }
            $packets[] = $packet;
        }

        return $packets;
    }

    /**
     * @param array
     * @return string
     */
    public function serializeTags($tags)
    {
        $tags = array_merge($tags, $this->tags);
        $values = array();
        foreach ($tags as $name => $value)
        {
            if (str_replace(array('|', ',', ':', '@', '#'), '', $name . $value) !== $name . $value)
            {
                throw new DatadogClientException('Invalid characters in tags. Do not use any of these: "|,:@#".');
            }
            $values[] = $value === TRUE ? $name : "$name:$value";
        }

        return implode(',', $values);
    }

    /**
     * Send an event to the Datadog HTTP api. Potentially slow, so avoid
     * making many call in a row if you don't want to stall your app
     * Requires PHP >= 5.3.0 and the PECL extension pecl_http
     *
     * @param string - Title of the event
     * @param array - Optional values of the event. See http://api.datadoghq.com/events for the valid keys
     */
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

        $body = json_encode($vals);

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
     * @param string
     * @param string
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


class DatadogClientException extends \Exception
{

}
