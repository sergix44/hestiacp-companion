<?php

namespace App\Certificates\Resolver;

use AcmePhp\Core\Challenge\Dns\DnsResolverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

class DohDnsResolver implements DnsResolverInterface
{
    private ClientInterface|Client $client;

    public function __construct(ClientInterface $client = null)
    {
        $this->client = $client ?: new Client();
    }

    public function getTxtEntries($domain): array
    {
        $entries = [];

        $response = $this->client->get("https://dns.google/resolve?name=$domain&type=TXT")->getBody()->getContents();

        $json = json_decode($response);

        foreach ($json?->Answer ?? [] as $answer) {
            if ($answer?->type === 16) {
                $entries[] = $answer?->data;
            }
        }

        return array_unique($entries);
    }

    public static function isSupported(): bool
    {
        return true;
    }
}
