window.DashboardReports = {
    build(ledger, today, fiscalEndMonth = 12, payroll = []) {
        const period=today.slice(0,7),year=Number(today.slice(0,4)),month=Number(today.slice(5,7));
        const fiscalYearStartMonth=Number(fiscalEndMonth)%12+1;
        const fiscalYearStart=(month<fiscalYearStartMonth?year-1:year)+'-'+String(fiscalYearStartMonth).padStart(2,'0')+'-01';
        const entries=ledger.bookEntries().filter(entry=>entry.date<=today),balances=new Map();
        let income=0,totalDebits=0,totalCredits=0;
        for(const entry of entries)for(const line of entry.lines){
            const code=String(line.account).split(' · ')[0],net=line.debit-line.credit;
            balances.set(code,(balances.get(code)||0)+net);totalDebits+=line.debit;totalCredits+=line.credit;
            if(entry.date>=fiscalYearStart&&(ledger.accountProfile?['Income','Expense'].includes(ledger.accountProfile(code).type):/^[4-7]\d{3}$/.test(code)))income-=net;
        }
        const buckets=[];
        for(let offset=11;offset>=0;offset--){const date=new Date(Date.UTC(year,month-1-offset,1));const key=date.toISOString().slice(0,7);const vouchers=entries.filter(entry=>entry.date.startsWith(key));let revenue=0,expenses=0;
            for(const entry of vouchers)for(const line of entry.lines){const type=ledger.accountProfile?.(String(line.account).split(' · ')[0]).type;if(type?type==='Income':/^4\d{3}/.test(line.account))revenue+=line.credit-line.debit;else if(type?type==='Expense':/^[5-7]\d{3}/.test(line.account))expenses+=line.debit-line.credit;}
            buckets.push({period:key,label:date.toLocaleDateString('en-PH',{month:'short',timeZone:'UTC'}),posted:vouchers.length,income:revenue,expenses});
        }
        const sum=(records,fn)=>records.filter(record=>record.status==='posted'&&record.date<=today&&record.date.startsWith(period)).reduce((total,record)=>total+fn(record),0);
        const monthSales=sum(ledger.sales(),sale=>ledger.taxAmounts?ledger.taxAmounts(sale.gross,sale.tax).net:sale.tax==='12% VAT'?Math.round(sale.gross*100/112):sale.gross);
        const monthPurchases=sum(ledger.purchases(),bill=>ledger.purchaseAmounts(bill).net);
        const sources=[...ledger.sales(),...ledger.purchases(),...ledger.collections(),...ledger.payments(),...ledger.cwtCertificates(),...ledger.wtpCertificates(),...ledger.journalEntries(),...payroll];
        const cutoff=new Date(today+'T12:00:00Z');cutoff.setUTCDate(cutoff.getUTCDate()-90);
        const cutoffDate=cutoff.toISOString().slice(0,10),receipts=ledger.collections().filter(row=>row.status==='posted'&&row.date<=today),payments=ledger.payments().filter(row=>row.status==='posted'&&row.date<=today);
        const certificates=ledger.cwtCertificates().filter(row=>row.status==='posted'&&row.date<=today);
        const linked=(row,invoice)=>String(row.saleId)===String(invoice.id)||(!row.saleId&&row.invoice===invoice.number);
        const oldReceivables=(ledger.invoices?.()||[]).filter(row=>row.date<=cutoffDate).map(row=>({...row,balance:Math.max(0,row.gross-receipts.filter(receipt=>linked(receipt,row)).reduce((sum,receipt)=>sum+receipt.amount,0)-certificates.filter(certificate=>linked(certificate,row)).reduce((sum,certificate)=>sum+certificate.amount,0))})).filter(row=>row.balance>0);
        const oldPayables=(ledger.payableBills?.()||[]).filter(row=>row.date<=cutoffDate).map(row=>({...row,balance:Math.max(0,row.payable-payments.filter(payment=>String(payment.billId)===String(row.id)&&payment.type==='AP Settlement').reduce((sum,payment)=>sum+payment.amount,0))})).filter(row=>row.balance>0);
        const attention={drafts:sources.filter(row=>row.status==='draft'&&row.date<=today).length,oldReceivables:oldReceivables.reduce((sum,row)=>sum+row.balance,0),oldPayables:oldPayables.reduce((sum,row)=>sum+row.balance,0),receivablesFrom:oldReceivables.map(row=>row.date).sort()[0],payablesFrom:oldPayables.map(row=>row.date).sort()[0]};
        const hasFirstEntry=ledger.sales().some(row=>!['SI-000001','SI-000002','SI-000003'].includes(row.number)&&row.status==='posted')||ledger.purchases().some(row=>!['BILL-000001','BILL-000002'].includes(row.number)&&row.status==='posted')||[...ledger.collections(),...ledger.payments()].some(row=>!row.origin&&row.status==='posted')||[...ledger.cwtCertificates(),...ledger.wtpCertificates(),...ledger.journalEntries()].some(row=>row.status==='posted');
        return {attention,hasFirstEntry,cash:[...balances].filter(([code])=>ledger.accountProfile?ledger.accountProfile(code).is_cash_bank:/^10\d{2}$/.test(code)).reduce((total,[,amount])=>total+amount,0),receivable:balances.get('1105')||0,payable:0-(balances.get('2010')||0),income,monthSales,monthPurchases,netVat:0-(balances.get('2210')||0)-(balances.get('2110')||0),buckets,posted:entries.length,balanced:totalDebits===totalCredits,totalDebits,totalCredits,periods:buckets.slice(-3).reverse().map(bucket=>({...bucket,drafts:sources.filter(row=>row.status==='draft'&&row.date?.startsWith(bucket.period)).length}))};
    }
};
