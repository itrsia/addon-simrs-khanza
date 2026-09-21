<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Util\PayloadAdapter;

final class PractitionerIhsResolutionTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->db->exec("
            CREATE TABLE pegawai (
                nik VARCHAR(20) PRIMARY KEY,
                nama VARCHAR(50),
                no_ktp VARCHAR(20)
            );
            CREATE TABLE satu_sehat_ihs_practitioner (
                nikpegawai VARCHAR(20) PRIMARY KEY,
                ihspegawai VARCHAR(100)
            );
            CREATE TABLE reg_periksa (
                no_rawat VARCHAR(17) PRIMARY KEY,
                kd_dokter VARCHAR(20)
            );
        ");
    }

    public function testResolveDokterIhsMatchesOnNoKtpNotNik(): void
    {
        // Doctor has employee code D001 and 16-digit KTP
        $this->db->exec("
            INSERT INTO pegawai (nik, nama, no_ktp) VALUES ('D001', 'dr. Budi Santoso', '3515000000000001');
            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES ('3515000000000001', '10001234567');
        ");

        $stmtDoc = $this->db->prepare("SELECT pg.no_ktp FROM pegawai pg WHERE pg.nik = ? LIMIT 1");
        $stmtDoc->execute(['D001']);
        $nikDokter = (string) ($stmtDoc->fetchColumn() ?: '');

        $ihs = PayloadAdapter::resolveDokterIhs($this->db, $nikDokter);
        $this->assertSame('10001234567', $ihs, 'Doctor IHS must resolve using 16-digit KTP number');

        // Sentinel '-' or non-16-digit KTP should not query upstream API and returns placeholder
        $this->db->exec("INSERT INTO pegawai (nik, nama, no_ktp) VALUES ('D003', 'dr. Sentinel', '-');");
        $ihsSentinel = PayloadAdapter::resolveDokterIhs($this->db, 'D003');
        $this->assertSame('N-DOKTER-IHS-PLACEHOLDER', $ihsSentinel);
    }

    public function testGetIhsIdsResolvesAttendingDoctorViaNoKtp(): void
    {
        $this->db->exec("
            INSERT INTO pegawai (nik, nama, no_ktp) VALUES ('D002', 'dr. Siti Aminah', '3515000000000002');
            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai) VALUES ('3515000000000002', '10009876543');
            INSERT INTO reg_periksa (no_rawat, kd_dokter) VALUES ('2026/09/21/000001', 'D002');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000001',
            'no_ktp' => '3515000000009999',
            'ihs_pasien' => 'P123',
        ];

        $ihsIds = PayloadAdapter::getIhsIds($this->db, '2026/09/21/000001', $patient);
        $this->assertSame('10009876543', $ihsIds['ihs_dokter']);
        $this->assertStringNotContainsString('PLACEHOLDER', $ihsIds['ihs_dokter']);
    }
}
