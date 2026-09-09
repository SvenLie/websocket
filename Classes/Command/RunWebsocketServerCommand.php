<?php

declare(strict_types=1);

namespace SvenLie\Websocket\Command;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Redis\Connection\RedisConnector;
use Amp\Redis\Connection\SocketRedisConnector;
use Amp\Redis\RedisConfig;
use Amp\Redis\RedisSubscriber;
use Amp\Socket\ClientTlsContext;
use Amp\Socket\ConnectContext;
use Amp\Socket\InternetAddress;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Composer\InstalledVersions;
use Psr\Log\LoggerInterface;
use SvenLie\Websocket\Configuration\WebsocketConfiguration;
use SvenLie\Websocket\Domain\Enum\ChannelType;
use SvenLie\Websocket\Handler\RoutableWebsocketClientHandlerInterface;
use SvenLie\Websocket\Handler\RoutingWebsocketClientHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Amp\async;
use function Amp\Redis\createRedisConnector;
use function Amp\Socket\connectTls;
use function Amp\trapSignal;

#[AsCommand(
    name: 'websocketServer:run',
    description: 'Run the WebSocket server'
)]
class RunWebsocketServerCommand extends Command
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RoutingWebsocketClientHandler $clientHandler,
        private readonly WebsocketConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'TCP port to listen on', '8080')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Reconcile poll interval in seconds (safety net; real-time push is via Redis pub/sub)', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->writeln('Starting WebSocket Server');

        if (!InstalledVersions::isInstalled('amphp/websocket-server')) {
            $io->error('Missing dependency for the WebSocket server. Run: composer require amphp/websocket-server');
            return Command::FAILURE;
        }

        $port = (int)$input->getOption('port');

        if ($port < 0 || $port > 65535) {
            $io->error('Port must be between 0 and 65535');
            return Command::FAILURE;
        }

        $interval = max(1.0, (float)$input->getOption('interval'));
        $handler = $this->clientHandler;

        $server = SocketHttpServer::createForDirectAccess($this->logger);
        $server->expose(new InternetAddress('0.0.0.0', $port));

        $websocket = new Websocket(
            $server,
            $this->logger,
            new Rfc6455Acceptor(),
            $handler
        );

        $pubSubActive = $this->startRedisSubscription($handler, $io);

        $server->start($websocket, new DefaultErrorHandler());

        $io->success(sprintf(
            'Notification WebSocket server listening on :%d (push: %s, reconcile every %.0fs)',
            $port,
            $pubSubActive ? 'Redis pub/sub' : 'poll only — amphp/redis missing',
            $interval,
        ));

        try {
            trapSignal([
                \SIGINT,
                \SIGTERM,
            ]);
        } catch (\Throwable) {
            (new DeferredFuture())->getFuture()->await();
        }

        $server->stop();

        return Command::SUCCESS;
    }

    protected function startRedisSubscription(RoutingWebsocketClientHandler $handler, SymfonyStyle $io): bool
    {
        if (!InstalledVersions::isInstalled('amphp/redis')) {
            $io->error('Missing dependency for the WebSocket server. Run: composer require amphp/redis');
            return false;
        }

        $connector = $this->buildRedisConnector();

        // Collect every channel a registered handler wants to listen on and map
        // it back to the dispatcher that should process the payload.
        $subscriptions = [];
        foreach ($handler->getHandlers() as $clientHandler) {
            $channelIdentifier = $clientHandler->getIdentifier();
            if ($channelIdentifier === null) {
                continue;
            }

            foreach ($this->dispatchers() as $suffix => $dispatch) {
                $channel = ChannelType::from($suffix)->channelFor($channelIdentifier);
                $subscriptions[$channel] = [$clientHandler, $dispatch];
            }
        }

        if ($subscriptions === []) {
            $io->writeln('No Redis broadcast/unicast channels registered — running in poll-only mode.');
            return false;
        }

        $subscriber = new RedisSubscriber($connector);
        foreach ($subscriptions as $channel => [$clientHandler, $dispatch]) {
            $this->subscribe($channel, $clientHandler, $dispatch, $subscriber, $io);
            $io->writeln(sprintf('Subscribed to Redis channel "%s".', $channel));
        }

        return true;
    }

    private function buildRedisConnector(): RedisConnector
    {
        $rawHost = $this->configuration->getRedisHost();
        $port = (string)$this->configuration->getRedisPort();
        $redisPassword = $this->configuration->getRedisPassword();

        $host = $rawHost;
        $useTls = false;
        if (preg_match('#^(?<scheme>[a-z0-9.]+)://(?<host>.+)$#i', $rawHost, $matches) === 1) {
            $scheme = strtolower($matches['scheme']);
            $host = $matches['host'];
            $useTls = str_starts_with($scheme, 'tls') || str_starts_with($scheme, 'ssl') || $scheme === 'rediss';
        }

        $this->logger->info(sprintf(
            'Connecting to Redis at %s:%s (TLS: %s)',
            $host,
            $port,
            $useTls ? 'on' : 'off',
        ));

        $config = RedisConfig::fromUri(sprintf('redis://%s:%s', $host, $port));
        if ($redisPassword !== '') {
            $config = $config->withPassword($redisPassword);
        }

        if (!$useTls) {
            return createRedisConnector($config);
        }

        $connectContext = (new ConnectContext())
            ->withConnectTimeout($config->getTimeout())
            ->withTlsContext(new ClientTlsContext($host));

        $tlsSocketConnector = new class () implements SocketConnector {
            public function connect(
                SocketAddress|string $uri,
                ?ConnectContext $context = null,
                ?Cancellation $cancellation = null
            ): Socket {
                return connectTls($uri, $context, $cancellation);
            }
        };

        $redisConnector = new SocketRedisConnector($config->getConnectUri(), $connectContext, $tlsSocketConnector);

        // createRedisConnector still wraps the connector with the password/database
        // handling derived from $config.
        return createRedisConnector($config, $redisConnector);
    }

    /**
     * Maps a {@see ChannelType} value to the dispatcher that forwards the payload
     * to the matching handler method. Add an entry here to support an additional
     * channel type.
     *
     * @return array<string, \Closure(RoutableWebsocketClientHandlerInterface, string): void>
     */
    private function dispatchers(): array
    {
        return [
            ChannelType::Broadcast->value => static function (RoutableWebsocketClientHandlerInterface $clientHandler, string $payload): void {
                $clientHandler->handleBroadcast($payload);
            },
            ChannelType::Unicast->value => static function (RoutableWebsocketClientHandlerInterface $clientHandler, string $payload): void {
                $clientHandler->handleUnicast($payload);
            },
        ];
    }

    /**
     * Subscribe to a single Redis channel and dispatch each received payload to
     * the given handler.
     *
     * @param \Closure(RoutableWebsocketClientHandlerInterface, string): void $dispatch
     */
    protected function subscribe(string $channel, RoutableWebsocketClientHandlerInterface $clientHandler, \Closure $dispatch, RedisSubscriber $subscriber, SymfonyStyle $io): void
    {
        async(function () use ($channel, $clientHandler, $dispatch, $subscriber, $io): void {
            try {
                foreach ($subscriber->subscribe($channel) as $payload) {
                    $dispatch($clientHandler, $payload);
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Redis subscription failed for channel "%s": %s', $channel, $e->getMessage()));
            }
        });
    }
}
