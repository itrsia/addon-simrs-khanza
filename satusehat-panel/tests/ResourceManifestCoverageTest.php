<?php

declare(strict_types=1);

namespace SatusehatPanel\Tests;

use PHPUnit\Framework\TestCase;
use SatusehatPanel\Controller\PatientController;
use SatusehatPanel\Core\Database;

final class ResourceManifestCoverageTest extends TestCase
{
    private ?\PDO $db = null;

    protected function tearDown(): void
    {
        Database::setMysqlForTesting(null);
        $this->db = null;
    }

    public function testPendingResourcePredicatesCoversAllMajorClinicalTypes(): void
    {
        $predicates = PatientController::pendingResourcePredicates();

        $expectedTypes = [
            'Encounter',
            'Condition',
            'Procedure',
            'AllergyIntolerance',
            'MedicationRequest',
            'MedicationDispense',
            'MedicationStatement',
            'CarePlan',
            'ClinicalImpression',
            'ServiceRequest',
            'Specimen',
            'Observation',
            'DiagnosticReport',
            'Medication',
            'Immunization',
            'Composition',
            'QuestionnaireResponse',
            'EpisodeOfCare',
            'ObservationTTV',
        ];

        foreach ($expectedTypes as $type) {
            $this->assertArrayHasKey($type, $predicates, "Pending predicate must exist for {$type}");
            $this->assertNotEmpty($predicates[$type], "Predicate for {$type} cannot be empty");
        }
    }

    public function testMultiInstancePredicatesEnforceAllOrNothingCoverage(): void
    {
        $predicates = PatientController::pendingResourcePredicates();

        // Multi-instance types must compare total source count against mapped count
        // (source count > mapped count) rather than checking simple existence,
        // ensuring partially synced resources are flagged as pending/unsent.
        $strictRatioTypes = [
            'Condition' => 'satu_sehat_condition',
            'Procedure' => 'satu_sehat_procedure',
            'MedicationRequest' => 'satu_sehat_medicationrequest',
            'MedicationDispense' => 'satu_sehat_medicationdispense',
            'MedicationStatement' => 'satu_sehat_medicationstatement',
        ];

        foreach ($strictRatioTypes as $type => $table) {
            $this->assertArrayHasKey($type, $predicates);
            $sql = $predicates[$type];
            $this->assertStringContainsString('>', $sql, "{$type} predicate must compare source vs mapped counts using '>'");
            $this->assertStringContainsString($table, $sql, "{$type} predicate must check mapping table {$table}");
            $this->assertStringContainsString("NOT IN ('', '-')", $sql, "{$type} predicate must filter sentinel empty/dash IDs");
        }

        // Lab pipeline types (ServiceRequest, Specimen, Observation, DiagnosticReport)
        // must check all 3 lab variants (PK, MB, Radiologi) with strict count comparisons.
        $labTypes = ['ServiceRequest', 'Specimen', 'Observation', 'DiagnosticReport'];
        foreach ($labTypes as $type) {
            $this->assertArrayHasKey($type, $predicates);
            $sql = $predicates[$type];
            $this->assertStringContainsString('permintaan_lab pl', $sql, "{$type} must check lab PK requests");
            $this->assertStringContainsString('permintaan_labmb pl', $sql, "{$type} must check lab MB requests");
            $this->assertStringContainsString('permintaan_radiologi pr', $sql, "{$type} must check radiologi requests");
            $this->assertStringContainsString("NOT IN ('', '-')", $sql, "{$type} must filter sentinel empty/dash IDs");
        }
    }

    public function testConditionManifestAllOrNothingSemantics(): void
    {
        $this->initTestDatabase();
        $noRawat = '2026/09/21/0001';

        // 2 diagnoses in SIMRS source data
        $this->db->exec("INSERT INTO diagnosa_pasien (no_rawat, kd_penyakit, prioritas) VALUES ('{$noRawat}', 'A09', 1)");
        $this->db->exec("INSERT INTO diagnosa_pasien (no_rawat, kd_penyakit, prioritas) VALUES ('{$noRawat}', 'R50', 2)");

        // Case 1: 0 mapped -> available, not sent
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['Condition']['available']);
        $this->assertFalse($manifest['Condition']['sent'], '0 of 2 mapped must be marked sent: false');

        // Case 2: 1 of 2 mapped with valid ID -> still sent: false (all-or-nothing!)
        $this->db->exec("INSERT INTO satu_sehat_condition (no_rawat, kd_penyakit, status, id_condition) VALUES ('{$noRawat}', 'A09', 'Ralan', 'cond-uuid-1')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['Condition']['available']);
        $this->assertFalse($manifest['Condition']['sent'], '1 of 2 mapped must be marked sent: false');

        // Case 3: 2nd mapped with '-' sentinel -> still sent: false
        $this->db->exec("INSERT INTO satu_sehat_condition (no_rawat, kd_penyakit, status, id_condition) VALUES ('{$noRawat}', 'R50', 'Ralan', '-')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertFalse($manifest['Condition']['sent'], 'Sentinel dash ID must not count toward sent coverage');

        // Case 4: 2nd updated with real ID -> now sent: true
        $this->db->exec("UPDATE satu_sehat_condition SET id_condition = 'cond-uuid-2' WHERE no_rawat = '{$noRawat}' AND kd_penyakit = 'R50'");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['Condition']['sent'], '2 of 2 mapped with real IDs must be marked sent: true');
    }

    public function testProcedureManifestAllOrNothingSemantics(): void
    {
        $this->initTestDatabase();
        $noRawat = '2026/09/21/0002';

        $this->db->exec("INSERT INTO prosedur_pasien (no_rawat, kode) VALUES ('{$noRawat}', '89.07')");
        $this->db->exec("INSERT INTO prosedur_pasien (no_rawat, kode) VALUES ('{$noRawat}', '88.76')");

        // 0 of 2 mapped
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['Procedure']['available']);
        $this->assertFalse($manifest['Procedure']['sent']);

        // 1 of 2 mapped
        $this->db->exec("INSERT INTO satu_sehat_procedure (no_rawat, kode, id_procedure) VALUES ('{$noRawat}', '89.07', 'proc-uuid-1')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertFalse($manifest['Procedure']['sent'], 'Partial procedure mapping must not be marked sent');

        // 2 of 2 mapped
        $this->db->exec("INSERT INTO satu_sehat_procedure (no_rawat, kode, id_procedure) VALUES ('{$noRawat}', '88.76', 'proc-uuid-2')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['Procedure']['sent'], 'Fully mapped procedures must be marked sent');
    }

    public function testMedicationRequestAndDispenseAllOrNothingSemantics(): void
    {
        $this->initTestDatabase();
        $noRawat = '2026/09/21/0003';
        $noResep = '202609210001';

        $this->db->exec("INSERT INTO resep_obat (no_resep, no_rawat) VALUES ('{$noResep}', '{$noRawat}')");
        $this->db->exec("INSERT INTO resep_dokter (no_resep, kode_brng) VALUES ('{$noResep}', 'BRG001')");
        $this->db->exec("INSERT INTO resep_dokter (no_resep, kode_brng) VALUES ('{$noResep}', 'BRG002')");

        // MedicationRequest: 1 of 2 mapped -> sent: false
        $this->db->exec("INSERT INTO satu_sehat_medicationrequest (no_resep, kode_brng, id_medicationrequest) VALUES ('{$noResep}', 'BRG001', 'mr-uuid-1')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['MedicationRequest']['available']);
        $this->assertFalse($manifest['MedicationRequest']['sent'], '1 of 2 medication requests mapped must be sent: false');

        // MedicationRequest: 2 of 2 mapped -> sent: true
        $this->db->exec("INSERT INTO satu_sehat_medicationrequest (no_resep, kode_brng, id_medicationrequest) VALUES ('{$noResep}', 'BRG002', 'mr-uuid-2')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['MedicationRequest']['sent'], '2 of 2 medication requests mapped must be sent: true');

        // MedicationDispense: 2 detail_pemberian_obat records
        $this->db->exec("INSERT INTO detail_pemberian_obat (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, status)
            VALUES ('{$noRawat}', '2026-09-21', '10:00:00', 'BRG001', 'BATCH1', 'FAK1', 'Ralan')");
        $this->db->exec("INSERT INTO detail_pemberian_obat (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, status)
            VALUES ('{$noRawat}', '2026-09-21', '10:00:00', 'BRG002', 'BATCH2', 'FAK2', 'Ralan')");

        // 1 of 2 dispense mapped -> sent: false
        $this->db->exec("INSERT INTO satu_sehat_medicationdispense (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_medicationdispanse)
            VALUES ('{$noRawat}', '2026-09-21', '10:00:00', 'BRG001', 'BATCH1', 'FAK1', 'disp-uuid-1')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['MedicationDispense']['available']);
        $this->assertFalse($manifest['MedicationDispense']['sent'], '1 of 2 dispense mapped must be sent: false');

        // 2 of 2 dispense mapped -> sent: true
        $this->db->exec("INSERT INTO satu_sehat_medicationdispense (no_rawat, tgl_perawatan, jam, kode_brng, no_batch, no_faktur, id_medicationdispanse)
            VALUES ('{$noRawat}', '2026-09-21', '10:00:00', 'BRG002', 'BATCH2', 'FAK2', 'disp-uuid-2')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['MedicationDispense']['sent'], '2 of 2 dispense mapped must be sent: true');
    }

    public function testLabPipelineAllOrNothingSemantics(): void
    {
        $this->initTestDatabase();
        $noRawat = '2026/09/21/0004';
        $noOrderPk = 'PL001';
        $noOrderMb = 'MB001';

        // Lab PK order
        $this->db->exec("INSERT INTO permintaan_lab (noorder, no_rawat) VALUES ('{$noOrderPk}', '{$noRawat}')");
        $this->db->exec("INSERT INTO permintaan_detail_permintaan_lab (noorder, id_template) VALUES ('{$noOrderPk}', 101)");
        $this->db->exec("INSERT INTO template_laboratorium (id_template) VALUES (101)");
        $this->db->exec("INSERT INTO satu_sehat_mapping_lab (id_template, code) VALUES (101, '58410-2')");

        // Lab MB order
        $this->db->exec("INSERT INTO permintaan_labmb (noorder, no_rawat) VALUES ('{$noOrderMb}', '{$noRawat}')");
        $this->db->exec("INSERT INTO permintaan_detail_permintaan_labmb (noorder, id_template) VALUES ('{$noOrderMb}', 201)");
        $this->db->exec("INSERT INTO template_laboratorium (id_template) VALUES (201)");
        $this->db->exec("INSERT INTO satu_sehat_mapping_lab (id_template, code) VALUES (201, '12345-6')");

        // Case 1: 0 mapped -> available, not sent
        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['ServiceRequest']['available']);
        $this->assertFalse($manifest['ServiceRequest']['sent']);
        $this->assertFalse($manifest['Specimen']['sent']);
        $this->assertFalse($manifest['Observation']['sent']);
        $this->assertFalse($manifest['DiagnosticReport']['sent']);

        // Case 2: Only PK mapped, MB unmapped -> still sent: false
        $this->db->exec("INSERT INTO satu_sehat_servicerequest_lab (noorder, id_servicerequest) VALUES ('{$noOrderPk}', 'sr-pk-1')");
        $this->db->exec("INSERT INTO satu_sehat_specimen_lab (noorder, id_specimen) VALUES ('{$noOrderPk}', 'sp-pk-1')");
        $this->db->exec("INSERT INTO satu_sehat_observation_lab (noorder, id_observation) VALUES ('{$noOrderPk}', 'ob-pk-1')");
        $this->db->exec("INSERT INTO satu_sehat_diagnosticreport_lab (noorder, id_diagnosticreport) VALUES ('{$noOrderPk}', 'dr-pk-1')");

        $manifest = $this->getManifestByType($noRawat);
        $this->assertFalse($manifest['ServiceRequest']['sent'], 'Partial lab sync (PK sent, MB unsent) must remain sent: false');
        $this->assertFalse($manifest['Specimen']['sent']);
        $this->assertFalse($manifest['Observation']['sent']);
        $this->assertFalse($manifest['DiagnosticReport']['sent']);

        // Case 3: MB mapped with '-' sentinel -> still sent: false
        $this->db->exec("INSERT INTO satu_sehat_servicerequest_lab_mb (noorder, id_servicerequest) VALUES ('{$noOrderMb}', '-')");
        $manifest = $this->getManifestByType($noRawat);
        $this->assertFalse($manifest['ServiceRequest']['sent'], 'Sentinel dash in lab mapping must not satisfy coverage');

        // Case 4: MB updated with real ID -> now sent: true
        $this->db->exec("UPDATE satu_sehat_servicerequest_lab_mb SET id_servicerequest = 'sr-mb-1' WHERE noorder = '{$noOrderMb}'");
        $this->db->exec("INSERT INTO satu_sehat_specimen_lab_mb (noorder, id_specimen) VALUES ('{$noOrderMb}', 'sp-mb-1')");
        $this->db->exec("INSERT INTO satu_sehat_observation_lab_mb (noorder, id_observation) VALUES ('{$noOrderMb}', 'ob-mb-1')");
        $this->db->exec("INSERT INTO satu_sehat_diagnosticreport_lab_mb (noorder, id_diagnosticreport) VALUES ('{$noOrderMb}', 'dr-mb-1')");

        $manifest = $this->getManifestByType($noRawat);
        $this->assertTrue($manifest['ServiceRequest']['sent'], 'All lab requests mapped must be marked sent: true');
        $this->assertTrue($manifest['Specimen']['sent'], 'All lab specimens mapped must be marked sent: true');
        $this->assertTrue($manifest['Observation']['sent'], 'All lab observations mapped must be marked sent: true');
        $this->assertTrue($manifest['DiagnosticReport']['sent'], 'All lab diagnostic reports mapped must be marked sent: true');
    }

    private function getManifestByType(string $noRawat): array
    {
        $manifest = PatientController::buildResourceManifest($noRawat, ['no_rawat' => $noRawat]);
        $byType = [];
        foreach ($manifest as $m) {
            $byType[$m['type']] = $m;
        }
        return $byType;
    }

    private function initTestDatabase(): void
    {
        $this->db = new \PDO('sqlite::memory:');
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        Database::setMysqlForTesting($this->db);

        $this->db->exec("
            CREATE TABLE satu_sehat_encounter (no_rawat VARCHAR(17), id_encounter VARCHAR(50));
            CREATE TABLE diagnosa_pasien (no_rawat VARCHAR(17), kd_penyakit VARCHAR(15), prioritas INT);
            CREATE TABLE pemeriksaan_ralan (no_rawat VARCHAR(17), keluhan TEXT, rtl TEXT, penilaian TEXT, alergi TEXT, suhu_tubuh VARCHAR(10), tensi VARCHAR(10), nadi VARCHAR(10), respirasi VARCHAR(10), tinggi VARCHAR(10), berat VARCHAR(10), spo2 VARCHAR(10), gcs VARCHAR(10));
            CREATE TABLE pemeriksaan_ranap (no_rawat VARCHAR(17), keluhan TEXT, rtl TEXT, penilaian TEXT, alergi TEXT, suhu_tubuh VARCHAR(10), tensi VARCHAR(10), nadi VARCHAR(10), respirasi VARCHAR(10), tinggi VARCHAR(10), berat VARCHAR(10), spo2 VARCHAR(10), gcs VARCHAR(10));
            CREATE TABLE satu_sehat_condition (no_rawat VARCHAR(17), kd_penyakit VARCHAR(15), status VARCHAR(10), id_condition VARCHAR(50));
            CREATE TABLE prosedur_pasien (no_rawat VARCHAR(17), kode VARCHAR(15));
            CREATE TABLE satu_sehat_procedure (no_rawat VARCHAR(17), kode VARCHAR(15), id_procedure VARCHAR(50));
            CREATE TABLE satu_sehat_allergy_intolerance (no_rawat VARCHAR(17), id_allergy_intolerance VARCHAR(50));
            CREATE TABLE resep_obat (no_resep VARCHAR(14), no_rawat VARCHAR(17));
            CREATE TABLE resep_dokter (no_resep VARCHAR(14), kode_brng VARCHAR(15));
            CREATE TABLE satu_sehat_medicationrequest (no_resep VARCHAR(14), kode_brng VARCHAR(15), id_medicationrequest VARCHAR(50));
            CREATE TABLE detail_pemberian_obat (no_rawat VARCHAR(17), tgl_perawatan DATE, jam TIME, kode_brng VARCHAR(15), no_batch VARCHAR(30), no_faktur VARCHAR(30), status VARCHAR(10));
            CREATE TABLE satu_sehat_medicationdispense (no_rawat VARCHAR(17), tgl_perawatan DATE, jam TIME, kode_brng VARCHAR(15), no_batch VARCHAR(30), no_faktur VARCHAR(30), id_medicationdispanse VARCHAR(50));
            CREATE TABLE satu_sehat_medicationstatement (no_resep VARCHAR(14), kode_brng VARCHAR(15), id_medicationstatement VARCHAR(50));
            CREATE TABLE satu_sehat_careplan (no_rawat VARCHAR(17), id_careplan VARCHAR(50));
            CREATE TABLE satu_sehat_clinicalimpression (no_rawat VARCHAR(17), id_clinicalimpression VARCHAR(50));
            CREATE TABLE satu_sehat_mapping_vaksin (kode_brng VARCHAR(15));
            CREATE TABLE satu_sehat_immunization (no_rawat VARCHAR(17), id_immunization VARCHAR(50));
            CREATE TABLE nota_jalan (no_rawat VARCHAR(17));
            CREATE TABLE nota_inap (no_rawat VARCHAR(17));
            CREATE TABLE satu_sehat_composition (no_rawat VARCHAR(17), id_composition VARCHAR(50));
            CREATE TABLE telaah_farmasi (no_resep VARCHAR(14));
            CREATE TABLE satu_sehat_questionresponse_telaah_farmasi (no_resep VARCHAR(14), id_questionresponse VARCHAR(50));
            CREATE TABLE satu_sehat_episode_of_care (no_rawat VARCHAR(17), id_episode_of_care VARCHAR(50));
            CREATE TABLE satu_sehat_mapping_obat (kode_brng VARCHAR(15));
            CREATE TABLE satu_sehat_medication (kode_brng VARCHAR(15), id_medication VARCHAR(50));
            CREATE TABLE permintaan_lab (noorder VARCHAR(15), no_rawat VARCHAR(17));
            CREATE TABLE permintaan_detail_permintaan_lab (noorder VARCHAR(15), id_template INT);
            CREATE TABLE template_laboratorium (id_template INT);
            CREATE TABLE satu_sehat_mapping_lab (id_template INT, code VARCHAR(50));
            CREATE TABLE satu_sehat_servicerequest_lab (noorder VARCHAR(15), id_servicerequest VARCHAR(50));
            CREATE TABLE satu_sehat_specimen_lab (noorder VARCHAR(15), id_specimen VARCHAR(50));
            CREATE TABLE satu_sehat_observation_lab (noorder VARCHAR(15), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_diagnosticreport_lab (noorder VARCHAR(15), id_diagnosticreport VARCHAR(50));
            CREATE TABLE permintaan_labmb (noorder VARCHAR(15), no_rawat VARCHAR(17));
            CREATE TABLE permintaan_detail_permintaan_labmb (noorder VARCHAR(15), id_template INT);
            CREATE TABLE satu_sehat_servicerequest_lab_mb (noorder VARCHAR(15), id_servicerequest VARCHAR(50));
            CREATE TABLE satu_sehat_specimen_lab_mb (noorder VARCHAR(15), id_specimen VARCHAR(50));
            CREATE TABLE satu_sehat_observation_lab_mb (noorder VARCHAR(15), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_diagnosticreport_lab_mb (noorder VARCHAR(15), id_diagnosticreport VARCHAR(50));
            CREATE TABLE permintaan_radiologi (noorder VARCHAR(15), no_rawat VARCHAR(17));
            CREATE TABLE permintaan_pemeriksaan_radiologi (noorder VARCHAR(15), kd_jenis_prw VARCHAR(15));
            CREATE TABLE satu_sehat_mapping_radiologi (kd_jenis_prw VARCHAR(15), code VARCHAR(50));
            CREATE TABLE satu_sehat_servicerequest_radiologi (noorder VARCHAR(15), id_servicerequest VARCHAR(50));
            CREATE TABLE satu_sehat_specimen_radiologi (noorder VARCHAR(15), id_specimen VARCHAR(50));
            CREATE TABLE satu_sehat_observation_radiologi (noorder VARCHAR(15), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_diagnosticreport_radiologi (noorder VARCHAR(15), id_diagnosticreport VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvsuhu (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvrespirasi (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvnadi (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvspo2 (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvtb (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvbb (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvlp (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvtensi (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvgcs (no_rawat VARCHAR(17), id_observation VARCHAR(50));
            CREATE TABLE satu_sehat_observationttvkesadaran (no_rawat VARCHAR(17), id_observation VARCHAR(50));
        ");
    }
}
