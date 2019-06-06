<?php

namespace CloudfrontProxyIPProvider;

use ArrayAccess;
use Concrete\Core\Config\Repository\Repository;
use Concrete\Core\Http\Client\Client as HttpClient;
use Exception;
use Illuminate\Support\Arr;
use ProxyIPManager\Provider\ProviderInterface;
use Throwable;

class Provider implements ProviderInterface
{
    /**
     * @var \Concrete\Core\Config\Repository\Repository
     */
    protected $config;

    /**
     * @var \Concrete\Core\Http\Client\Client
     */
    protected $httpClient;

    /**
     * Initialize the instance.
     *
     * @param \Concrete\Core\Config\Repository\Repository $config
     * @param \Concrete\Core\Http\Client\Client $httpClient
     */
    public function __construct(Repository $config, HttpClient $httpClient)
    {
        $this->config = $config;
        $this->httpClient = $httpClient;
    }

    /**
     * {@inheritdoc}
     *
     * @see \ProxyIPManager\Provider\ProviderInterface::getName()
     */
    public function getName()
    {
        return t('CloudFront');
    }

    /**
     * {@inheritdoc}
     *
     * @see \ProxyIPManager\Provider\ProviderInterface::getProxyIPs()
     */
    public function getProxyIPs(ArrayAccess $errors, array $configuration = null)
    {
        $endpoints = $this->getEndpoints();
        $result = [];
        foreach ($endpoints as $endpoint) {
            try {
                $result = array_merge($result, $this->getProxyIPsFromEndpoint($endpoint));
            } catch (Exception $x) {
                $errors[] = $x->getMessage();
            } catch (Throwable $x) {
                $errors[] = $x->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get the list of endpoints to be called to get the proxy IP addresses.
     *
     * @return string[]
     */
    protected function getEndpoints()
    {
        $endpoints = $this->config->get('cloudfront_proxy_ip_provider::endpoints.cloudfront-tools');
        if (!empty($endpoints)) {
            return is_array($endpoints) ? $endpoints : [$endpoints];
        }

        return $this->config->get('cloudfront_proxy_ip_provider::endpoints.fallback');
    }

    /**
     * @param string $endpoint
     *
     * @throws \Exception
     *
     * @return string[]
     */
    protected function getProxyIPsFromEndpoint($endpoint)
    {
        $response = $this->getEndpointContent($endpoint);
        $data = $this->decodeEndpointResponse($response);

        return $this->extractIPsFromEndpointResponse($data);
    }

    /**
     * Invoke an endpoint and return its response.
     *
     * @param string $endpoint
     *
     * @throws \Exception
     *
     * @return string
     */
    protected function getEndpointContent($endpoint)
    {
        try {
            $this->httpClient->reset()->setUri($endpoint);
        } catch (Exception $x) {
            throw new Exception(t('Failed to set the HTTP Client endpoint url to %1$s: %2$s', $endpoint, $x->getMessage()));
        }
        try {
            $response = $this->httpClient->send();
        } catch (Exception $x) {
            throw new Exception(t('Failed to send the HTTP request to %1$s: %2$s', $endpoint, $x->getMessage()));
        }
        if (!$response->isOk()) {
            throw new Exception(t('Bad response code (%1$s) from the HTTP request to %2$s.', $response->getStatusCode(), $endpoint));
        }

        return $response->getBody();
    }

    /**
     * Decode the raw response from an endpoint.
     *
     * @param string $response
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function decodeEndpointResponse($response)
    {
        $flags = 0;
        if (defined('JSON_THROW_ON_ERROR')) {
            $flags |= JSON_THROW_ON_ERROR;
        }
        $data = json_decode($response, true, 512, $flags);
        if (!is_array($data)) {
            throw new Exception(t('Failed to decode the response from an endpoint: %s', $response));
        }

        return $data;
    }

    /**
     * @param array $data
     *
     * @throws \Exception
     *
     * @return string[]
     */
    protected function extractIPsFromEndpointResponse(array $data)
    {
        if (isset($data['CLOUDFRONT_REGIONAL_EDGE_IP_LIST'])) {
            return $this->extractIPsFromEndpointResponseCF($data);
        }
        if (isset($data['prefixes'])) {
            return $this->extractIPsFromEndpointResponseAWS($data);
        }
        throw new Exception(t('Failed to extract IPs from the endpoint response'));
    }

    /**
     * @param array $data
     *
     * @return string[]
     */
    protected function extractIPsFromEndpointResponseCF(array $data)
    {
        return Arr::flatten($data);
    }

    /**
     * @param array $data
     *
     * @throws \Exception
     *
     * @return string[]
     */
    protected function extractIPsFromEndpointResponseAWS(array $data)
    {
        $result = [];
        foreach (Arr::get($data, 'prefixes', []) as $ip) {
            if (Arr::get($ip, 'service') === 'CLOUDFRONT') {
                $prefix = Arr::get($ip, 'ip_prefix');
                if ($prefix) {
                    $result[] = $prefix;
                }
            }
        }

        return $result;
    }
}
