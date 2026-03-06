<?php

namespace App\Services;

use Doctrine\ORM\EntityManager;
use App\Domain\Line;
use App\Domain\LineBreakdown;

final class SGImportService
{
    public function __construct(
        private EntityManager $em
    ) {
    }

    public function import($handle): void
    {
        $this->em->getConnection()->beginTransaction();

        $lineNumber = 1;

        $fileLine = fgets($handle);
        while ($fileLine !== false) {
            if ($lineNumber++ <= 7) {
                if ($lineNumber === 3) {
                    $data = str_getcsv($fileLine, ";");
                    if ($data[0] !== "FR76 3000 3031 2200 0501 3271 922") {
                        throw new \Exception("Not a SG CSV file");
                    }
                }
                $fileLine = fgets($handle);
                continue;
            }
            $data = str_getcsv($fileLine, ";");
            try {
                $this->createLine($data, $handle, $fileLine);
            } catch (\Exception $e) {
                error_log("Error processing line $lineNumber: " . $e->getMessage());
                $fileLine = fgets($handle);
            }
        }

        $this->em->flush();
        $this->em->getConnection()->commit();
    }

    public function createLine(array $data, $handle, &$fileLine): void
    {
        $line = new Line();
        $timezone = new \DateTimeZone('Europe/Paris');
        $date = \DateTimeImmutable::createFromFormat("d/m/Y H:i:s", $data[5] . "01:00:00", $timezone);
        if (!$date) {
            $fileLine = fgets($handle);
            return;
        }
        $line->setDate($date);
        $line->setAmount($this->toFloat($data[2] == "" ? $data[3] : $data[2]));
        $line->setLabel($data[6]);

        $description = $data[1] . "\n";

        $fileLine = fgets($handle);
        $data = str_getcsv($fileLine, ";");
        while ($data[0] == '' && !empty($data[1])) {
            $description .= $data[1] . "\n";
            $fileLine = fgets($handle);
            $data = str_getcsv($fileLine, ";");
        }

        $line->setDescription($description);

        $line = $this->qualifyLine($line);

        if ($line) {
            $this->em->persist($line);
        }
    }

    private function qualifyLine(Line $line): Line | null
    {
        if (strpos($line->getDescription(), 'ABONNT ENCAISSEMENT INTERNET') === 0) {
            $line->setType('VRT');
            $line->setBreakdown([LineBreakdown::SOGECOM_FEES]);
            $line->breakdownSogecomFees = $line->getAmount();
            return $line;
        }
        if (strpos($line->getDescription(), 'COTISATION JAZZ ASSOCIATIONS') === 0) {
            $line->setType('VRT');
            $line->setBreakdown([LineBreakdown::INTERNAL_TRANSFER]);
            $line->breakdownInternalTransfer = $line->getAmount();
            return $line;
        }
        // Frais déjà comptabilisés dans l'import Sogecom
        if (strpos($line->getDescription(), 'REMISE CB') === 0) {
            if (
                $line->getLabel() === 'FACTURES CARTES REMISES'
                || $line->getLabel() === 'COMMISSIONS ET FRAIS DIVERS'
            ) {
                return null;
            }
        }
        if ($line->getLabel() === 'AUTRES VIREMENTS RECUS' || strpos($line->getDescription(), 'VIR INST RE') === 0) {
            $line->setType('VRT');
        }
        if (strpos($line->getDescription(), 'DE: PayPal Europe S.a.r.l. et Cie S.C.A') !== false) {
            $line->setBreakdown([LineBreakdown::INTERNAL_TRANSFER]);
            $line->breakdownInternalTransfer = $line->getAmount();
            return $line;
        }
        if ($line->getLabel() === 'AUTRES VIREMENTS EMIS') {
            $line->setType('VRT');
            if (
                (
                    strpos($line->getDescription(), 'RBTS FRAIS PEN') !== false
                    || strpos($line->getDescription(), 'RBT FRAIS PEN') !== false
                )
                && $line->getAmount() < 0
            ) {
                $line->setBreakdown([LineBreakdown::PEN_REFUND]);
                $line->breakdownPenRefund = $line->getAmount();
                $line->setName('PEN');
                return $line;
            }
            if (strpos($line->getDescription(), 'OSAC  DFFAI') !== false && $line->getAmount() < 0) {
                $line->setBreakdown([LineBreakdown::OSAC]);
                $line->breakdownOsac = $line->getAmount();
                $line->setName('OSAC');
                return $line;
            }
        }
        return $line;
    }

    private function toFloat(string $value): float
    {
        return floatval(strtr(str_replace(' EUR', '', $value), [',' => '.', ' ' => '', ' ' => '']));
    }
}
