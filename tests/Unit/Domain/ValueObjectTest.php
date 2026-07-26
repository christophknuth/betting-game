<?php

declare(strict_types=1);

namespace BettingGame\Tests\Unit\Domain;

use BettingGame\Domain\ValueObject\ParticipantId;
use BettingGame\Domain\ValueObject\EventId;
use BettingGame\Domain\ValueObject\PredictionData;
use BettingGame\Domain\ValueObject\DisplayName;
use BettingGame\Domain\ValueObject\Email;
use BettingGame\Domain\ValueObject\GameStatus;
use BettingGame\Domain\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ValueObjectTest extends TestCase
{
    public function testParticipantIdValidation(): void
    {
        $id = new ParticipantId(123);
        $this->assertEquals(123, $id->value());
    }

    public function testParticipantIdRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ParticipantId(0);
    }

    public function testParticipantIdRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ParticipantId(-5);
    }

    public function testEventIdValidation(): void
    {
        $id = new EventId(456);
        $this->assertEquals(456, $id->value());
    }

    public function testPredictionDataValidation(): void
    {
        $data = new PredictionData(['homeScore' => 2, 'awayScore' => 1]);
        $this->assertEquals(['homeScore' => 2, 'awayScore' => 1], $data->toArray());
    }

    public function testPredictionDataRejectsEmptyArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PredictionData([]);
    }

    public function testPredictionDataJsonSerialization(): void
    {
        $data = new PredictionData(['homeScore' => 2]);
        $json = $data->toJson();
        
        $this->assertJson($json);
        $this->assertEquals('{"homeScore":2}', $json);
    }

    public function testPredictionDataFromJson(): void
    {
        $data = PredictionData::fromJson('{"homeScore":3,"awayScore":1}');
        $this->assertEquals(['homeScore' => 3, 'awayScore' => 1], $data->toArray());
    }

    public function testPredictionDataRejectsInvalidJson(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PredictionData::fromJson('invalid json');
    }

    public function testDisplayNameValidation(): void
    {
        $name = new DisplayName('John Doe');
        $this->assertEquals('John Doe', $name->value());
    }

    public function testDisplayNameTrimming(): void
    {
        $name = new DisplayName('  John Doe  ');
        $this->assertEquals('John Doe', $name->value());
    }

    public function testDisplayNameRejectsTooShort(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisplayName('A');
    }

    public function testDisplayNameRejectsTooLong(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DisplayName(str_repeat('A', 51));
    }

    public function testEmailValidation(): void
    {
        $email = new Email('test@example.com');
        $this->assertEquals('test@example.com', $email->value());
    }

    public function testEmailNormalization(): void
    {
        $email = new Email('TEST@EXAMPLE.COM');
        $this->assertEquals('test@example.com', $email->value());
    }

    public function testEmailRejectsInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Email('not-an-email');
    }

    public function testGameStatusValidation(): void
    {
        $status = new GameStatus('active');
        $this->assertEquals('active', $status->value());
        $this->assertTrue($status->isActive());
        $this->assertFalse($status->isEnded());
    }

    public function testGameStatusRejectsInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GameStatus('invalid-status');
    }

    public function testGameStatusAllValidValues(): void
    {
        $validStatuses = ['upcoming', 'active', 'ended', 'cancelled'];
        
        foreach ($validStatuses as $statusValue) {
            $status = new GameStatus($statusValue);
            $this->assertEquals($statusValue, $status->value());
        }
    }
}
