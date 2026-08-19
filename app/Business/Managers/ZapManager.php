<?php
namespace App\Business\Managers;

use BlueFission\Net\HTTP;

class ZapManager {
    private $apiKey;
    private $apiUrl = 'https://api.zapier.com/v1/';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    private function callAPI($method, $endpoint, $data = []) {
        $url = $this->apiUrl . $endpoint;

        $options = [
            'http' => [
                'header'  => "Content-Type: application/json\r\nAuthorization: Basic " . base64_encode($this->apiKey . ":"),
                'method'  => $method,
                'content' => HTTP::jsonEncode($data)
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);
        if (!$result) {
            throw new \Exception('Error calling Zapier API: ' . $http_response_header[0]);
        }

        return HTTP::jsonDecode($result, true);
    }

    public function searchZaps($query) {
        $endpoint = 'zaps?search=' . urlencode($query);
        return $this->callAPI('GET', $endpoint);
    }

    public function createZap($name) {
        $endpoint = 'zaps';
        $data = [
            'name' => $name,
            'paused' => false
        ];
        return $this->callAPI('POST', $endpoint, $data);
    }

    public function configureZap($zapId, $stepKey, $config) {
        $endpoint = "zaps/{$zapId}/steps/{$stepKey}/config";
        return $this->callAPI('PUT', $endpoint, $config);
    }
}

// Usage example:

$apiKey = 'your_zapier_api_key_here';
$zapier = new Zapier($apiKey);

// Search for Zaps
$searchResults = $zapier->searchZaps('example');
print_r($searchResults);

// Create a new Zap
$newZap = $zapier->createZap('My New Zap');
print_r($newZap);

// Configure a step in a Zap
$zapId = $newZap['id'];
$stepKey = 'trigger'; // or 'action' depending on the step you want to configure
$config = [
    'example_key' => 'example_value'
];
$zapier->configureZap($zapId, $stepKey, $config);
