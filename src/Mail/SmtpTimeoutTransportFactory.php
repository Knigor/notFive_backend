<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

final readonly class SmtpTimeoutTransportFactory implements TransportFactoryInterface
{
    public function __construct(private TransportFactoryInterface $inner)
    {
    }

    public function supports(Dsn $dsn): bool
    {
        return $this->inner->supports($dsn);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        $transport = $this->inner->create($dsn);
        if ($transport instanceof SmtpTransport) {
            $stream = $transport->getStream();
            if ($stream instanceof SocketStream) {
                // Bound SMTP socket operations without changing HTTP/Go timeouts.
                $stream->setTimeout(5.0);
            }
        }

        return $transport;
    }
}
