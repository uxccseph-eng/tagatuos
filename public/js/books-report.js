window.BooksReports = {
    build(ledger,book,period,options={}) {
        const fallbackEnd=new Date(Date.UTC(Number(period.slice(0,4)),Number(period.slice(5,7)),0)).toISOString().slice(0,10),startDate=options.from || period+'-01',endDate=options.to || fallbackEnd;
        const inRange=date=>date>=startDate&&date<=endDate;
        const source=options.source || 'all',query=(options.search || '').trim().toLowerCase();
        const all=ledger.bookEntries().filter(entry=>source==='all'||entry.sheet===source);
        const flatten=entries=>entries.flatMap(entry=>entry.lines.map(line=>({...line,date:entry.date,number:entry.number,reference:entry.reference,source:entry.source,sheet:entry.sheet,branch:'Main',voucherId:entry.id,code:String(line.account).split(' · ')[0]})));
        const baseColumns=[['date','Date'],['number','Entry No.'],['reference','Ref No.'],['source','Source'],['branch','Branch'],['account','Account Code & Title'],['party','Party / Subsidiary'],['remarks','Particulars / Remarks'],['debit','Debit (PHP)','money'],['credit','Credit (PHP)','money']];
        let salesTotals=null,purchaseTotals=null,cashTotals=null,analysisTotals=null,traceTotals=null,ledgerStats=null;
        let columns=baseColumns,rows=[],description='Chronological record of posted transactions and their double-entry ledger lines.';
        const isCash=line=>ledger.accountProfile?ledger.accountProfile(line.code).is_cash_bank:/^10\d{2}$/.test(line.code);
        const before=flatten(all.filter(entry=>entry.date<startDate));
        let current=flatten(all.filter(entry=>inRange(entry.date)));
        const sourceForBook={'subsidiary-sales':'sales','subsidiary-purchase':'purchases','cash-disbursements':'payments','cash-receipts':'collections'}[book];
        if(sourceForBook)current=current.filter(line=>line.sheet===sourceForBook);
        if(book==='settlement-trace'){
            const purchases=options.analysisMode==='purchases',sheet=purchases?'purchases':'sales';
            columns=[['date','Date'],['number','Doc No.'],['reference','Reference'],['type','Type'],['remarks','Particulars'],['amount','Amount (PHP)','money'],['balance','Balance (PHP)','money'],['bank','Cash / Bank Account'],['branch','Branch']];
            const documents=(purchases?ledger.purchases():ledger.sales()).filter(record=>record.status==='posted'&&inRange(record.date)&&(source==='all'||source===sheet));
            for(const document of documents){
                const party=purchases?document.supplier:document.customer,group=document.number+' · '+document.date+' · '+party;
                const origin={date:document.date,number:document.number,reference:document.reference,sheet,source:purchases?'Purchases':'Sales',type:purchases?'Purchase Invoice':'Sales Invoice',remarks:document.description || document.memo || '',amount:document.gross,branch:'Main',group,document:document.number,origin:true};
                const clearings=[];
                if(purchases){const totals=ledger.purchaseAmounts(document);if(totals.ewt+totals.wvat)clearings.push({date:document.date,number:document.number,reference:document.reference,sheet,source:'Purchases',type:'Source Withholding',remarks:'Withholding recognized on source bill; linked WTP is supporting documentation.',amount:-(totals.ewt+totals.wvat)});}
                else for(const certificate of ledger.cwtCertificates().filter(record=>record.status==='posted'&&record.invoice===document.number))clearings.push({date:certificate.date,number:certificate.number,reference:certificate.certificate,sheet:'cwt',source:'CWT',type:'CWT / 2307',remarks:'Posted withholding certificate received',amount:-certificate.amount});
                const settlements=(purchases?ledger.payments():ledger.collections()).filter(record=>record.status==='posted'&&(purchases?record.type==='AP Settlement'&&String(record.billId)===String(document.id):record.type!=='Advance'&&record.invoice===document.number));
                for(const record of settlements)clearings.push({date:record.date,number:record.number,reference:record.reference,sheet:purchases?'payments':'collections',source:purchases?'Payments':'Collections',type:'Cash Settlement',remarks:record.notes || 'Posted cash settlement',amount:-record.amount,bank:record.bank});
                clearings.sort((a,b)=>a.date.localeCompare(b.date)||(a.type==='Cash Settlement')-(b.type==='Cash Settlement')||a.number.localeCompare(b.number));
                let balance=document.gross;rows.push({...origin,balance});for(const clearing of clearings){balance+=clearing.amount;rows.push({...clearing,balance,branch:'Main',group,document:document.number});}
                rows.push({kind:'closing',group,document:document.number,remarks:'Total cleared / remaining',amount:balance-document.gross,balance,branch:'Main'});
            }
            description='Invoices dated in the selected date range, with all posted clearings across periods. Actual CWT certificates clear receivables; expected withholding alone does not. Purchases recognize withholding on the source bill once.';
        }else if(book==='cash-analysis'){
            const payments=options.analysisMode==='disbursements',sheet=payments?'payments':'collections';
            columns=[['date','Date'],['number','Txn No.'],['reference',payments?'Check / Ref.':'OR / Ref.'],['party',payments?'Supplier / Payee':'Customer / Payor'],[payments?'sourceBill':'sourceInvoice',payments?'Source Bill':'Source Invoice'],['remarks','Particulars'],['description','Description'],['code','Account Code'],['account',payments?'Expense / Asset Account':'Revenue Account'],['amount',payments?'Amount Disbursed':'Amount Collected','money'],['net','Net of VAT','money'],['bank','Cash / Bank Account'],['branch','Branch']];
            const documents=payments?ledger.purchases():ledger.sales();
            for(const record of (payments?ledger.payments():ledger.collections()).filter(record=>record.status==='posted'&&inRange(record.date)&&(source==='all'||source===sheet))){
                const document=documents.find(document=>document.status==='posted'&&(payments?record.type==='AP Settlement'&&String(document.id)===String(record.billId):record.type!=='Advance'&&record.invoice===document.number));
                const account=document?.account || (payments?(record.type==='Supplier Advance'?'1170 · Advances to Suppliers':record.type==='AP Settlement'?'2010 · Accounts Payable - Trade':record.account):record.type==='Advance'?'2200 · Customer Advances':'1105 · Accounts Receivable - Trade');
                const vat=document&&(ledger.taxAmounts?ledger.taxAmounts(record.amount,document.tax).tax.kind==='VAT':payments?document.tax==='VAT (12%)':document.tax==='12% VAT');
                rows.push({date:record.date,number:record.number,reference:record.reference,party:payments?record.supplier:record.customer,source:payments?'Payments':'Collections',sheet,sourceBill:payments&&document?document.number:'',sourceInvoice:!payments&&document?document.number:'',remarks:document?(document.description || document.memo || ''):record.notes || record.type,description:record.notes || '',code:String(account || '').split(' · ')[0],account,amount:record.amount,net:document&&ledger.taxAmounts?ledger.taxAmounts(record.amount,document.tax).net:vat?Math.round(record.amount*100/112):record.amount,bank:record.bank,branch:'Main',voucherId:sheet+':'+record.id});
            }
            rows.sort((a,b)=>a.date.localeCompare(b.date)||a.number.localeCompare(b.number));
            description='Posted '+(payments?'payments':'receipts')+' analyzed by the settled invoice account. Amount is VAT-inclusive cash; Net of VAT excludes source invoice VAT proportionately. Advances use their own control account. This management analysis does not create ledger postings.';
        }else if(book==='subsidiary-purchase'){
            rows=ledger.purchases().filter(bill=>bill.status==='posted'&&inRange(bill.date)&&(source==='all'||source==='purchases')&&(!options.party||options.party==='all'||bill.supplier===options.party)).map(bill=>{
                const totals=ledger.purchaseAmounts(bill);
                return {date:bill.date,number:bill.number,reference:bill.reference,source:'Purchases',sheet:'purchases',voucherId:'purchases:'+bill.id,party:bill.supplier,tin:bill.supplierTin || bill.tin || '',address:bill.supplierAddress || bill.address || '',remarks:bill.description || bill.notes || '',gross:totals.gross,vatable:(ledger.taxAmounts?ledger.taxAmounts(bill.gross,bill.tax).tax.kind==='VAT':bill.tax==='VAT (12%)')?totals.net:0,exempt:(ledger.taxAmounts?ledger.taxAmounts(bill.gross,bill.tax).tax.kind==='Exempt'&&ledger.taxAmounts(bill.gross,bill.tax).tax.code!=='NONVAT':bill.tax==='Exempt')?totals.net:0,zero:(ledger.taxAmounts?ledger.taxAmounts(bill.gross,bill.tax).tax.kind==='Zero Rated':bill.tax==='Zero-Rated')?totals.net:0,nonVat:(ledger.taxAmounts?ledger.taxAmounts(bill.gross,bill.tax).tax.code==='NONVAT':bill.tax==='Non-VAT')?totals.net:0,vat:totals.vat,ewt:totals.ewt,wvat:totals.wvat,net:totals.payable,branch:'Main',debit:totals.gross,credit:totals.gross};
            });
            rows.sort((a,b)=>(options.descending?-1:1)*(a.date.localeCompare(b.date)||a.number.localeCompare(b.number)));
            columns=[['date','Date'],['number','Txn No.'],['reference','Supplier Inv No.'],['party','Supplier'],['tin','TIN'],['address','Address'],['remarks','Particulars'],['gross','Gross Purchases','money'],['vatable','VATable Purchases','money'],['exempt','VAT-Exempt','money'],['zero','Zero-Rated','money']];
            if(rows.some(row=>row.nonVat))columns.push(['nonVat','Non-VAT Purchases','money']);columns.push(['vat','Input VAT','money'],['ewt','EWT / 2307','money']);if(rows.some(row=>row.wvat))columns.push(['wvat','Withholding VAT','money']);columns.push(['net','Net Payable','money'],['branch','Branch']);
            description='Posted purchase invoices · Input VAT, expected withholding and net payable by source bill.';
        }else if(['cash-disbursements','cash-receipts'].includes(book)){
            const payments=book==='cash-disbursements',bills=ledger.purchases(),sales=ledger.invoices();
            const receipts=ledger.collections().filter(record=>record.status==='posted').sort((a,b)=>a.date.localeCompare(b.date)||a.number.localeCompare(b.number));
            const allocations=new Map(),applied=new Map();
            const prorate=(amount,withheld,base)=>Number((BigInt(amount)*BigInt(withheld)+BigInt(base)/2n)/BigInt(base));
            for(const receipt of receipts){
                const sale=receipt.type!=='Advance'&&sales.find(sale=>sale.number===receipt.invoice);
                let ewt=0,wvat=0;
                if(sale&&sale.total>0){
                    const base=ledger.taxAmounts?ledger.taxAmounts(sale.gross,sale.tax).net:sale.tax==='12% VAT'?Math.round(sale.gross*100/112):sale.gross;
                    const rate=label=>Number(String(label || '').match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
                    const expectedEwt=Math.round(base*rate(sale.cwt)/100),expectedWvat=Math.round(base*rate(sale.vatCwt)/100);
                    const before=Math.min(sale.total,applied.get(sale.number) || 0),after=Math.min(sale.total,before+receipt.amount);
                    ewt=prorate(after,expectedEwt,sale.total)-prorate(before,expectedEwt,sale.total);wvat=prorate(after,expectedWvat,sale.total)-prorate(before,expectedWvat,sale.total);applied.set(sale.number,after);
                }
                allocations.set(receipt.id,{ewt,wvat});
            }
            const records=payments?ledger.payments():receipts;
            rows=records.filter(record=>record.status==='posted'&&inRange(record.date)&&(source==='all'||source===(payments?'payments':'collections'))&&(!options.party||options.party==='all'||(payments?record.supplier:record.customer)===options.party)).map(record=>{
                const bill=payments&&bills.find(bill=>String(bill.id)===String(record.billId)),sale=!payments&&sales.find(sale=>sale.number===record.invoice);
                const withholding=payments?{ewt:0,wvat:0}:allocations.get(record.id);
                return {date:record.date,number:record.number,reference:record.reference,source:payments?'Payments':'Collections',sheet:payments?'payments':'collections',voucherId:(payments?'payments:':'collections:')+record.id,party:payments?record.supplier:record.customer,tin:record.tin || (payments?bill?.tin:sale?.customerTin || sale?.tin) || '',remarks:record.notes || (payments?'Payment voucher · '+record.type:'Collection voucher · '+record.type),sourceBill:payments?record.bill || '':undefined,sourceInvoice:!payments&&record.type!=='Advance'?sale?.number || record.invoice:'',gross:record.amount+withholding.ewt+withholding.wvat,ewt:withholding.ewt,wvat:withholding.wvat,net:record.amount,sourceEwt:bill?ledger.purchaseAmounts(bill).ewt:0,bank:record.bank,offset:payments?(record.type==='AP Settlement'?'2010 · Accounts Payable - Trade':record.type==='Supplier Advance'?'1170 · Advances to Suppliers':record.account):(record.type==='Advance'?'2200 · Customer Advances':'1105 · Accounts Receivable - Trade'),branch:'Main',debit:record.amount,credit:record.amount};
            });
            rows.sort((a,b)=>(options.descending?-1:1)*(a.date.localeCompare(b.date)||a.number.localeCompare(b.number)));
            columns=[['date','Date'],['number','Txn No.'],['reference',payments?'Check / Ref':'OR / Coll. Ref.'],['party',payments?'Payee / Supplier':'Customer / Payor'],['tin','TIN'],['remarks','Particulars'],[payments?'sourceBill':'sourceInvoice',payments?'Source Bill':'Source Invoice'],['gross',payments?'Gross Disb.':'Gross Collection','money'],['ewt','EWT / 2307','money']];
            if(rows.some(row=>row.wvat))columns.push(['wvat','VAT Withholding','money']);columns.push(['net',payments?'Net Cash':'Net Cash Received','money'],['vat',payments?'Input VAT':'Output VAT','money'],['bank','Cash / Bank Account'],['offset',payments?'Account Debited':'Account Credited'],['branch','Branch']);
            description=payments?'Posted payment vouchers · Withholding already recognized on source bills is not deducted a second time.':'Posted collection vouchers · Gross collection includes proportionally allocated expected withholding from linked sales invoices.';
        }else if(book==='subsidiary-sales'){
            const typeFor=sale=>sale.customerType || sale.classification || 'Regular';
            rows=ledger.invoices().filter(sale=>inRange(sale.date)&&(source==='all'||source==='sales')&&(!options.customerType||options.customerType==='all'||typeFor(sale)===options.customerType)).map(sale=>{
                const taxInfo=ledger.taxAmounts?ledger.taxAmounts(sale.gross,sale.tax):null,vat=taxInfo?taxInfo.tax.kind==='VAT':sale.tax==='12% VAT',base=taxInfo?taxInfo.net:vat?Math.round(sale.gross*100/112):sale.gross,rate=Number(String(sale.cwt || '').match(/(\d+(?:\.\d+)?)%/)?.[1] || 0);
                return {date:sale.date,number:sale.number,reference:sale.reference,source:'Sales',sheet:'sales',voucherId:'sales:'+sale.id,party:sale.customer,customerType:typeFor(sale),tin:sale.customerTin || sale.tin || '',address:sale.customerAddress || sale.address || '',remarks:sale.memo || '',gross:sale.gross,vatable:vat?base:0,exempt:(taxInfo?taxInfo.tax.kind==='Exempt'&&taxInfo.tax.code!=='NONVAT':sale.tax==='VAT Exempt')?base:0,zero:(taxInfo?taxInfo.tax.kind==='Zero Rated':sale.tax==='Zero-Rated (0%)')?base:0,nonVat:(taxInfo?taxInfo.tax.code==='NONVAT':sale.tax==='Non-VAT')?base:0,vat:sale.gross-base,ewt:Math.round(base*rate/100),net:sale.total,wvat:sale.gross-Math.round(base*rate/100)-sale.total,branch:'Main'};
            });
            rows.sort((a,b)=>(options.descending?-1:1)*a.date.localeCompare(b.date));
            columns=[['date','Date'],['number','Txn No.'],['reference','SI / OR No.'],['party','Customer'],['customerType','Customer Type'],['tin','TIN'],['address','Address'],['remarks','Particulars'],['gross','Gross Sales','money'],['vatable','VATable Sales','money'],['exempt','VAT-Exempt','money'],['zero','Zero-Rated','money']];
            if(rows.some(row=>row.nonVat))columns.push(['nonVat','Non-VAT Sales','money']);
            columns.push(['vat','Output VAT','money'],['ewt','EWT / 2307','money']);if(rows.some(row=>row.wvat))columns.push(['wvat','VAT Withholding','money']);columns.push(['net','Net Receivable','money'],['branch','Branch']);
            description='Posted sales register · VAT, expected income withholding, and net receivable by source invoice.';
        }else if(['general-ledger','subsidiary-ledger'].includes(book)){
            columns=[['date','Date'],['source','Source Journal'],['number','Document No.'],['reference','Ref No.'],['remarks','Particulars'],['account','Account'],['party','Subsidiary'],['debit','Debit (PHP)','money'],['credit','Credit (PHP)','money'],['balance','Balance (PHP)','money'],['branch','Branch']];
            const subsidiary=book==='subsidiary-ledger',key=line=>line.code+(subsidiary?' | '+line.party:'');
            const normal=code=>ledger.accountProfile?{polarity:ledger.accountProfile(code).normal_balance==='Credit'?-1:1,label:ledger.accountProfile(code).type}:({polarity:code!=='2110'&&(code==='1590'||/^[234]/.test(code))?-1:1,label:code==='2110'?'Asset':code==='1590'?'Contra Asset':({'1':'Asset','2':'Liability','3':'Equity','4':'Revenue'}[code[0]] || 'Expense')});
            const groups=new Map();for(const line of [...before,...current].filter(line=>(!subsidiary||line.party)&&(!options.account||options.account==='all'||line.code===options.account))){const id=key(line);if(!groups.has(id))groups.set(id,{code:line.code,account:line.account,party:line.party,opening:0,lines:[]});const group=groups.get(id);if(inRange(line.date))group.lines.push(line);else group.opening+=line.debit-line.credit;}
            if(!subsidiary&&options.includeZero)for(const account of ledger.journalAccounts()){const code=account.split(' · ')[0];if(!groups.has(code)&&(!options.account||options.account==='all'||options.account===code))groups.set(code,{code,account,party:'',opening:0,lines:[]});}
            const displayed=[];
            for(const [id,group] of [...groups].sort(([a],[b])=>a.localeCompare(b))){
                if(!options.includeZero&&!group.lines.length&&!group.opening)continue;
                const heading=group.account+(subsidiary?' · '+group.party:'');
                if(query&&![heading,...group.lines.map(line=>Object.values(line).join(' '))].join(' ').toLowerCase().includes(query))continue;
                const profile=normal(group.code);let balance=group.opening*profile.polarity || 0;
                const meta={group:heading,groupAccount:group.account,groupParty:subsidiary?group.party:'',code:group.code,accountType:profile.label,normalSide:profile.polarity===1?'Dr':'Cr',branch:'Main'};
                const debit=group.lines.reduce((sum,line)=>sum+line.debit,0),credit=group.lines.reduce((sum,line)=>sum+line.credit,0);const closing=balance+(debit-credit)*profile.polarity || 0;
                displayed.push({...group,debit,credit,closing,profile});
                if(subsidiary&&options.balances){rows.push({...meta,account:group.account,party:group.party,opening:balance,debit,credit,balance:closing,kind:'balance'});continue;}
                rows.push({...meta,date:startDate,account:group.account,party:subsidiary?group.party:'',remarks:'Balance forwarded',debit:0,credit:0,balance,kind:'opening'});
                for(const line of group.lines.sort((a,b)=>a.date.localeCompare(b.date)||['sales','purchases','journal','collections','payments','cwt','wtp'].indexOf(a.sheet)-['sales','purchases','journal','collections','payments','cwt','wtp'].indexOf(b.sheet)||a.number.localeCompare(b.number))){balance+=(line.debit-line.credit)*profile.polarity;rows.push({...line,...meta,balance});}
                rows.push({...meta,remarks:'Total for the period ('+group.code+(subsidiary?' · '+group.party:'')+')',debit,credit,balance,kind:'subtotal'});
            }
            if(subsidiary&&options.balances)columns=[['account','Account'],['party','Subsidiary'],['opening','Forwarded','money'],['debit','Period Debits','money'],['credit','Period Credits','money'],['balance','Closing Balance','money'],['branch','Branch']];
            const parties=new Set(displayed.map(group=>group.party).filter(Boolean));const customers=new Set(),suppliers=new Set();for(const line of [...before,...current].filter(line=>displayed.some(group=>group.code===line.code&&group.party===line.party))){if(['sales','collections','cwt'].includes(line.sheet))customers.add(line.party);if(['purchases','payments','wtp'].includes(line.sheet))suppliers.add(line.party);}
            const controls=[...new Set([...before,...current].map(line=>line.code).filter(code=>['1105','2010','1150','1170','2200'].includes(code)&&(!options.account||options.account==='all'||options.account===code)))];let controlVariance=0;
            for(const code of controls){const gl=[...before,...current].filter(line=>line.code===code).reduce((sum,line)=>sum+line.debit-line.credit,0);const sl=[...before,...current].filter(line=>line.code===code&&line.party).reduce((sum,line)=>sum+line.debit-line.credit,0);controlVariance+=Math.abs(gl-sl);}
            ledgerStats={accounts:new Set(displayed.map(group=>group.code)).size,subsidiaries:parties.size,customers:customers.size,suppliers:suppliers.size,controlVariance,controls:controls.length,debit:displayed.reduce((sum,group)=>sum+group.debit,0),credit:displayed.reduce((sum,group)=>sum+group.credit,0)};
            description=subsidiary?'Subsidiary-tagged posted lines, grouped by account and party. Balances follow each account’s normal debit or credit direction.':'Posted account movements, balances forwarded, and period totals. Balances follow each account’s normal debit or credit direction.';
        }else{
            rows=current;
            if(sourceForBook)description='Posted '+({'sales':'sales','purchases':'purchase','payments':'disbursement','collections':'receipt'}[sourceForBook])+' transactions and their ledger lines for the selected month.';
            if(options.grouped){rows=rows.sort((a,b)=>a.code.localeCompare(b.code)||a.date.localeCompare(b.date));rows=rows.map(line=>({...line,group:line.account}));}
            else rows.sort((a,b)=>(options.descending?-1:1)*(a.date.localeCompare(b.date)||a.number.localeCompare(b.number)));
        }
        if(query&&book==='settlement-trace'){const groups=new Set(rows.filter(row=>Object.values(row).join(' ').toLowerCase().includes(query)).map(row=>row.group));rows=rows.filter(row=>groups.has(row.group));}
        if(query&&!['general-ledger','subsidiary-ledger','settlement-trace'].includes(book))rows=rows.filter(row=>Object.values(row).join(' ').toLowerCase().includes(query));
        if(book==='subsidiary-sales')salesTotals=rows.reduce((totals,row)=>{for(const key of ['gross','vatable','exempt','zero','nonVat','vat','ewt','wvat','net'])totals[key]=(totals[key] || 0)+row[key];return totals;},{});
        if(book==='subsidiary-purchase')purchaseTotals=rows.reduce((totals,row)=>{for(const key of ['gross','vatable','exempt','zero','nonVat','vat','ewt','wvat','net'])totals[key]=(totals[key] || 0)+(row[key] || 0);return totals;},{});
        if(['cash-disbursements','cash-receipts'].includes(book))cashTotals=rows.reduce((totals,row)=>{for(const key of ['gross','ewt','wvat','net'])totals[key]=(totals[key] || 0)+(row[key] || 0);return totals;},{vat:0});
        if(book==='cash-analysis')analysisTotals=rows.reduce((totals,row)=>({amount:totals.amount+row.amount,net:totals.net+row.net}),{amount:0,net:0});
        if(book==='settlement-trace'){const origins=rows.filter(row=>row.origin),closing=rows.filter(row=>row.kind==='closing');traceTotals={documents:origins.length,principal:origins.reduce((sum,row)=>sum+row.amount,0),cleared:-closing.reduce((sum,row)=>sum+row.amount,0),balance:closing.reduce((sum,row)=>sum+row.balance,0),open:closing.filter(row=>row.balance!==0).length};}
        const movement=rows.filter(row=>!['opening','subtotal'].includes(row.kind)),debit=movement.reduce((sum,line)=>sum+(line.debit || 0),0),credit=movement.reduce((sum,line)=>sum+(line.credit || 0),0);
        return {salesTotals,purchaseTotals,cashTotals,analysisTotals,traceTotals,ledgerStats,columns:columns.map(([key,title,format])=>({key,title,format})),rows,debit,credit,entries:new Set(movement.map(line=>line.voucherId || line.number).filter(Boolean)).size,description,canReconcile:!['cash-analysis','subsidiary-ledger','settlement-trace','subsidiary-sales','subsidiary-purchase','cash-disbursements','cash-receipts'].includes(book)};
    }
};
