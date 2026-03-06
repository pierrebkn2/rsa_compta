<?php

namespace App\Tests\Services;

use PHPUnit\Framework\TestCase;
use App\Services\SogecomImportService;
use App\Domain\LineBreakdown;
use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\MockObject\MockObject;

class SogecomImportServiceTest extends TestCase
{
    private SogecomImportService $service;
    private MockObject $mockEntityManager;

    protected function setUp(): void
    {
        $this->mockEntityManager = $this->createMock(EntityManager::class);
        $this->service = new SogecomImportService($this->mockEntityManager);
    }

    public function testCreateLineWithPlaneRenewal(): void
    {
        // Arrange - amount >= 100
        $data = [
            '01/01/2025 00:00:00',  // date (index 0)
            '150.50 EUR',           // amount (index 1)
            '',                     // unused (index 2)
            'Acme Corp',            // name (index 3)
            'Description line 1',   // description (index 4)
            'Description line 2',   // description (index 5)
            '2.50 EUR',             // fees (index 6)
        ];

        // Act
        $line = $this->service->createLine($data);

        // Assert
        $this->assertEquals(150.50, $line->getAmount());
        $this->assertContains(LineBreakdown::PLANE_RENEWAL, $line->getBreakdown());
        $this->assertEquals(100, $line->breakdownPlaneRenewal);
        $this->assertEquals(50.50, $line->breakdownCustomerFees);
    }

    public function testCreateLineWithSmallAmountIsRSAContribution(): void
    {
        // Arrange - amount > 0 but < 100
        $data = [
            '01/01/2025 00:00:00',
            '50.00 EUR',
            '',
            'Acme Corp',
            'Contribution',
            'Description',
            '0.50 EUR',
        ];

        // Act
        $line = $this->service->createLine($data);

        // Assert
        $this->assertEquals(50.00, $line->getAmount());
        $this->assertContains(LineBreakdown::RSA_NAV_CONTRIBUTION, $line->getBreakdown());
    }

    public function testCreateFeesLineCalculatesFees(): void
    {
        // Arrange
        $data = [
            '01/01/2025 00:00:00',
            '100.00 EUR',
            '',
            'Acme Corp',
            'Description',
            'Description',
            '2.50 EUR',
        ];

        // Act
        $line = $this->service->createFeesLine($data);

        // Assert
        $this->assertNotNull($line);
        $this->assertEquals(-2.50, $line->getAmount());
        $this->assertContains(LineBreakdown::SOGECOM_FEES, $line->getBreakdown());
    }

    public function testCreateFeesLineReturnsNullForZeroFees(): void
    {
        // Arrange
        $data = [
            '01/01/2025 00:00:00',
            '100.00 EUR',
            '',
            'Acme Corp',
            'Description',
            'Description',
            '0.00 EUR',
        ];

        // Act
        $line = $this->service->createFeesLine($data);

        // Assert
        $this->assertNull($line);
    }

    public function testToFloatHandlesEURCurrency(): void
    {
        // Arrange
        $reflection = new \ReflectionMethod($this->service, 'toFloat');
        $reflection->setAccessible(true);

        // Act
        $result = $reflection->invoke($this->service, '100,50 EUR');

        // Assert
        $this->assertEquals(100.50, $result);
    }

    public function testToFloatHandlesSpacesAndCommas(): void
    {
        // Arrange
        $reflection = new \ReflectionMethod($this->service, 'toFloat');
        $reflection->setAccessible(true);

        // Act
        $result = $reflection->invoke($this->service, '1 000,50 EUR');

        // Assert
        $this->assertEquals(1000.50, $result);
    }

    public function testImportWithValidCsvFile(): void
    {
        // Arrange
        $csvContent = "01/01/2025 00:00:00,150.50 EUR,,Acme Corp,Description line 1,Description line 2,2.50 EUR\n";

        $handle = $this->createMockFileHandle($csvContent);

        $mockConnection = $this->createMock(\Doctrine\DBAL\Connection::class);
        $mockConnection->expects($this->once())->method('beginTransaction');
        $mockConnection->expects($this->once())->method('commit');

        $this->mockEntityManager->expects($this->any())->method('getConnection')->willReturn($mockConnection);
        $this->mockEntityManager->expects($this->atLeast(1))->method('persist');
        $this->mockEntityManager->expects($this->once())->method('flush');

        // Act
        $this->service->import($handle);

        // Assert
        $this->assertTrue(true);
    }

    /**
     * Create a mock file handle from string content
     */
    private function createMockFileHandle(string $content)
    {
        $lines = explode("\n", $content);
        $lineIndex = 0;

        return \Closure::fromCallable(function () use ($lines, &$lineIndex) {
            if ($lineIndex >= count($lines)) {
                return false;
            }
            $line = $lines[$lineIndex];
            $lineIndex++;
            return $line !== '' ? $line . "\n" : false;
        });
    }
}
