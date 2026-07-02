<?php

declare(strict_types=1);

namespace Panel\Mail;

// Placeholder mailer. Swap this out for an SMTP or API-backed implementation of
// the Mailer interface to actually deliver messages.
final class LogMailer implements Mailer
{
    public function __construct(private readonly string $logFile, private readonly string $from)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $entry = date('c') . " to={$to} from={$this->from} subject={$subject}\n{$body}\n---\n";
        file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
