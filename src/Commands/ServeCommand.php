<?php

declare(strict_types=1);

namespace Naf\Websocket\Commands;

use Naf\CLI\Core\AbstractCommand;
use Naf\CLI\Core\Input;
use Naf\CLI\Core\Output;
use Naf\Websocket\Server\Server;

use function Naf\config;

/**
 * Run the socket server.
 *
 * Meant to be a supervised program beside the queue worker: it holds no state,
 * so being restarted costs its clients a reconnect and nothing else. That is
 * also why it does not trap signals -- the image has no pcntl, and a server with
 * nothing to flush has nothing to lose to a hard stop.
 *
 * @internal
 */
final class ServeCommand extends AbstractCommand
{
    public const string NAME = 'websocket:serve';

    protected function configure(): void
    {
        $this
            ->setTitle('Serve WebSocket connections')
            ->setDescription('Accept browser connections and fan out what the application publishes.')
            ->addOption('address')
            ->addOption('seconds');
    }

    public function run(Input $input, Output $output): int
    {
        if (!\Naf\Websocket\live()) {
            $output->writeLine(
                'Aus: websocket:enabled steht nicht auf true, oder es gibt keinen Schlüssel. '
                . 'Der Server startet nicht, und die Anwendung sagt keinem Browser, wo er sich '
                . 'verbinden soll.',
                'warning',
            );

            return self::SUCCESS;
        }

        $key = (string) config('websocket:key', '');
        if ($key === '') {
            $output->writeLine(
                'Kein Schlüssel für Tickets. Ohne ihn kann der Server keine Verbindung prüfen; '
                . 'setze websocket:key.',
                'error',
            );

            return self::FAILURE;
        }

        $certificate = (string) config('websocket:certificate', '');
        $tls         = $certificate === '' ? null : array_filter([
            'local_cert' => $certificate,
            'local_pk'   => (string) config('websocket:key_file', '') ?: null,
        ]);

        if ($tls === null) {
            $output->writeLine(
                'Ohne Zertifikat spricht der Server ws:// -- eine Seite auf https darf das nicht '
                . 'öffnen. Für alles außer lokaler Entwicklung gehört hier ein Zertifikat hin.',
                'warning',
            );
        }

        $server = new Server(
            (string) ($input->getOption('address') ?: config('websocket:address', '0.0.0.0:8091')),
            (string) config('websocket:control', '/tmp/naf-websocket.sock'),
            $key,
            array_values((array) config('websocket:origins', [])),
            $tls,
            static fn(string $line) => $output->writeLine($line),
            (int) config('websocket:max_connections', 2000),
        );

        $server->listen();
        $seconds = $input->getOption('seconds');
        $server->run($seconds === null ? null : (float) $seconds);

        return self::SUCCESS;
    }
}
