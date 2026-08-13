<?php

namespace Fleetbase\Support\SocketCluster;

use WebSocket\Client;

/**
 * Class SocketClusterService.
 *
 * Service class for managing SocketCluster connections and messages.
 */
class SocketClusterService
{
    /**
     * WebSocket client instance.
     */
    protected Client $client;

    /**
     * The URI for the WebSocket connection.
     */
    protected string $uri;

    /**
     * Connection options.
     */
    protected array $options = [];

    /**
     * Indicates whether the message was sent.
     */
    protected ?bool $sent = null;

    /**
     * The response received, if any.
     */
    protected ?string $response = null;

    /**
     * Error message, if any.
     */
    protected ?string $error = null;

    /**
     * Indicates whether the handshake was sent.
     */
    protected ?bool $handshakeSent = null;

    /**
     * The handshake response received, if any.
     */
    protected ?string $handshakeResponse = null;

    /**
     * Handshake error message, if any.
     */
    protected ?string $handshakeError = null;

    /**
     * Socket protocol error statuses.
     */
    public array $socketProtocolErrorStatuses = [
        1001 => 'Socket was disconnected',
        1002 => 'A WebSocket protocol error was encountered',
        1003 => 'Server terminated socket because it received invalid data',
        1005 => 'Socket closed without status code',
        1006 => 'Socket hung up',
        1007 => 'Message format was incorrect',
        1008 => 'Encountered a policy violation',
        1009 => 'Message was too big to process',
        1010 => 'Client ended the connection because the server did not comply with extension requirements',
        1011 => 'Server encountered an unexpected fatal condition',
        4000 => 'Server ping timed out',
        4001 => 'Client pong timed out',
        4002 => 'Server failed to sign auth token',
        4003 => 'Failed to complete handshake',
        4004 => 'Client failed to save auth token',
        4005 => 'Did not receive #handshake from client before timeout',
        4006 => 'Failed to bind socket to message broker',
        4007 => 'Client connection establishment timed out',
        4008 => 'Server rejected handshake from client',
        4009 => 'Server received a message before the client handshake',
    ];

    /**
     * Create a new SocketClusterService instance.
     *
     * @param array|string $options connection options or URI
     */
    public function __construct($options = [])
    {
        $this->options = $options = $this->getOptions($options);
        $this->uri     = $uri = $this->parseOptions($options);
        $this->client  = new Client($uri, $options);
    }

    /**
     * Get the WebSocket client instance.
     *
     * @return Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Get the WebSocket server URI.
     *
     * @return Client
     */
    public function getUri()
    {
        return $this->uri;
    }

    /**
     * Create a new SocketClusterService instance.
     *
     * @param array|string $options connection options or URI
     */
    public static function instance($options = []): SocketClusterService
    {
        return new static($options);
    }

    /**
     * Publishes a message to the specified channel.
     *
     * @param string $channel the channel to publish the message to
     * @param array  $data    the data to send in the message (optional)
     * @param array  $options additional options for the message (optional)
     *
     * @return bool returns true if the message was sent successfully, false otherwise
     */
    public static function publish($channel, array $data = [], $options = []): bool
    {
        return static::instance($options)->send($channel, $data);
    }

    /**
     * Whether the socketcluster handshake has completed on the current connection.
     *
     * @var bool
     */
    protected bool $handshakeCompleted = false;

    /**
     * Sends a message to the specified channel.
     *
     * PATCHED: keeps the websocket connection open and reuses it across
     * publishes instead of handshake → publish → close per channel (which
     * made every broadcast event cost 4 full connections and saturated the
     * queue worker under sustained driver location updates).
     *
     * @param string $channel the channel to send the message to
     * @param array  $data    the data to send in the message (optional)
     *
     * @return bool returns true if the message was sent successfully, false otherwise
     *
     * @throws \WebSocket\ConnectionException if there is a connection error
     * @throws \WebSocket\TimeoutException    if the operation times out
     * @throws \Throwable                     if any other error occurs
     */
    public function send($channel, array $data = []): bool
    {
        $cid        = rand();
        $message    = new SocketClusterMessage($channel, $data, $cid);
        $this->sent = false;

        try {
            $this->ensureConnected();
            $this->client->send($message);
            $this->response = $this->client->receive();
            $this->sent     = true;
        } catch (\WebSocket\ConnectionException $e) {
            $this->error = $e->getMessage();
            $this->disconnect();
        } catch (\WebSocket\TimeoutException $e) {
            $this->error = $e->getMessage();
            $this->disconnect();
        } catch (\Throwable $e) {
            $this->error = $e->getMessage();
            $this->disconnect();
        }

        return $this->sent;
    }

    /**
     * Ensures the websocket connection is open and the socketcluster handshake
     * has been performed. The phrity client auto-reconnects on send() if the
     * connection was closed, so a dead connection self-heals here.
     *
     * @return void
     */
    protected function ensureConnected(): void
    {
        if (!$this->client->isConnected()) {
            $this->handshakeCompleted = false;
        }

        if (!$this->handshakeCompleted) {
            $this->handshake(rand());
            $this->handshakeCompleted = (bool) $this->handshakeSent;

            if (!$this->handshakeCompleted) {
                throw new \WebSocket\ConnectionException('SocketCluster handshake failed: ' . ($this->handshakeError ?? 'unknown error'));
            }
        }
    }

    /**
     * Closes the current connection and resets handshake state so the next
     * send() re-establishes it.
     *
     * @return void
     */
    protected function disconnect(): void
    {
        try {
            $this->client->close();
        } catch (\Throwable $ignored) {
            // connection already dead
        }

        $this->handshakeCompleted = false;
    }

    /**
     * Sends a handshake message to the SocketCluster server.
     *
     * @param int $cid the connection ID for the handshake
     *
     * @return bool returns true if the handshake was sent successfully, false otherwise
     *
     * @throws \WebSocket\ConnectionException if there is a connection error
     * @throws \WebSocket\TimeoutException    if the operation times out
     * @throws \Throwable                     if any other error occurs
     */
    public function handshake($cid)
    {
        $handshake           = new SocketClusterHandshake($cid);
        $this->handshakeSent = false;

        try {
            $this->client->send($handshake);
            $this->handshakeResponse = $this->client->receive();
            $this->handshakeSent     = true;
        } catch (\WebSocket\ConnectionException $e) {
            $this->handshakeError = $e->getMessage();
        } catch (\WebSocket\TimeoutException $e) {
            $this->handshakeError = $e->getMessage();
        } catch (\Throwable $e) {
            $this->handshakeError = $e->getMessage();
        }

        return $this->handshakeSent;
    }

    /**
     * Get the error message, if any.
     */
    public function error(): ?string
    {
        return $this->error;
    }

    /**
     * Get the response received, if any.
     */
    public function response(): ?string
    {
        return $this->response;
    }

    /**
     * Get a specific option value.
     *
     * @param string $key     the option key
     * @param mixed  $default default value if the option is not set
     */
    public function getOption(string $key, $default = null)
    {
        $value = data_get($this->options, $key, $default);

        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }

    /**
     * Get the connection options.
     *
     * @param array|string $options connection options or URI
     */
    public function getOptions($options): array
    {
        $defaultConnectionOptions = config('broadcasting.connections.socketcluster.options', []);

        if (is_string($options)) {
            $options = parse_url($options);
        }

        return array_merge($defaultConnectionOptions, $options);
    }

    /**
     * Create a socket server URI from connection options provided.
     *
     * @param string|array $options
     *
     * @return void
     */
    protected function parseOptions($options): string
    {
        $default = [
            'scheme'   => '',
            'host'     => '',
            'port'     => '',
            'path'     => '',
            'query'    => [],
            'fragment' => '',
        ];

        $optArr = !is_array($options) ? parse_url($options) : $options;
        $optArr = array_merge($default, $optArr);

        if (isset($optArr['secure'])) {
            $scheme = ((bool) $optArr['secure']) ? 'wss' : 'ws';
        } else {
            $scheme = in_array($optArr['scheme'], ['wss', 'https']) ? 'wss' : 'ws';
        }

        $query = $optArr['query'];
        if (!is_array($query)) {
            parse_str($optArr['query'], $query);
        }

        $host  = trim($optArr['host'], '/');
        $port  = !empty($optArr['port']) ? ':' . $optArr['port'] : '';
        $path  = trim($optArr['path'], '/');
        $path  = !empty($path) ? $path . '/' : '';
        $query = count($query) ? '?' . http_build_query($query) : '';

        return sprintf('%s://%s%s/%s%s', $scheme, $host, $port, $path, $query);
    }
}
