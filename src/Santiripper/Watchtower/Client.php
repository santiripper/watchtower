<?php

namespace Santiripper\Watchtower;

use Log;

/**
 * Watchtower Client
 * @package Santiripper\Watchtower
 */
class Client
{
    /**
     * @var \Aws\CloudWatch\CloudWatchClient
     */
    protected $cloudwatch;

    /**
     * @var array
     */
    private $metrics = [];
    /**
     * @var bool
     */
    private $sendOnShutdown;

    /**
     * @var bool
     */
    protected $enabled;

    /**
     * @var string
     */
    protected $output;

    /**
     * @var bool
     */
    protected $throw_exception_on_fail;

    /**
     * Client constructor.
     */
    public function __construct()
    {
        $this->cloudWatch = app()->make('aws')->createClient('cloudwatch');

        $this->sendOnShutdown = config('watchtower.send_on_shutdown', false);
        $this->enabled        = config('watchtower.enabled', false);
        $this->output         = config('watchtower.output');

        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * @param string $on Cloudwatch namespace
     * @return Metric
     */
    public function on($on)
    {
        if (array_get($this->metrics, $on)) {
            return $this->metrics[$on];
        }

        return $this->metrics[$on] = new Metric($on);
    }

    /**
     * @param boolean $sendOnShutdown
     *
     * @return $this
     */
    public function setSendOnShutdown($sendOnShutdown)
    {
        $this->sendOnShutdown = $sendOnShutdown;
        return $this;
    }

    /**
     * @param boolean $enabled
     *
     * @return $this
     */
    public function setEnabled($enabled)
    {
        $this->enabled = $enabled;
        return $this;
    }

    /**
     * @return array
     */
    protected function toArray()
    {
        $metricsArray = array_map(function ($metric) {
            return $metric->toArray();
        }, array_values($this->metrics));

        return $metricsArray;
    }

    /**
     * @return $this
     */
    public function clear()
    {
        $this->metrics = [];

        return $this;
    }

    /**
     * @return $this
     * @throws \Exception
     */
    public function send()
    {
        if (! $this->isEnabled() || !count($this->metrics)) {
            return $this;
        }

        //$data     = $this->toArray();
        $output   = $this->output;
        $function = camel_case('send_to_' . $output);

        try {
            foreach ($this->metrics as $metric) {
                call_user_func([$this, $function], $metric->toArray());
            }
        } catch (\Exception $e) {
            if ($this->throw_exception_on_fail) {
                throw $e;
            }

            Log::error('Cloudwatch: ' . $e->getMessage() . ' on ' . $e->getFile() . ' line ' . $e->getLine());
        }

        $this->clear();

        return $this;
    }

    /**
     * @param array $data
     */
    private function sendToCloudwatch(array $data)
    {
        $this->cloudWatch->putMetricData($data);
    }

    /**
     * @param array $data
     */
    private function sendToLog(array $data)
    {
        logger(json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * @return boolean
     */
    private function isEnabled()
    {
        return $this->enabled;
    }

    /**
     * On script shutdown
     */
    public function shutdown()
    {
        if ($this->sendOnShutdown) {
            $this->send();
        }
    }

    /**
     * @param string $name
     * @param string $value
     * @return Dimension
     */
    public function dimension($name, $value)
    {
        return new Dimension($name, $value);
    }
}