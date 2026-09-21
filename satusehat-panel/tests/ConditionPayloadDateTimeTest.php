<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Core\Database;
use SatusehatPanel\Util\PayloadAdapter;

final class ConditionPayloadDateTimeTest extends TestCase
{
    private \PDO $db;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        Database::setMysqlForTesting($this->db);

        $this->db->exec("
            CREATE TABLE reg_periksa (
                no_rawat VARCHAR(17) PRIMARY KEY,
                tgl_registrasi DATE,
                jam_reg TIME,
                status_lanjut VARCHAR(10),
                kd_dokter VARCHAR(20)
            );
            CREATE TABLE pemeriksaan_ralan (
                no_rawat VARCHAR(17) PRIMARY KEY,
                tgl_perawatan DATE,
                jam_rawat TIME,
                keluhan TEXT,
                nip VARCHAR(20)
            );
            CREATE TABLE pemeriksaan_ranap (
                no_rawat VARCHAR(17) PRIMARY KEY,
                tgl_perawatan DATE,
                jam_rawat TIME,
                keluhan TEXT,
                nip VARCHAR(20)
            );
            CREATE TABLE satu_sehat_condition (
                no_rawat VARCHAR(17),
                kd_penyakit VARCHAR(15),
                status VARCHAR(10),
                id_condition VARCHAR(50)
            );
            CREATE TABLE satu_sehat_encounter (
                no_rawat VARCHAR(17) PRIMARY KEY,
                id_encounter VARCHAR(50)
            );
            CREATE TABLE pegawai (
                nik VARCHAR(20) PRIMARY KEY,
                nama VARCHAR(50),
                no_ktp VARCHAR(20)
            );
            CREATE TABLE diagnosa_pasien (
                no_rawat VARCHAR(17),
                kd_penyakit VARCHAR(15),
                status VARCHAR(10),
                prioritas INT
            );
            CREATE TABLE penyakit (
                kd_penyakit VARCHAR(15) PRIMARY KEY,
                nm_penyakit VARCHAR(100)
            );
            CREATE TABLE satu_sehat_ihs_practitioner (
                nikpegawai VARCHAR(20) PRIMARY KEY,
                ihspegawai VARCHAR(100)
            );
            CREATE TABLE satu_sehat_ihs_patient (
                nikpasien VARCHAR(20) PRIMARY KEY,
                ihspasien VARCHAR(100)
            );
        ");
    }

    protected function tearDown(): void
    {
        Database::setMysqlForTesting(null);
    }

    public function testChiefComplaintEmitsFullIsoDateTimeRule10403(): void
    {
        $this->db->exec("
            INSERT INTO reg_periksa (no_rawat, tgl_registrasi, jam_reg, status_lanjut)
            VALUES ('2026/09/21/000001', '2026-09-21', '08:30:00', 'Ralan');
            INSERT INTO pemeriksaan_ralan (no_rawat, tgl_perawatan, jam_rawat, keluhan)
            VALUES ('2026/09/21/000001', '2026-09-21', '09:00:00', 'Demam tinggi sejak 2 hari yang lalu');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000001',
            'no_ktp' => '3515000000000001',
            'ihs_pasien' => 'P10001',
            'ihs_dokter' => 'N10001',
        ];

        $payloads = PayloadAdapter::build('Condition', '2026/09/21/000001', $patient, [], true);
        $this->assertNotEmpty($payloads);

        foreach ($payloads as $p) {
            $this->assertArrayHasKey('recordedDate', $p);
            $recordedDate = $p['recordedDate'];

            // Rule 10403: Condition recordedDate MUST be full ISO-8601 dateTime with timezone offset
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $recordedDate,
                "recordedDate '{$recordedDate}' must be full ISO-8601 with timezone (Rule 10403)"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $recordedDate,
                "recordedDate cannot be date-only (Rule 10403 violation)"
            );
        }
    }

    public function testChiefComplaintMultiDirectCallWithPdo(): void
    {
        $this->db->exec("
            INSERT INTO reg_periksa (no_rawat, tgl_registrasi, jam_reg, status_lanjut)
            VALUES ('2026/09/21/000002', '2026-09-21', '10:00:00', 'Ralan');
            INSERT INTO pemeriksaan_ralan (no_rawat, tgl_perawatan, jam_rawat, keluhan)
            VALUES ('2026/09/21/000002', '2026-09-21', '10:15:30', 'Nyeri perut sebelah kanan');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000002',
            'no_ktp' => '3515000000000002',
            'ihs_pasien' => 'P10002',
            'ihs_dokter' => 'N10002',
        ];

        $payloads = PayloadAdapter::buildChiefComplaintMulti($this->db, $patient, true);
        $this->assertCount(1, $payloads);

        $p = $payloads[0];
        $this->assertSame('Condition', $p['resourceType']);
        $this->assertSame('chief-complaint', $p['category'][0]['coding'][0]['code']);
        $this->assertSame('Nyeri perut sebelah kanan', $p['code']['text']);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $p['recordedDate']
        );
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $p['onsetDateTime']
        );
        $this->assertSame('2026-09-21T10:15:30+07:00', $p['recordedDate']);
    }

    public function testConditionMultiWithDiagnosisAndChiefComplaintEmitsFullIsoDateTime(): void
    {
        $this->db->exec("
            INSERT INTO reg_periksa (no_rawat, tgl_registrasi, jam_reg, status_lanjut, kd_dokter)
            VALUES ('2026/09/21/000003', '2026-09-21', '11:00:00', 'Ralan', 'D001');
            INSERT INTO pegawai (nik, nama, no_ktp)
            VALUES ('D001', 'dr. Hartono', '3515000000000003');
            INSERT INTO penyakit (kd_penyakit, nm_penyakit)
            VALUES ('A09', 'Gastroenteritis');
            INSERT INTO diagnosa_pasien (no_rawat, kd_penyakit, status, prioritas)
            VALUES ('2026/09/21/000003', 'A09', 'Ralan', 1);
            INSERT INTO pemeriksaan_ralan (no_rawat, tgl_perawatan, jam_rawat, keluhan)
            VALUES ('2026/09/21/000003', '2026-09-21', '11:20:00', 'Diare dan muntah');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000003',
            'no_ktp' => '3515000000000003',
            'ihs_pasien' => 'P10003',
            'ihs_dokter' => 'N10003',
            'nm_pasien' => 'Budi Setiawan',
            'tgl_registrasi' => '2026-09-21',
            'jam_reg' => '11:00:00',
        ];

        $payloads = PayloadAdapter::buildConditionMulti($this->db, $patient, true);
        $this->assertCount(2, $payloads);

        foreach ($payloads as $p) {
            $this->assertArrayHasKey('recordedDate', $p);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
                $p['recordedDate'],
                "Condition recordedDate '{$p['recordedDate']}' must be full ISO-8601 with timezone (Rule 10403)"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                $p['recordedDate'],
                'Condition recordedDate cannot be date-only'
            );
        }
    }

    public function testBuildWithDirectPdoParameter(): void
    {
        Database::setMysqlForTesting($this->db);

        $this->db->exec("
            INSERT INTO reg_periksa (no_rawat, tgl_registrasi, jam_reg, status_lanjut)
            VALUES ('2026/09/21/000004', '2026-09-21', '14:00:00', 'Ralan');
            INSERT INTO pemeriksaan_ralan (no_rawat, tgl_perawatan, jam_rawat, keluhan)
            VALUES ('2026/09/21/000004', '2026-09-21', '14:30:00', 'Batuk berdahak');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000004',
            'no_ktp' => '3515000000000004',
            'ihs_pasien' => 'P10004',
            'ihs_dokter' => 'N10004',
        ];

        $payloads = PayloadAdapter::build('Condition', '2026/09/21/000004', $patient, [], true);
        $this->assertNotEmpty($payloads);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $payloads[0]['recordedDate']
        );
    }

    public function testChiefComplaintFallbackWhenTimeMissingEmitsFullIsoDateTime(): void
    {
        $this->db->exec("
            INSERT INTO reg_periksa (no_rawat, tgl_registrasi, jam_reg, status_lanjut)
            VALUES ('2026/09/21/000005', '2026-09-21', '08:00:00', 'Ralan');
            INSERT INTO pemeriksaan_ralan (no_rawat, tgl_perawatan, jam_rawat, keluhan)
            VALUES ('2026/09/21/000005', '2026-09-21', NULL, 'Pusing berputar');
        ");

        $patient = [
            'no_rawat' => '2026/09/21/000005',
            'no_ktp' => '3515000000000005',
            'ihs_pasien' => 'P10005',
            'ihs_dokter' => 'N10005',
        ];

        $payloads = PayloadAdapter::build('Condition', '2026/09/21/000005', $patient, [], true);
        $this->assertNotEmpty($payloads);

        $recordedDate = $payloads[0]['recordedDate'];
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $recordedDate
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $recordedDate
        );
    }
}
