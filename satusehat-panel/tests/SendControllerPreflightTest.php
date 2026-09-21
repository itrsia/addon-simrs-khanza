<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Controller\SendController;
use SatusehatPanel\Core\Database;

final class SendControllerPreflightTest extends TestCase
{
    private \PDO $db;
    private string $sqlitePath;

    protected function setUp(): void
    {
        $this->sqlitePath = PANEL_TEST_STORAGE . '/preflight-' . uniqid() . '.db';
        Database::setSqlitePathForTesting($this->sqlitePath);

        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createSchema($this->db);

        Database::setMysqlForTesting($this->db);
    }

    protected function tearDown(): void
    {
        Database::setMysqlForTesting(null);
        Database::setSqlitePathForTesting(null);
        SendController::setClientForTesting(null);
        @unlink($this->sqlitePath);
    }

    private function createSchema(\PDO $db): void
    {
        $db->exec("
            CREATE TABLE reg_periksa (
                no_rawat VARCHAR(17) PRIMARY KEY,
                no_rkm_medis VARCHAR(15),
                kd_poli VARCHAR(5),
                kd_dokter VARCHAR(20),
                tgl_registrasi DATE,
                jam_reg TIME,
                status_lanjut VARCHAR(10),
                status_bayar VARCHAR(20)
            );
            CREATE TABLE pasien (
                no_rkm_medis VARCHAR(15) PRIMARY KEY,
                nm_pasien VARCHAR(40),
                no_ktp VARCHAR(20)
            );
            CREATE TABLE pegawai (
                nik VARCHAR(20) PRIMARY KEY,
                nama VARCHAR(50),
                no_ktp VARCHAR(20)
            );
            CREATE TABLE poliklinik (
                kd_poli VARCHAR(5) PRIMARY KEY,
                nm_poli VARCHAR(50)
            );
            CREATE TABLE kamar_inap (
                no_rawat VARCHAR(17),
                kd_kamar VARCHAR(15),
                tgl_masuk DATE,
                jam_masuk TIME
            );
            CREATE TABLE satu_sehat_mapping_lokasi_ralan (
                kd_poli VARCHAR(5) PRIMARY KEY,
                id_lokasi_satusehat VARCHAR(64)
            );
            CREATE TABLE satu_sehat_mapping_lokasi_ranap (
                kd_kamar VARCHAR(15) PRIMARY KEY,
                id_lokasi_satusehat VARCHAR(64)
            );
            CREATE TABLE satu_sehat_encounter (
                no_rawat VARCHAR(17) PRIMARY KEY,
                id_encounter VARCHAR(64)
            );
            CREATE TABLE resep_obat (
                no_resep VARCHAR(14) PRIMARY KEY,
                no_rawat VARCHAR(17)
            );
            CREATE TABLE satu_sehat_medicationrequest (
                no_resep VARCHAR(14) PRIMARY KEY,
                id_medicationrequest VARCHAR(64)
            );
            CREATE TABLE permintaan_lab (
                noorder VARCHAR(15) PRIMARY KEY,
                no_rawat VARCHAR(17)
            );
            CREATE TABLE satu_sehat_servicerequest_lab (
                noorder VARCHAR(15) PRIMARY KEY,
                id_servicerequest VARCHAR(64)
            );
            CREATE TABLE permintaan_radiologi (
                noorder VARCHAR(15) PRIMARY KEY,
                no_rawat VARCHAR(17)
            );
            CREATE TABLE satu_sehat_servicerequest_radiologi (
                noorder VARCHAR(15) PRIMARY KEY,
                id_servicerequest VARCHAR(64)
            );
            CREATE TABLE satu_sehat_ihs_patient (
                nikpasien VARCHAR(20) PRIMARY KEY,
                ihspasien VARCHAR(64)
            );
            CREATE TABLE satu_sehat_ihs_practitioner (
                nikpegawai VARCHAR(20) PRIMARY KEY,
                ihspegawai VARCHAR(64)
            );
        ");
    }

    private function seedBasePatient(
        string $noRawat = '2026/09/21/000001',
        string $statusBayar = 'Sudah Bayar',
        string $nikPasien = '3515000000000001',
        string $kdDokter = 'D001',
        string $nikDokterKtp = '3515000000000002',
        string $kdPoli = 'U001'
    ): void {
        $this->db->exec("
            INSERT INTO pasien (no_rkm_medis, nm_pasien, no_ktp)
            VALUES ('RM001', 'Budi Santoso', '{$nikPasien}');

            INSERT INTO pegawai (nik, nama, no_ktp)
            VALUES ('{$kdDokter}', 'dr. Pratama', '{$nikDokterKtp}');

            INSERT INTO poliklinik (kd_poli, nm_poli)
            VALUES ('{$kdPoli}', 'Poli Penyakit Dalam');

            INSERT INTO reg_periksa (no_rawat, no_rkm_medis, kd_poli, kd_dokter, tgl_registrasi, jam_reg, status_lanjut, status_bayar)
            VALUES ('{$noRawat}', 'RM001', '{$kdPoli}', '{$kdDokter}', '2026-09-21', '08:00:00', 'Ralan', '{$statusBayar}');
        ");
    }

    public function testResourceDependencyOrderPlacesRootAnchorsFirst(): void
    {
        $order = SendController::RESOURCE_DEPENDENCY_ORDER;

        $posEncounter = array_search('Encounter', $order, true);
        $posCondition = array_search('Condition', $order, true);
        $posMedication = array_search('Medication', $order, true);
        $posMedReq = array_search('MedicationRequest', $order, true);
        $posSpecimen = array_search('Specimen', $order, true);
        $posDR = array_search('DiagnosticReport', $order, true);
        $posComposition = array_search('Composition', $order, true);

        $this->assertLessThan($posCondition, $posEncounter, 'Encounter must precede Condition');
        $this->assertLessThan($posMedReq, $posMedication, 'Medication must precede MedicationRequest');
        $this->assertLessThan($posMedReq, $posEncounter, 'Encounter must precede MedicationRequest');
        $this->assertLessThan($posDR, $posSpecimen, 'Specimen must precede DiagnosticReport');
        $this->assertLessThan($posComposition, $posDR, 'DiagnosticReport must precede Composition');
    }

    public function testResourcePrerequisitesMappingEnforcesMandatoryParents(): void
    {
        $prereqs = SendController::RESOURCE_PREREQUISITES;

        $this->assertContains('Encounter', $prereqs['Condition']);
        $this->assertContains('Encounter', $prereqs['Procedure']);
        $this->assertContains('Encounter', $prereqs['ObservationTTV']);
        $this->assertContains('Encounter', $prereqs['ServiceRequest']);
        $this->assertContains('ServiceRequest', $prereqs['Specimen']);
        $this->assertContains('ServiceRequest', $prereqs['DiagnosticReport']);
        $this->assertContains('MedicationRequest', $prereqs['MedicationDispense']);
    }

    public function testSortResourcesOrdersChildResourcesAfterAnchors(): void
    {
        $scrambled = [
            'Composition',
            'DiagnosticReport',
            'Specimen',
            'MedicationRequest',
            'Condition',
            'Medication',
            'Encounter',
        ];

        $sorted = SendController::sortResources($scrambled);

        $posEncounter = array_search('Encounter', $sorted, true);
        $posCondition = array_search('Condition', $sorted, true);
        $posMedication = array_search('Medication', $sorted, true);
        $posMedReq = array_search('MedicationRequest', $sorted, true);
        $posSpecimen = array_search('Specimen', $sorted, true);
        $posDR = array_search('DiagnosticReport', $sorted, true);
        $posComposition = array_search('Composition', $sorted, true);

        $this->assertLessThan($posCondition, $posEncounter);
        $this->assertLessThan($posMedReq, $posMedication);
        $this->assertLessThan($posMedReq, $posEncounter);
        $this->assertLessThan($posDR, $posSpecimen);
        $this->assertLessThan($posComposition, $posDR);
    }

    public function testMissingPatientReturnsError(): void
    {
        $result = SendController::sendBundle('2026/09/21/999999', ['resources' => ['Encounter']]);

        $this->assertFalse($result['success']);
        $this->assertSame('Patient not found', $result['error']);
    }

    public function testEmptyResourcesReturnsError(): void
    {
        $this->seedBasePatient();

        // Mark Encounter as already sent, with no other clinical data present
        $this->db->exec("
            INSERT INTO satu_sehat_encounter (no_rawat, id_encounter)
            VALUES ('2026/09/21/000001', 'enc-uuid-already-sent');
        ");

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => []]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Tidak ada resource yang siap dikirim', $result['error']);
    }

    public function testBillingGateBlocksUnpaidVisits(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Belum Bayar');

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => ['Encounter']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Pasien belum lunas', $result['error']);
        $this->assertStringContainsString('status_bayar != Sudah Bayar', $result['error']);
    }

    public function testPrerequisiteGateBlocksChildWhenParentNotInBundleAndNotSent(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => ['Condition']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Condition memerlukan Encounter', $result['error']);
    }

    public function testPrerequisiteGateBlocksDiagnosticReportWithoutServiceRequest(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        $result = SendController::sendBundle('2026/09/21/000001', [
            'resources' => ['Encounter', 'DiagnosticReport'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('DiagnosticReport memerlukan ServiceRequest', $result['error']);
    }

    public function testPrerequisiteGateBlocksMedicationDispenseWithoutMedicationRequest(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        $result = SendController::sendBundle('2026/09/21/000001', [
            'resources' => ['Encounter', 'MedicationDispense'],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('MedicationDispense memerlukan MedicationRequest', $result['error']);
    }

    public function testPrerequisiteGatePassesWhenPrerequisitesSatisfiedInBundle(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Valid patient and doctor IHS
        $this->db->exec("
            INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien)
            VALUES ('3515000000000001', 'P10001');

            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai)
            VALUES ('3515000000000002', '100000001');
        ");
        // Leave location unmapped so it stops at Location gate instead of failing prerequisite gate

        $result = SendController::sendBundle('2026/09/21/000001', [
            'resources' => ['Encounter', 'Condition'],
        ]);

        $this->assertFalse($result['success']);
        // Crucially, it did NOT fail with prerequisite error
        $this->assertStringNotContainsString('Condition memerlukan Encounter', $result['error']);
        // It reached the location mapping gate
        $this->assertStringContainsString('Rule 10120', $result['error']);
    }

    public function testPrerequisiteGatePassesWhenParentAlreadySent(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Encounter previously sent
        $this->db->exec("
            INSERT INTO satu_sehat_encounter (no_rawat, id_encounter)
            VALUES ('2026/09/21/000001', 'enc-uuid-12345');
        ");

        // Request Condition alone without Encounter in bundle
        $result = SendController::sendBundle('2026/09/21/000001', [
            'resources' => ['Condition'],
        ]);

        $this->assertFalse($result['success']);
        // Crucially, it did NOT fail with prerequisite error because Encounter was already sent
        $this->assertStringNotContainsString('Condition memerlukan Encounter', $result['error']);
        // It proceeded past prerequisite gate to IHS validation
        $this->assertStringContainsString('IHS ID Pasien tidak ditemukan', $result['error']);
    }

    public function testLocationMappingGateBlocksEncounterWhenUnmappedRule10120(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Valid patient and doctor IHS
        $this->db->exec("
            INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien)
            VALUES ('3515000000000001', 'P10001');

            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai)
            VALUES ('3515000000000002', '100000001');
        ");
        // satu_sehat_mapping_lokasi_ralan is unmapped (no row for U001)

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => ['Encounter']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Rule 10120', $result['error']);
        $this->assertStringContainsString('belum dipetakan ke SATUSEHAT', $result['error']);
    }

    public function testPatientIhsPlaceholderBlocked(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Placeholder in patient IHS table
        $this->db->exec("
            INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien)
            VALUES ('3515000000000001', 'P-PASIEN-IHS-PLACEHOLDER');
        ");

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => ['Encounter']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('IHS ID Pasien tidak ditemukan', $result['error']);
    }

    public function testDoctorIhsPlaceholderBlocked(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Valid patient IHS, but placeholder in doctor IHS table
        $this->db->exec("
            INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien)
            VALUES ('3515000000000001', 'P10001');

            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai)
            VALUES ('3515000000000002', 'D-DOKTER-IHS-PLACEHOLDER');
        ");

        $result = SendController::sendBundle('2026/09/21/000001', ['resources' => ['Encounter']]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('IHS ID Dokter tidak ditemukan', $result['error']);
    }

    public function testPlaceholderBanInBundlePayloadBlocksSend(): void
    {
        $this->seedBasePatient('2026/09/21/000001', 'Sudah Bayar');

        // Valid patient, doctor, and location mapping
        $this->db->exec("
            INSERT INTO satu_sehat_ihs_patient (nikpasien, ihspasien)
            VALUES ('3515000000000001', 'P10001');

            INSERT INTO satu_sehat_ihs_practitioner (nikpegawai, ihspegawai)
            VALUES ('3515000000000002', '100000001');

            INSERT INTO satu_sehat_mapping_lokasi_ralan (kd_poli, id_lokasi_satusehat)
            VALUES ('U001', 'loc-poli-dalam-1');
        ");

        $customPayloads = [
            'Encounter' => [
                'resourceType' => 'Encounter',
                'status' => 'arrived',
                'subject' => [
                    'reference' => 'Patient/PLACEHOLDER-TEST-REF',
                ],
            ],
        ];

        $result = SendController::sendBundle('2026/09/21/000001', [
            'resources' => ['Encounter'],
            'custom_payloads' => $customPayloads,
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('terdapat referensi placeholder', $result['error']);
    }
}
