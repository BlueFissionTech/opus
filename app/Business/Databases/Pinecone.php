<?php
namespace App\Business\Databases;

use BlueFission\Data\Storage\Storage;
use BlueFission\Connections\Curl;
use BlueFission\Net\HTTP;

// https://betterprogramming.pub/enhancing-chatgpt-with-infinite-external-memory-using-vector-database-and-chatgpt-retrieval-plugin-b6f4ea16ab8
class Pinecone extends Storage
{
    private $_client;
    private $_baseUrl;

    /**
     * @var array $_config The configuration options for the Pinecone storage class.
     */
    protected $_config = [
        'location'=>'https://api.pinecone.io/',
        'name'=>'',
        'project'=>'',
        'region'=>'',
        'apikey'=>'',
    ];

    protected $_data = [
        'id'=>'',
        'data'=>'',
    ];

    public function __construct($config = null)
    {
        parent::__construct($config);
        $this->_apiKey = $this->config('apikey');
        $this->_baseUrl = "https://".$this->config('name')."-".$this->config('project').".svc.".$this->config('region').".pinecone.io/";
        
        $this->_client = new Curl([
            'target' => $this->_baseUrl,
            'method' => 'post',
            'headers' => [
                'Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }

    private function sendRequest($endpoint, $data)
    {
        $this->client->config('target', $this->_baseUrl . $endpoint);
        $this->client->open();
        $this->client->query($data);
        $response = $this->client->result();
        $this->client->close();

        return HTTP::jsonDecode((string) $response, true);
    }

    public function createIndex($metric = "cosine")
    {
        return $this->sendRequest('index/create', [
            'index_name' => $indexName,
            'index_config' => [
                'metric' => $metric
            ]
        ]);
    }

    public function read()
    {
        $indexName = $this->config('name');
        $this->_data = $this->sendRequest("index/{$indexName}/fetch", [
            'ids' => $this->id
        ]);
    }

    public function write($items)
    {
        $indexName = $this->config('name');
        $this->sendRequest("index/{$indexName}/upsert", [
            'items' => $this->data
        ]);
    }

    public function delete()
    {
        $indexName = $this->config('name');
        $this->sendRequest("index/{$indexName}/delete", [
            'ids' => $this->id
        ]);
    }

    public function deleteIndex()
    {
        $this->sendRequest('index/delete', [
            'index_name' => $this->config('name')
        ]);
    }

    public function query($queries, $topK = 1)
    {
        $indexName = $this->config('name');
        return $this->sendRequest("index/{$indexName}/query", [
            'queries' => $queries,
            'top_k' => $topK
        ]);
    }
}
