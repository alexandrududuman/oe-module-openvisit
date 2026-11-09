<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Config\ModuleConfig;
use OpenVisit\OpenEMR\Modules\OpenVisit\Config\SettingsSource;
use OpenVisit\OpenEMR\Modules\OpenVisit\Exception\VisitException;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Appointment;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Recording;
use OpenVisit\OpenEMR\Modules\OpenVisit\Model\Visit;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingFiles;
use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingService;
use OpenVisit\OpenEMR\Modules\OpenVisit\Settings;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\FakeGateway;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryParticipantStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemoryRecordingStore;
use OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit\Fakes\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

final class RecordingServiceTest extends TestCase
{
    private string $root;
    private InMemoryRecordingStore $recordings;
    private InMemoryParticipantStore $participants;
    private InMemorySessionStore $sessions;
    private FakeGateway $gateway;
    private Visit $visit;
    /** @var array<string, string> */
    private array $settings = [Settings::ALLOW_RECORDING => '1'];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openvisit-rec-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/inbox', 0777, true);
        $this->recordings = new InMemoryRecordingStore();
        $this->participants = new InMemoryParticipantStore();
        $this->sessions = new InMemorySessionStore();
        $this->gateway = new FakeGateway();
        $this->gateway->appointments[42] = new Appointment(42, 7, 6, 11, '2026-11-09', '10:00:00', 900, 'Phil', 'Belford', 'Donna', 'Lee');
        $created = $this->sessions->create(42, 6, 'ov-room1');
        $this->sessions->markStarted($created->id, 500, '2026-11-09 10:00:00');
        $this->visit = $this->sessions->findById($created->id) ?? throw new \RuntimeException();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function service(): RecordingService
    {
        $settings = $this->settings;
        $source = new class ($settings) implements SettingsSource {
            /** @param array<string, string> $values */
            public function __construct(private readonly array $values) {}

            public function get(string $key): ?string
            {
                return $this->values[$key] ?? null;
            }
        };
        return new RecordingService(
            $this->recordings,
            $this->participants,
            $this->sessions,
            $this->gateway,
            new ModuleConfig($source, static fn(string $v): string => $v),
            new RecordingFiles($this->root . '/inbox', $this->root . '/storage'),
        );
    }

    private function join(string $role): int
    {
        return $this->participants->joined($this->visit->id, $role, $role, null, 'ua', '2026-11-09 10:00:00')->id;
    }

    public function testRecordingNeedsTheSetting(): void
    {
        $this->settings = [];
        $this->join('provider');
        $this->join('patient');

        $this->expectException(VisitException::class);
        $this->service()->start($this->visit, 6);
    }

    public function testRecordingNeedsAtLeastTwoPeopleInTheCall(): void
    {
        $this->join('provider');
        $this->join('provider');

        try {
            $this->service()->start($this->visit, 6);
            self::fail('a provider alone, even on two tabs, must not record');
        } catch (VisitException $e) {
            self::assertStringContainsString('two people', $e->getMessage());
        }
        self::assertSame([], $this->recordings->rows);
    }

    public function testPeopleWhoLeftDoNotCount(): void
    {
        $this->join('provider');
        $patient = $this->join('patient');
        $this->participants->left($patient, '2026-11-09 10:05:00');

        $this->expectException(VisitException::class);
        $this->service()->start($this->visit, 6);
    }

    public function testStartsWithTheProviderAndAPatientAndAuditsConsent(): void
    {
        $this->join('provider');
        $this->join('patient');

        $recording = $this->service()->start($this->visit, 6);

        self::assertSame(Recording::STATUS_STARTING, $recording->status);
        self::assertSame(6, $recording->startedBy);
        self::assertSame('openvisit-record-start', $this->gateway->audits[0][0]);
        self::assertStringContainsString('consent', $this->gateway->audits[0][1]);
        self::assertSame(7, $this->gateway->audits[0][2]);
    }

    public function testOnlyOneRecordingAtATimeAndOnlyWhileTheVisitRuns(): void
    {
        $this->join('provider');
        $this->join('guest');
        $service = $this->service();
        $first = $service->start($this->visit, 6);
        try {
            $service->start($this->visit, 6);
            self::fail('second live recording accepted');
        } catch (VisitException) {
        }

        $service->stopped($first->id, $this->visit->id);
        $this->sessions->markEnded($this->visit->id, '2026-11-09 10:30:00');
        $ended = $this->sessions->findById($this->visit->id) ?? throw new \RuntimeException();

        $this->expectException(VisitException::class);
        $service->start($ended, 6);
    }

    public function testStatusChangesFollowTheRoomAndStayWithinTheirVisit(): void
    {
        $this->join('provider');
        $this->join('patient');
        $service = $this->service();
        $recording = $service->start($this->visit, 6);

        $service->started($recording->id, 999);
        self::assertSame(Recording::STATUS_STARTING, $this->recordings->rows[$recording->id]->status);

        $service->started($recording->id, $this->visit->id);
        self::assertSame(Recording::STATUS_RECORDING, $this->recordings->rows[$recording->id]->status);
        self::assertSame('2026-11-09 10:00:00', $this->recordings->rows[$recording->id]->startedAt);

        $this->gateway->clock = $this->gateway->clock->modify('+2 minutes');
        $service->stopped($recording->id, $this->visit->id);
        self::assertSame(Recording::STATUS_PROCESSING, $this->recordings->rows[$recording->id]->status);
        self::assertSame('2026-11-09 10:02:00', $this->recordings->rows[$recording->id]->stoppedAt);
    }

    public function testAFailedStartIsRecordedAsFailed(): void
    {
        $this->join('provider');
        $this->join('patient');
        $service = $this->service();
        $recording = $service->start($this->visit, 6);

        $service->failed($recording->id, $this->visit->id);

        self::assertSame(Recording::STATUS_FAILED, $this->recordings->rows[$recording->id]->status);
    }

    private function drop(string $dir, string $room, string $duration = '61.2'): void
    {
        $path = $this->root . '/inbox/' . $dir;
        mkdir($path);
        file_put_contents("$path/{$room}_2026-11-09-10-00-00.mp4", str_repeat('v', 2048));
        file_put_contents("$path/metadata.json", json_encode(['meeting_url' => "https://meet.example.test/{$room}"]));
        file_put_contents("$path/.ready", $duration);
    }

    public function testImportAttachesFilesToTheOldestWaitingRecordingOfTheRoom(): void
    {
        $this->join('provider');
        $this->join('patient');
        $service = $this->service();
        $first = $service->start($this->visit, 6);
        $service->stopped($first->id, $this->visit->id);
        $second = $service->start($this->visit, 6);
        $this->drop('jibri-1', 'ov-room1');

        self::assertSame(1, $service->importReady());

        $ready = $this->recordings->rows[$first->id];
        self::assertSame(Recording::STATUS_READY, $ready->status);
        self::assertSame("{$this->visit->id}/{$first->id}.mp4", $ready->filePath);
        self::assertSame(2048, $ready->fileSize);
        self::assertSame(61, $ready->durationSeconds);
        self::assertFileExists($this->root . "/storage/{$this->visit->id}/{$first->id}.mp4");
        self::assertSame(Recording::STATUS_STARTING, $this->recordings->rows[$second->id]->status);
        self::assertSame($this->root . "/storage/{$this->visit->id}/{$first->id}.mp4", $service->fileOf($ready));
    }

    public function testImportKeepsAFileEvenWhenTheRoomNeverReportedTheRecording(): void
    {
        $this->drop('jibri-1', 'ov-room1');

        self::assertSame(1, $this->service()->importReady());

        $rows = $this->recordings->findBySession($this->visit->id);
        self::assertCount(1, $rows);
        self::assertSame(Recording::STATUS_READY, $rows[0]->status);
        self::assertSame(6, $rows[0]->startedBy);
    }

    public function testImportLeavesRecordingsOfUnknownRoomsAlone(): void
    {
        $this->drop('jibri-1', 'not-an-openvisit-room');

        self::assertSame(0, $this->service()->importReady());
        self::assertSame([], $this->recordings->rows);
        self::assertFileExists($this->root . '/inbox/jibri-1/not-an-openvisit-room_2026-11-09-10-00-00.mp4');
    }

    public function testRecordingsThatNeverStartedAreMarkedFailed(): void
    {
        $this->join('provider');
        $this->join('patient');
        $service = $this->service();
        $recording = $service->start($this->visit, 6);
        $this->gateway->clock = $this->gateway->clock->modify('+20 minutes');

        $service->importReady();

        self::assertSame(Recording::STATUS_FAILED, $this->recordings->rows[$recording->id]->status);
    }
}
