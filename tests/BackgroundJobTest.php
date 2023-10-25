<?php

namespace Jobby\Tests;

use Jobby\BackgroundJob;
use Jobby\Helper;
use Opis\Closure\SerializableClosure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @coversDefaultClass BackgroundJob
 */
class BackgroundJobTest extends TestCase
{
    const JOB_NAME = 'name';

    private string $logFile;

    private Helper $helper;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->logFile = __DIR__ . '/_files/BackgroundJobTest.log';
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }

        $this->helper = new Helper();
    }

    /**
     * {@inheritdoc}
     */
    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public static function runProvider(): array
    {
        $echo = function () {
            echo 'test';

            return true;
        };
        $uid = function () {
            echo getmyuid();

            return true;
        };
        $job = ['closure' => $echo];

        return [
            'diabled, not run'       => [$job + ['enabled' => false], ''],
            'normal job, run'         => [$job, 'test'],
            'wrong host, not run'    => [$job + ['runOnHost' => 'something that does not match'], ''],
            'current user, run,'     => [['closure' => $uid], getmyuid()],
        ];
    }

    /**
     * @covers ::getConfig
     */
    public function testGetConfig(): void
    {
        $job = new BackgroundJob('test job', []);
        $this->assertIsArray($job->getConfig());
    }

    /**
     * @dataProvider runProvider
     *
     * @covers ::runt
     */
    public function testRun(array $config, mixed $expectedOutput): void
    {
        $this->runJob($config);

        $this->assertEquals($expectedOutput, $this->getLogContent());
    }

    /**
     * @covers ::runFile
     */
    public function testInvalidCommand(): void
    {
        $this->runJob(['command' => 'invalid-command']);

        $this->assertStringContainsString('invalid-command', $this->getLogContent());

        if ($this->helper->getPlatform() === Helper::UNIX) {
            $this->assertStringContainsString('not found', $this->getLogContent());
            $this->assertStringContainsString(
                "ERROR: Job exited with status '127'",
                $this->getLogContent()
            );
        } else {
            $this->assertStringContainsString(
                'not recognized as an internal or external command',
                $this->getLogContent()
            );
        }
    }

    /**
     * @covers ::runFunction
     */
    public function testClosureNotReturnTrue(): void
    {
        $this->runJob(
            [
                'closure' => function () {
                    return false;
                },
            ]
        );

        $this->assertStringContainsString(
            'ERROR: Closure did not return true! Returned:',
            $this->getLogContent()
        );
    }

    /**
     * @covers ::getLogFile
     */
    public function testHideStdOutByDefault(): void
    {
        ob_start();
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => null,
            ]
        );
        $content = ob_get_contents();
        ob_end_clean();

        $this->assertEmpty($content);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldCreateLogFolder(): void
    {
        $logfile = dirname($this->logFile) . '/foo/bar.log';
        $this->runJob(
            [
                'closure' => function () {
                    echo 'foo bar';
                },
                'output'  => $logfile,
            ]
        );

        $dirExists = file_exists(dirname($logfile));
        $isDir = is_dir(dirname($logfile));

        unlink($logfile);
        rmdir(dirname($logfile));

        $this->assertTrue($dirExists);
        $this->assertTrue($isDir);
    }

    /**
     * @covers ::getLogFile
     */
    public function testShouldSplitStderrAndStdout(): void
    {
        $dirname = dirname($this->logFile);
        $stdout = $dirname . '/stdout.log';
        $stderr = $dirname . '/stderr.log';
        $this->runJob(
            [
                'command' => "(echo \"stdout output\" && (>&2 echo \"stderr output\"))",
                'output_stdout' => $stdout,
                'output_stderr' => $stderr,
            ]
        );

        $this->assertStringContainsString('stdout output', @file_get_contents($stdout));
        $this->assertStringContainsString('stderr output', @file_get_contents($stderr));

        unlink($stderr);
        unlink($stdout);

    }

    /**
     * @covers ::mail
     */
    public function testNotSendMailOnMissingRecipients()
    {
        $helper = $this->createPartialMock(Helper::class, ['sendMail']);
        $helper->expects($this->never())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure'    => function () {
                    return false;
                },
                'recipients' => '',
            ],
            $helper
        );
    }

    /**
     * @covers ::mail
     */
    public function testMailShouldTriggerHelper()
    {
        $helper = $this->createPartialMock(Helper::class, ['sendMail']);
        $helper->expects($this->once())
            ->method('sendMail')
        ;

        $this->runJob(
            [
                'closure' => function () {
                    return false;
                },
                'recipients' => 'test@example.com',
            ],
            $helper
        );
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntime()
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createMock(Helper::class);
        $helper->expects($this->once())
            ->method('getLockLifetime')
            ->willReturn(0)
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        $this->assertEmpty($this->getLogContent());
    }

    /**
     * @covers ::checkMaxRuntime
     */
    public function testCheckMaxRuntimeShouldFailIsExceeded(): void
    {
        if ($this->helper->getPlatform() !== Helper::UNIX) {
            $this->markTestSkipped("'maxRuntime' is not supported on Windows");
        }

        $helper = $this->createMock(Helper::class);
        $helper->expects($this->once())
            ->method('getLockLifetime')
            ->willReturn(2)
        ;

        $this->runJob(
            [
                'command'    => 'true',
                'maxRuntime' => 1,
            ],
            $helper
        );

        $this->assertStringContainsString(
            'MaxRuntime of 1 secs exceeded! Current runtime: 2 secs',
            $this->getLogContent()
        );
    }

    /**
     * @dataProvider haltDirProvider
     * @covers       ::shouldRun
     */
    public function testHaltDir(bool $createFile, bool $jobRuns): void
    {
        $dir = __DIR__ . '/_files';
        $file = $dir . '/' . static::JOB_NAME;

        $fs = new Filesystem();

        if ($createFile) {
            $fs->touch($file);
        }

        $this->runJob(
            [
                'haltDir' => $dir,
                'closure' => function () {
                    echo 'test';

                    return true;
                },
            ]
        );

        if ($createFile) {
            $fs->remove($file);
        }

        $content = $this->getLogContent();
        $this->assertEquals($jobRuns, is_string($content) && !empty($content));
    }

    public static function haltDirProvider(): array
    {
        return [
            [true, false],
            [false, true],
        ];
    }

    private function runJob(array $config, Helper $helper = null): void
    {
        $config = $this->getJobConfig($config);

        $job = new BackgroundJob(self::JOB_NAME, $config, $helper);
        $job->run();
    }

    private function getJobConfig(array $config): array
    {
        $helper = new Helper();

        if (isset($config['closure'])) {
            $wrapper = new SerializableClosure($config['closure']);
            $config['closure'] = serialize($wrapper);
        }

        return array_merge(
            [
                'enabled'    => 1,
                'haltDir'    => null,
                'runOnHost'  => $helper->getHost(),
                'dateFormat' => 'Y-m-d H:i:s',
                'schedule'   => '* * * * *',
                'output'     => $this->logFile,
                'maxRuntime' => null,
                'runAs'      => null,
            ],
            $config
        );
    }

    private function getLogContent(): ?string
    {
        return @file_get_contents($this->logFile);
    }
}
