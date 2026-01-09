<?php
namespace Jobby;

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mailer\Transport\SendmailTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class Helper
{
    const UNIX = 0;

    const WINDOWS = 1;

    /**
     * @var resource[]
     */
    private $lockHandles = [];

    private ?MailerInterface $mailer;

    public function __construct(MailerInterface $mailer = null)
    {
        $this->mailer = $mailer;
    }

    public function sendMail(string $job, array $config, string $message): Email
    {
        $host = $this->getHost();
        $body = <<<EOF
$message

You can find its output in {$config['output']} on $host.

Best,
jobby@$host
EOF;

        $mailer = $this->getCurrentMailer($config);

        $mail = new Email();
        foreach (explode(',', $config['recipients']) as $recipient) {
            $mail->addTo($recipient);
        }
        $mail->subject("[$host] '{$job}' needs some attention!");
        $mail->text($body);
        $mail->from(new Address($config['smtpSender'], $config['smtpSenderName']));
        $mail->sender(new Address($config['smtpSender']));

        $mailer->send($mail);

        return $mail;
    }

    private function getCurrentMailer(array $config): MailerInterface
    {
        if ($this->mailer !== null) {
            return $this->mailer;
        }

        if (array_key_exists('mailerDsn', $config)) {
            $dsn = $config['mailerDsn'];
            $transport = Transport::fromDsn($dsn);
        } else if ($config['mailer'] === 'smtp') {
            $dsn = sprintf(
                "smtp://%s:%s@%s:%s",
                $config['smtpUsername'],
                $config['smtpPassword'],
                $config['smtpHost'],
                $config['smtpPort']
            );
            $transport = Transport::fromDsn($dsn);
        } else {
            $transport = new SendmailTransport();
        }

        return new Mailer($transport);
    }

    /**
     * @throws Exception
     * @throws InfoException
     */
    public function acquireLock(string $lockFile): void
    {
        if (array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock already acquired (Lockfile: $lockFile).");
        }

        if (!file_exists($lockFile) && !touch($lockFile)) {
            throw new Exception("Unable to create file (File: $lockFile).");
        }

        $fh = fopen($lockFile, 'rb+');
        if ($fh === false) {
            throw new Exception("Unable to open file (File: $lockFile).");
        }

        $attempts = 5;
        while ($attempts > 0) {
            if (flock($fh, LOCK_EX | LOCK_NB)) {
                $this->lockHandles[$lockFile] = $fh;
                ftruncate($fh, 0);
                fwrite($fh, getmypid());

                return;
            }
            usleep(250);
            --$attempts;
        }

        throw new InfoException("Job is still locked (Lockfile: $lockFile)!");
    }

    /**
     * @throws Exception
     */
    public function releaseLock(string $lockFile)
    {
        if (!array_key_exists($lockFile, $this->lockHandles)) {
            throw new Exception("Lock NOT held - bug? Lockfile: $lockFile");
        }

        if ($this->lockHandles[$lockFile]) {
            ftruncate($this->lockHandles[$lockFile], 0);
            flock($this->lockHandles[$lockFile], LOCK_UN);
        }

        unset($this->lockHandles[$lockFile]);
    }

    public function getLockLifetime(string $lockFile): int
    {
        if (!file_exists($lockFile)) {
            return 0;
        }

        $pid = file_get_contents($lockFile);
        if (empty($pid)) {
            return 0;
        }

        if (!posix_kill((int) $pid, 0)) {
            return 0;
        }

        $stat = stat($lockFile);

        return (time() - $stat['mtime']);
    }

    public function getTempDir(): string
    {
        // @codeCoverageIgnoreStart
        if (function_exists('sys_get_temp_dir')) {
            $tmp = sys_get_temp_dir();
        } elseif (!empty($_SERVER['TMP'])) {
            $tmp = $_SERVER['TMP'];
        } elseif (!empty($_SERVER['TEMP'])) {
            $tmp = $_SERVER['TEMP'];
        } elseif (!empty($_SERVER['TMPDIR'])) {
            $tmp = $_SERVER['TMPDIR'];
        } else {
            $tmp = getcwd();
        }
        // @codeCoverageIgnoreEnd

        return $tmp;
    }

    public function getHost(): string
    {
        return php_uname('n');
    }

    public function getApplicationEnv(): string|null
    {
        return $_SERVER['APPLICATION_ENV'] ?? null;
    }

    public function getPlatform(): int
    {
        if (strncasecmp(PHP_OS, 'Win', 3) === 0) {
            // @codeCoverageIgnoreStart
            return self::WINDOWS;
            // @codeCoverageIgnoreEnd
        }

        return self::UNIX;
    }

    public function escape(string $input): string
    {
        $input = strtolower($input);
        $input = preg_replace('/[^a-z0-9_. -]+/', '', $input);
        $input = trim($input);
        $input = str_replace(' ', '_', $input);
        $input = preg_replace('/_{2,}/', '_', $input);

        return $input;
    }

    public function getSystemNullDevice(): string
    {
        $platform = $this->getPlatform();
        if ($platform === self::UNIX) {
            return '/dev/null';
        }
        return 'NUL';
    }
}
