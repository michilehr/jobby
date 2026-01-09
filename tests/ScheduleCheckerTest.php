<?php

namespace Jobby\Tests;

use DateTimeImmutable;
use Jobby\ScheduleChecker;
use PHPUnit\Framework\TestCase;

class ScheduleCheckerTest extends TestCase
{
    private ScheduleChecker $scheduleChecker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduleChecker = new ScheduleChecker();
    }

    public function test_it_can_detect_a_due_job_from_a_datetime_string(): void
    {
        $this->assertTrue($this->scheduleChecker->isDue(date('Y-m-d H:i:s')));
    }

    public function test_it_can_detect_if_a_job_is_due_with_a_passed_in_DateTimeImmutable(): void
    {
        $scheduleChecker = new ScheduleChecker(new DateTimeImmutable("2017-01-02 13:14:59"));

        $this->assertTrue($scheduleChecker->isDue(date("2017-01-02 13:14:12")));
        $this->assertFalse($scheduleChecker->isDue(date("2017-01-02 13:15:00")));
    }

    public function test_it_can_detect_a_non_due_job_from_a_datetime_string(): void
    {
        $this->assertFalse($this->scheduleChecker->isDue(date('Y-m-d H:i:s', strtotime('tomorrow'))));
    }

    public function test_it_can_detect_a_due_job_from_a_cron_expression(): void
    {
        $this->assertTrue($this->scheduleChecker->isDue("* * * * *"));
    }

    public function test_it_can_detect_a_due_job_from_a_non_trivial_cron_expression(): void
    {
        $scheduleChecker = new ScheduleChecker(new DateTimeImmutable("2017-04-01 00:00:00"));

        $this->assertTrue($scheduleChecker->isDue("0 0 1 */3 *"));
    }

    public function test_it_can_detect_a_non_due_job_from_a_cron_expression(): void
    {
        $hour = date("H", strtotime('+1 hour'));
        $this->assertFalse($this->scheduleChecker->isDue("* {$hour} * * *"));
    }

    public function test_it_can_use_a_closure_to_detect_a_due_job(): void
    {
        $this->assertTrue(
            $this->scheduleChecker->isDue(function() {
                return true;
            })
        );
    }

    public function test_it_can_use_a_closure_to_detect_a_non_due_job(): void
    {
        $this->assertFalse(
            $this->scheduleChecker->isDue(function() {
                return false;
            })
        );
    }

    public function test_it_can_detect_if_a_job_is_due_with_a_passed_in_DateTimeImmutable_from_a_cron_expression(): void
    {
        $scheduleChecker = new ScheduleChecker(new DateTimeImmutable("2017-01-02 18:14:59"));

        $this->assertTrue($scheduleChecker->isDue("* 18 * * *"));
    }
}
