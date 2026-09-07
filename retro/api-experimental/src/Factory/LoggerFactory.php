<?php

namespace App\Factory;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class LoggerFactory
{
    private string $path;

    private int $level;

    private array $handler = [];

    private ?HandlerInterface $test = null;

    public function __construct(array $settings)
    {
        $this->path = $settings['path'] ?? 'vfs://root/logs';
        $this->level = (int) $settings['level'] ?? Logger::DEBUG;
        $this->test = $settings['test'] ?? null;
    }

    public function createLogger(string $name = null): LoggerInterface
    {
        if ($this->test) {
            $this->handler = [$this->test];
        }

        $logger = new Logger($name ?: Uuid::v4()->toRfc4122());

        foreach ($this->handler as $handler) {
            $logger->pushHandler($handler);
        }

        $this->handler = [];

        return $logger;
    }

    public function addHandler(HandlerInterface $handler): self
    {
        $this->handler[] = $handler;

        return $this;
    }

    public function addFileHandler(string $filename, int $level = null): self
    {
        $filename = sprintf('%s/%s', $this->path, $filename);
        $rotatingFileHandler = new RotatingFileHandler($filename, 0, $level ?? $this->level, true, 0777);

        // The last "true" here tells monolog to remove empty []'s
        $rotatingFileHandler->setFormatter(new LineFormatter(null, null, false, true));

        $this->addHandler($rotatingFileHandler);

        return $this;
    }

    public function addConsoleHandler(int $level = null): self
    {
        $streamHandler = new StreamHandler('php://output', $level ?? $this->level);
        $streamHandler->setFormatter(new LineFormatter(null, null, false, true));

        $this->addHandler($streamHandler);

        return $this;
    }
}