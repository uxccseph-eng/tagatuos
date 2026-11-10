<?php

namespace Tests\Unit;

use App\Services\CompanyLedgerService;
use App\Services\TransactionNumbering;
use PHPUnit\Framework\TestCase;

class TransactionNumberingTest extends TestCase
{
    public function test_every_sheet_repairs_existing_archive_gaps_and_allocates_the_next_post_without_numbering_drafts(): void
    {
        foreach (TransactionNumbering::PREFIXES as $sheet => $prefix) {
            $rows = [
                ['id' => 'retained', 'number' => $prefix.'-000004', 'status' => 'posted'],
                ['id' => 'deleted', 'number' => $prefix.'-000002', 'status' => 'archived'],
                ['id' => 'first', 'number' => $prefix.'-000001', 'status' => 'void'],
                ['id' => 'custom', 'number' => 'CUSTOM-099', 'status' => 'posted'],
                ['id' => 'draft', 'number' => '', 'status' => 'draft'],
            ];
            $previous = CompanyLedgerService::emptyState();
            $previousPayroll = $sheet === 'payroll' ? $rows : [];
            if ($sheet !== 'payroll') {
                $previous[$sheet] = $rows;
            }
            $state = $previous;
            $payroll = $previousPayroll;
            $new = ['id' => 'new', 'number' => '', 'status' => 'posted'];
            if ($sheet === 'payroll') {
                $payroll[] = $new;
            } else {
                $state[$sheet][] = $new;
            }
            (new TransactionNumbering)->normalize($state, $payroll, $previous, $previousPayroll);
            $actual = array_column($sheet === 'payroll' ? $payroll : $state[$sheet], null, 'id');
            $this->assertSame($prefix.'-000002', $actual['retained']['number'], $sheet);
            $this->assertSame($rows[1], $actual['deleted'], $sheet.' archive history');
            $this->assertSame($prefix.'-000001', $actual['first']['number'], $sheet.' undeleted void');
            $this->assertSame('CUSTOM-099', $actual['custom']['number'], $sheet.' custom reference');
            $this->assertSame('', $actual['draft']['number'], $sheet.' draft');
            $this->assertSame($prefix.'-000003', $actual['new']['number'], $sheet.' next post');
            $stable = [$state, $payroll];
            (new TransactionNumbering)->normalize($state, $payroll, $state, $payroll);
            $this->assertSame($stable, [$state, $payroll], $sheet.' retry');
        }
    }

    public function test_compaction_updates_linked_invoice_bill_certificate_and_payroll_labels_without_changing_identities(): void
    {
        $state = CompanyLedgerService::emptyState();
        foreach (TransactionNumbering::PREFIXES as $sheet => $prefix) {
            $rows = [['id' => 'deleted', 'number' => $prefix.'-000001', 'status' => 'archived'], ['id' => 'kept', 'number' => $prefix.'-000003', 'status' => 'posted']];
            if ($sheet === 'payroll') {
                $payroll = $rows;
            } else {
                $state[$sheet] = $rows;
            }
        }
        foreach (['collections', 'cwtCertificates'] as $sheet) {
            $state[$sheet][1] += ['saleId' => 'kept', 'invoice' => 'SI-000003'];
        }
        foreach (['payments', 'wtpCertificates'] as $sheet) {
            $state[$sheet][1] += ['billId' => 'kept', 'bill' => 'BILL-000003'];
        }
        $state['cwtCertificates'][1]['certificate'] = '2307R-000003';
        $state['wtpCertificates'][1]['certificate'] = 'EXTERNAL-CERTIFICATE';
        $state['journalEntries'][1] += ['origin' => 'payroll-remittance', 'payrollRunId' => 'kept', 'payrollNumber' => 'PYRJ-000003', 'payrollAllocations' => [['runId' => 'kept', 'runNumber' => 'PYRJ-000003']], 'particulars' => 'Remittance PYRJ-000003'];
        (new TransactionNumbering)->normalize($state, $payroll, $state, $payroll);
        foreach (['collections', 'cwtCertificates'] as $sheet) {
            $this->assertSame('kept', $state[$sheet][1]['saleId']);
            $this->assertSame('SI-000001', $state[$sheet][1]['invoice']);
        }
        foreach (['payments', 'wtpCertificates'] as $sheet) {
            $this->assertSame('kept', $state[$sheet][1]['billId']);
            $this->assertSame('BILL-000001', $state[$sheet][1]['bill']);
        }
        $this->assertSame('2307R-000001', $state['cwtCertificates'][1]['certificate']);
        $this->assertSame('EXTERNAL-CERTIFICATE', $state['wtpCertificates'][1]['certificate']);
        $this->assertSame('PYRJ-000001', $state['journalEntries'][1]['payrollNumber']);
        $this->assertSame('PYRJ-000001', $state['journalEntries'][1]['payrollAllocations'][0]['runNumber']);
        $this->assertSame('Remittance PYRJ-000001', $state['journalEntries'][1]['particulars']);
    }
}
