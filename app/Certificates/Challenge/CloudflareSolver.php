<?php

namespace App\Certificates\Challenge;

use AcmePhp\Core\Challenge\ConfigurableServiceInterface;
use AcmePhp\Core\Challenge\Dns\DnsDataExtractor;
use AcmePhp\Core\Challenge\SolverInterface;
use AcmePhp\Core\Protocol\AuthorizationChallenge;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class CloudflareSolver implements SolverInterface, ConfigurableServiceInterface
{
    private string $apiToken;
    private string $zone;

    private array $ids = [];

    private DnsDataExtractor $extractor;

    private ClientInterface $client;

    public function __construct(DnsDataExtractor $extractor = null, ClientInterface $client = null)
    {
        $this->extractor = $extractor ?: new DnsDataExtractor();
        $this->client = $client ?: new Client();
    }

    public function supports(AuthorizationChallenge $authorizationChallenge): bool
    {
        return $authorizationChallenge->getType() === 'dns-01';
    }

    public function solve(AuthorizationChallenge $authorizationChallenge)
    {
        $recordName = $this->extractor->getRecordName($authorizationChallenge);
        $recordValue = $this->extractor->getRecordValue($authorizationChallenge);

        $response = $this->client->post(
            "https://api.cloudflare.com/client/v4/zones/$this->zone/dns_records",
            [
                'json' => [
                    'type' => 'TXT',
                    'name' => $recordName,
                    'content' => $recordValue,
                    'ttl' => 1,
                    'comment' => 'hcpc_challenge'
                ],
                'headers' => [
                    'Authorization' => "Bearer $this->apiToken",
                    'Content-Type' => 'application/json',
                ],
            ]
        );

        $json = json_decode($response->getBody()->getContents());

        $this->ids[] = $json->result->id;
    }

    public function cleanup(AuthorizationChallenge $authorizationChallenge)
    {
        foreach ($this->ids as $id) {
            $this->client->delete(
                "https://api.cloudflare.com/client/v4/zones/$this->zone/dns_records/$id",
                [
                    'headers' => [
                        'Authorization' => "Bearer $this->apiToken",
                        'Content-Type' => 'application/json',
                    ],
                ]
            );
        }
    }

    public function configure(array $config)
    {
        $this->apiToken = $config['api_token'];
        $this->zone = $config['zone_id'];
    }
}
