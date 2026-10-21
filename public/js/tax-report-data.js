(function(root){
    const posted=(records,range)=>(records || []).filter(record=>record.status==='posted'&&record.date>=range.from&&record.date<=range.to);
    const tin=value=>{const digits=String(value || '').replace(/\D/g,'');return [9,12,13,14].includes(digits.length)?digits.slice(0,9).replace(/(\d{3})(\d{3})(\d{3})/,'$1-$2-$3')+'-'+digits.slice(9).padStart(5,'0'):String(value || '');};
    const hasTin=value=>/^\d{3}-\d{3}-\d{3}-(?:\d{3}|\d{5})$/.test(tin(value))&&/[1-9]/.test(tin(value));
    function isImportPurchase(record,ledger){const code=ledger.taxAmounts(record.gross,record.tax).tax?.code || '';return record.isImportation===true||Boolean(record.importation&&typeof record.importation==='object')||Boolean(record.customs&&typeof record.customs==='object')||/IMPORT/i.test(code);}
    function purchaseSchedule(ledger,range,grouped){
        const rows=[],issues=[],groups=new Map(),stats={base:0,tax:0,count:0};let hasNonVat=false,hasUnclassified=false;
        for(const record of posted(ledger.purchases(),range).filter(item=>!isImportPurchase(item,ledger))){
            const name=record.supplier || '',identity=tin(record.supplierTin ?? record.tin),amount=ledger.taxAmounts(record.gross,record.tax),info=amount.tax,vat=record.gross-amount.net,code=String(record.account || '').split(' · ')[0],category=ledger.accountProfile?.(code)?.relief_category;
            if(!name)issues.push(record.number+': missing party name.');if(!hasTin(identity))issues.push(record.number+': missing or invalid TIN.');
            const values={exempt:0,zero:0,services:0,capital_goods:0,goods:0,nonvat:0,unclassified:0};
            if(info.kind==='VAT'){if(['services','capital_goods','goods'].includes(category))values[category]=amount.net;else{values.unclassified=amount.net;hasUnclassified=true;issues.push(record.number+': set the VAT purchase category in Masters → Chart of Accounts.');}stats.base+=amount.net;}
            else if(info.kind==='Zero Rated')values.zero=amount.net;else if(info.code==='NONVAT'){values.nonvat=amount.net;hasNonVat=true;}else values.exempt=amount.net;
            const key=grouped?record.date.slice(0,7)+'|'+name.toLowerCase()+'|'+identity:String(record.id)+'|'+record.number;
            if(!groups.has(key))groups.set(key,{date:grouped?record.date.slice(0,7):record.date,name,tin:identity,number:record.number,values:{exempt:0,zero:0,services:0,capital_goods:0,goods:0,nonvat:0,unclassified:0},vat:0,net:0,documents:[]});
            const item=groups.get(key);for(const field of Object.keys(values))item.values[field]+=values[field];item.vat+=vat;item.net+=amount.net;item.documents.push(record.number);stats.tax+=vat;stats.count++;
        }
        const columns=[grouped?'Month':'Date','Supplier','TIN',...(!grouped?['Document']:[]),'Exempt','Zero-Rated','Services','Capital Goods','Other Goods',...(hasNonVat?['Non-VAT']:[]),...(hasUnclassified?['Unclassified']:[]),'Input VAT','Total Purchases'];
        const numeric=columns.map((_,index)=>index).filter(index=>index>=(grouped?3:4));
        for(const item of [...groups.values()].sort((a,b)=>a.date.localeCompare(b.date)||a.name.localeCompare(b.name)))rows.push({cells:[item.date,item.name,item.tin,...(!grouped?[item.number]:[]),item.values.exempt,item.values.zero,item.values.services,item.values.capital_goods,item.values.goods,...(hasNonVat?[item.values.nonvat]:[]),...(hasUnclassified?[item.values.unclassified]:[]),item.vat,item.net],documents:item.documents});
        const totals=columns.map((_,index)=>numeric.includes(index)?rows.reduce((sum,row)=>sum+row.cells[index],0):'');totals[0]='Total';
        return {columns,rows,numeric,totals,stats,baseLabel:'Net taxable purchases',taxLabel:'Input VAT',note:'Posted domestic purchases only. Services, Capital Goods, and Other Goods follow Masters → Chart of Accounts. Import VAT purchases appear in RELIEF Importation. Total Purchases excludes input VAT.',issues:[...new Set(issues)],available:true};
    }
    function importationSchedule(ledger,range){
        const columns=['Month','Entry No.','Supplier','Country','Dutiable','Charges','Exempt','Taxable','VAT Paid'],numeric=[4,5,6,7,8],rows=[],issues=[],stats={base:0,tax:0,count:0};
        for(const record of posted(ledger.purchases(),range).filter(item=>isImportPurchase(item,ledger))){
            const customs=record.importation || record.customs || {},amount=ledger.taxAmounts(record.gross,record.tax),entry=customs.entryNo || customs.entryNumber || record.importEntryNo || '',country=customs.country || record.importCountry || '';
            const value=(field,fallback=0)=>{const saved=customs[field] ?? record['import'+field[0].toUpperCase()+field.slice(1)];if(saved===undefined||saved===null||saved==='')return fallback;const number=Number(saved);if(!Number.isSafeInteger(number)){issues.push(record.number+': '+field+' must be saved in whole centavos.');return 0;}return number;};
            const taxable=value('taxable',amount.tax.kind==='VAT'?amount.net:0),exempt=value('exempt',amount.tax.kind==='VAT'?0:amount.net),vatPaid=value('vatPaid');
            if(customs.vatPaid===undefined&&record.importVatPaid===undefined)issues.push(record.number+': missing VAT paid to Customs.');
            if(!entry)issues.push(record.number+': missing import entry number.');if(!country)issues.push(record.number+': missing country.');
            if(!record.supplier)issues.push(record.number+': missing supplier.');
            rows.push({cells:[record.date.slice(0,7),entry,record.supplier || '',country,value('dutiable'),value('charges'),exempt,taxable,vatPaid],documents:[record.number]});stats.base+=taxable;stats.tax+=vatPaid;stats.count++;
        }
        rows.sort((a,b)=>a.cells[0].localeCompare(b.cells[0])||String(a.cells[1]).localeCompare(String(b.cells[1])));
        const totals=columns.map((_,index)=>numeric.includes(index)?rows.reduce((sum,row)=>sum+row.cells[index],0):'');totals[0]='Total';
        return {columns,rows,numeric,totals,stats,baseLabel:'Taxable importations',taxLabel:'VAT paid',note:'Posted purchases with an import VAT code or saved customs details appear here. Import entry number, country, dutiable value, charges, and VAT paid come from the saved purchase. Importation DAT export is not connected.',issues:[...new Set(issues)],available:true,emptyTitle:'No importations in this window',emptyDescription:'A posted bill whose line uses an import VAT rate appears here. Capture its customs details (import entry no., country, dates, OR no.) on the Purchases sheet.'};
    }
    function sawtSchedule(ledger,range,grouped,taxKind){
        const vat=taxKind==='vat',rows=[],issues=[],groups=new Map(),stats={base:0,tax:0,count:0};
        const columns=['Date','Payee','TIN','Branch','ATC','Income payment','Rate','Tax withheld','Status',...(!grouped?['Certificate','Source SI']:[])],numeric=[5,7];
        const sources=(ledger.sales?.() || []).filter(sale=>sale.status==='posted');
        for(const record of posted(ledger.cwtCertificates(),range)){
            const atc=String(record.atc || '').trim(),vatCertificate=/^W[VB]/i.test(atc);if(vat!==vatCertificate)continue;
            const name=record.customer || '',fullTin=tin(record.customerTin ?? record.tin),digits=fullTin.replace(/\D/g,''),validTin=hasTin(fullTin),base=record.taxBase || 0,amount=record.amount || 0;
            if(!name)issues.push(record.number+': missing party name.');if(!validTin)issues.push(record.number+': missing or invalid TIN.');if(!atc)issues.push(record.number+': ATC not recorded.');
            const source=sources.find(sale=>record.saleId?String(sale.id)===String(record.saleId):record.invoice&&sale.number===record.invoice);
            const linked=Boolean(source),status=linked?'Linked':'Unlinked';if(!linked)issues.push(record.number+': source sales invoice is missing or no longer posted.');
            const savedRate=record.rate ?? record.withholdingRate,rate=savedRate!==undefined&&savedRate!==null&&savedRate!==''&&Number.isFinite(Number(savedRate))?Number(savedRate):base?Number((amount/base*100).toFixed(2)):0;
            const baseTin=digits.length===14?digits.slice(0,9):'',branch=digits.length===14?digits.slice(9):'',rateLabel=rate+'%';
            const key=grouped?JSON.stringify([record.date,name.toLowerCase(),fullTin,atc,rateLabel,status]):JSON.stringify([record.id,record.number]);
            if(!groups.has(key))groups.set(key,{date:record.date,name,baseTin,branch,atc,base:0,amount:0,rate:rateLabel,status,certificate:record.certificate || record.number,invoice:record.invoice || '',documents:[]});
            const item=groups.get(key);item.base+=base;item.amount+=amount;item.documents.push(record.certificate || record.number);stats.base+=base;stats.tax+=amount;stats.count++;
        }
        for(const item of [...groups.values()].sort((a,b)=>a.date.localeCompare(b.date)||a.name.localeCompare(b.name)||a.atc.localeCompare(b.atc)))rows.push({cells:[item.date,item.name,item.baseTin,item.branch,item.atc,item.base,item.rate,item.amount,item.status,...(!grouped?[item.certificate,item.invoice]:[])],documents:item.documents});
        const totals=columns.map((_,index)=>numeric.includes(index)?rows.reduce((sum,row)=>sum+row.cells[index],0):'');totals[0]='Total';
        return {columns,rows,numeric,totals,stats,baseLabel:'Income payment',taxLabel:'Tax withheld',note:vat?'Reads posted VAT withholding certificates received, identified by their saved WB/WV ATC. Expected withholding on sales is excluded until a certificate is recorded.':'Reads posted 2307s received (CWT tab). A sale expecting CWT but missing its posted 2307 is not included yet. See the 2307 Monitor tab.',issues:[...new Set(issues)],available:true,emptyTitle:'No posted '+(vat?'VAT withholding certificates':'2307s')+' in this window',emptyDescription:vat?'VAT withholding certificates must be recorded separately with a VAT withholding ATC. Expected VAT withholding on sales is not a received certificate.':'Post a received certificate in the CWT sheet to include it here. Use 2307 Monitor to review sales still awaiting a certificate.'};
    }
    function qapSchedule(ledger,range,grouped,options={}){
        const noWithholding=options.qapSchedule==='2',masters=options.masters || {},groups=new Map(),issues=[],stats={base:0,tax:0,count:0};
        const purchases=ledger.purchases(),certificates=ledger.wtpCertificates();
        const columns=['TIN','Payee / Supplier','Branch','ATC','Income payment','Rate','Tax withheld',...(!grouped?['Date','Document','Source bill']:[])],numeric=[4,6];
        const sources=noWithholding?posted(purchases,range):posted(certificates,range);
        for(const record of sources){
            const bill=noWithholding?record:purchases.find(item=>String(item.id)===String(record.billId)),amount=noWithholding?ledger.purchaseAmounts(record):ledger.wtpAmounts(record);
            const rawAtc=String(ledger.wtpAtc?.(record) || record.ewtCode || record.atc || bill?.ewtCode || '').trim(),atc=rawAtc.match(/^W[IC][0-9]{3}/i)?.[0]?.toUpperCase() || '';
            if(noWithholding&&(rawAtc||Number(record.ewt)>0))continue;
            if(!noWithholding&&amount.ewt<=0)continue;
            const party=(masters.parties || []).find(item=>(!item.classification||['Supplier','Both'].includes(item.classification))&&(record.partyId?String(item.id)===String(record.partyId):String(item.name).trim().toLowerCase()===String(record.supplier || '').trim().toLowerCase()));
            const identity=[record.supplierTin,record.tin,party?.tin].find(value=>hasTin(value)) || '',digits=tin(identity).replace(/\D/g,''),name=record.supplier || party?.name || '',base=noWithholding?amount.net:amount.base,withheld=noWithholding?0:amount.ewt,rate=noWithholding?0:Number(record.ewt || 0);
            if(!hasTin(identity))issues.push(record.number+': missing or invalid supplier TIN.');if(!name)issues.push(record.number+': missing supplier name.');
            if(!noWithholding&&!atc)issues.push(record.number+': no saved source ATC; set it in WTP, Purchases, or the linked account in Masters.');
            if(!noWithholding&&bill&&bill.status!=='posted')issues.push(record.number+': source purchase is no longer posted.');
            if(!noWithholding&&record.billId&&!bill)issues.push(record.number+': source purchase is missing.');
            if(!Number.isSafeInteger(base)||base<=0||!Number.isSafeInteger(withheld)||withheld<0)issues.push(record.number+': review the saved tax base and withholding amount.');
            const key=grouped?JSON.stringify([digits,name.toUpperCase(),atc,rate]):String(record.id)+'|'+record.number;
            if(!groups.has(key))groups.set(key,{identity:digits,name,atc,rate,base:0,withheld:0,date:record.date,number:record.number,bill:bill?.number || '',documents:[],atcRecords:[],printRecord:!noWithholding&&atc?record:null});
            const item=groups.get(key);item.base+=base;item.withheld+=withheld;item.documents.push(record.number);if(!noWithholding&&!atc)item.atcRecords.push(record);stats.base+=base;stats.tax+=withheld;stats.count++;
        }
        const rows=[...groups.values()].sort((a,b)=>a.name.localeCompare(b.name)||a.atc.localeCompare(b.atc)||a.date.localeCompare(b.date)).map(item=>({cells:[item.identity.slice(0,9),item.name,item.identity.slice(9),item.atc,item.base,item.rate.toFixed(2)+'%',item.withheld,...(!grouped?[item.date,item.number,item.bill]:[])],documents:item.documents,printRecord:item.printRecord,atcRecords:item.atcRecords}));
        const totals=columns.map((_,index)=>numeric.includes(index)?rows.reduce((sum,row)=>sum+row.cells[index],0):'');totals[0]='Total';
        return {columns,rows,numeric,totals,stats,baseLabel:'Total income payments / Tax base',taxLabel:'Total EWT withheld',issues:[...new Set(issues)],available:true,note:noWithholding?'Schedule 2 reads posted purchases with no income withholding ATC or EWT rate. These rows are excluded from the withheld QAP DAT and Form 2307.':'Schedule 1 reads posted WTP income withholding certificates, including bill-linked certificates once. Withholding-tagged bills without a posted certificate are excluded. Form 2307 consolidates the supplier’s posted income withholding certificates for the chosen calendar quarter.',emptyTitle:noWithholding?'No purchases without withholding in this window':'No posted EWT certificates in this window',emptyDescription:noWithholding?'Posted bills without an income withholding ATC appear here.':'Post a certificate in WTP to include the payee. Draft and void certificates are excluded.'};
    }
    function qapDatPayload(ledger,company,range,options={}){
        const year=Number(range.to.slice(0,4)),quarter=Math.ceil(Number(range.to.slice(5,7))/3),from=year+'-'+String((quarter-1)*3+1).padStart(2,'0')+'-01',to=new Date(Date.UTC(year,quarter*3,0)).toISOString().slice(0,10);
        if(!options.annual&&(range.from!==from||range.to!==to))throw Error('Select one complete calendar quarter (Q1–Q4) before exporting QAP DAT.');
        const report=qapSchedule(ledger,range,false,{masters:options.masters});
        if(report.issues.length)throw Error(report.issues[0]);
        const records=report.rows.map(row=>{const record=row.printRecord;if(!record)throw Error('Select a valid income withholding ATC before exporting.');const party=(options.masters?.parties || []).find(item=>(!item.classification||['Supplier','Both'].includes(item.classification))&&(record.partyId?String(item.id)===String(record.partyId):String(item.name).trim().toLowerCase()===String(record.supplier || '').trim().toLowerCase()));return {payee_id:record.partyId || party?.id || null,source_id:String(record.id),date:record.date,status:record.status,tin:row.cells[0]+row.cells[2],entity_type:record.entity_type || party?.entity_type || 'corporation',registered_name:row.cells[1],last_name:record.last_name || party?.last_name || '',first_name:record.first_name || party?.first_name || '',middle_name:record.middle_name || party?.middle_name || '',atc:row.cells[3],income_payment:(row.cells[4]/100).toFixed(2),tax_rate:Number(record.ewt).toFixed(2),amount_withheld:(row.cells[6]/100).toFixed(2)};});
        if(!records.length)throw Error('No posted EWT certificates in the selected '+(options.annual?'year.':'quarter.'));
        return {quarter,year,records};
    }
    function qapAnnualDatPayload(ledger,company,year,options={}){
        if(!Number.isInteger(year)||year<1900||year>2199)throw Error('Choose a valid reporting year.');
        const payload=qapDatPayload(ledger,company,{from:year+'-01-01',to:year+'-12-31'},{...options,annual:true});
        delete payload.quarter;
        return payload;
    }
    function build(ledger,schedule,range,grouped=true,options={}){
        if(schedule==='qap-ewt')return qapSchedule(ledger,range,grouped,options);
        if(schedule==='sawt')return sawtSchedule(ledger,range,grouped,options.sawtTax || 'income');
        if(schedule==='relief-purchases')return purchaseSchedule(ledger,range,grouped);
        if(schedule==='relief-importation')return importationSchedule(ledger,range);
        const rows=[],issues=[],stats={base:0,tax:0,count:0},numeric=[];
        let columns=[],note='',baseLabel='Tax base',taxLabel='Tax amount';
        const party=(record,supplier=false)=>({name:record[supplier?'supplier':'customer'] || '',tin:tin(record[supplier?'supplierTin':'customerTin'] ?? record.tin)});
        const check=(record,identity)=>{if(!identity.name)issues.push(record.number+': missing party name.');if(!hasTin(identity.tin))issues.push(record.number+': missing or invalid TIN.');};
        if(['relief-sales','relief-purchases'].includes(schedule)){
            const purchases=schedule==='relief-purchases',records=posted(purchases?ledger.purchases():ledger.sales(),range);
            baseLabel=purchases?'Net taxable purchases':'Net taxable sales';taxLabel=purchases?'Input VAT':'Output VAT';
            columns=[grouped?'Month':'Date',purchases?'Supplier':'Customer','TIN',...(!grouped?['Document']:[]),'Exempt','Zero-rated','Taxable','Non-VAT','Rate',taxLabel,purchases?'Net purchases':'Net sales','Gross amount'];
            const start=grouped?3:4;numeric.push(start,start+1,start+2,start+3,start+5,start+6,start+7);
            const groups=new Map();
            for(const record of records){
                const identity=party(record,purchases),amount=ledger.taxAmounts(record.gross,record.tax),info=amount.tax,vat=record.gross-amount.net;
                check(record,identity);stats.count++;stats.tax+=vat;
                const values=[0,0,0,0];const bucket=info.kind==='VAT'?2:info.kind==='Zero Rated'?1:info.code==='NONVAT'?3:0;values[bucket]=amount.net;if(bucket===2)stats.base+=amount.net;
                const key=grouped?record.date.slice(0,7)+'|'+identity.name.toLowerCase()+'|'+identity.tin:record.id+'|'+record.number;
                if(!groups.has(key))groups.set(key,{date:grouped?record.date.slice(0,7):record.date,name:identity.name,tin:identity.tin,number:record.number,values:[0,0,0,0],vat:0,net:0,gross:0,rates:new Set(),documents:[]});
                const item=groups.get(key);values.forEach((value,index)=>item.values[index]+=value);item.vat+=vat;item.net+=amount.net;item.gross+=record.gross;if(bucket===2)item.rates.add(info.rate ?? 12);item.documents.push(record.number);
            }
            for(const item of [...groups.values()].sort((a,b)=>a.date.localeCompare(b.date)||a.name.localeCompare(b.name)))rows.push({cells:[item.date,item.name,item.tin,...(!grouped?[item.number]:[]),...item.values,[...item.rates].map(rate=>rate+'%').join(' / ') || '—',item.vat,item.net,item.gross],documents:item.documents});
            note='Posted '+(purchases?'Purchases':'Sales')+' only. Non-VAT amounts are shown separately from VAT-exempt amounts. Party TINs come from saved transaction details.';
        }else if(schedule==='2307-monitor'){
            columns=['Date','Customer','TIN','Source SI','Expected CWT','Received CWT','Unrecorded','Status'];numeric.push(4,5,6);baseLabel='Expected CWT';taxLabel='Received CWT';
            const certificates=ledger.cwtCertificates().filter(record=>record.status==='posted'&&record.date<=range.to);
            for(const sale of posted(ledger.sales(),range)){
                const source=ledger.cwtSource(sale.id);if(!source?.withheld)continue;const identity=party(sale);check(sale,identity);
                const received=certificates.filter(record=>String(record.saleId)===String(sale.id)||record.invoice===sale.number).reduce((sum,record)=>sum+record.amount,0),remaining=source.withheld-received;
                rows.push({cells:[sale.date,identity.name,identity.tin,sale.number,source.withheld,received,remaining,remaining>0?'Incomplete':remaining<0?'Over-recorded':'Recorded'],documents:[sale.number]});stats.base+=source.withheld;stats.tax+=received;stats.count++;
            }
            note='Sales in the selected period with expected CWT, matched to posted certificates received through the ending date. Unrecorded amounts do not create certificates automatically.';
        }else{
            columns=['Date','Name','TIN','Reference','Amount'];numeric.push(4);
            note=({'relief-importation':'Importation requires customs / import VAT records, which are not captured in the current sheets.','qap-fwt':'Final withholding tax classification and source transactions are not captured in the current sheets.','alphalist-non-mwe':'The employee and payroll sources do not yet capture the MWE classification and annual alphalist fields. No employees have been classified automatically.','alphalist-mwe':'Minimum wage earner classification is not captured in Employee Masters yet. No employees have been classified automatically.','sc-pwd':'SC/PWD eligibility, discount and identification details are not captured in Sales yet.'})[schedule];
        }
        const totals=columns.map((_,index)=>numeric.includes(index)?rows.reduce((sum,row)=>sum+row.cells[index],0):'');totals[0]='Total';
        return {columns,rows,numeric,totals,stats,baseLabel,taxLabel,note,issues:[...new Set(issues)],available:!['relief-importation','qap-fwt','alphalist-non-mwe','alphalist-mwe','sc-pwd'].includes(schedule)};
    }
    function sawtDatPayload(ledger,company,range,options={}){
        const vat=options.taxKind==='vat',masters=options.masters || {},baseTin=String(company.tin || '').trim(),digits=baseTin.replace(/\D/g,''),companyTin=digits.length===9?digits+String(company.branch_code || '').padStart(5,'0'):baseTin;
        const records=posted(ledger.cwtCertificates(),range).filter(record=>vat===/^W[VB]/i.test(String(record.atc || '').trim()));
        if(!records.length)throw Error('No posted received certificates in the selected SAWT schedule and date range.');
        return {form_type:options.formType || (company.entity_type==='individual'?'1701Q':'1702Q'),tax_kind:vat?'vat':'income',from:range.from,to:range.to,taxpayer:{tin:companyTin,entity_type:company.entity_type || 'corporation',registered_name:company.registered_name || company.company_name || '',last_name:company.last_name || '',first_name:company.first_name || '',middle_name:company.middle_name || '',rdo:String(company.rdo || '')},records:records.map(record=>{
            if(!Number.isSafeInteger(record.taxBase)||!Number.isSafeInteger(record.amount)||record.taxBase<=0||record.amount<=0)throw Error(record.number+': correct the saved certificate amounts before exporting.');
            const rawAtc=String(record.atc || ''),atc=rawAtc.match(/^W[ICVB][0-9]{3}/i)?.[0]?.toUpperCase() || '',party=masters.parties?.find(item=>(!item.classification||['Customer','Both'].includes(item.classification))&&(record.partyId?String(item.id)===String(record.partyId):String(item.name).toLowerCase()===String(record.customer).toLowerCase()));
            const rate=record.rate ?? record.withholdingRate ?? rawAtc.match(/([0-9]+(?:\.[0-9]+)?)%/)?.[1] ?? Number((record.amount/record.taxBase*100).toFixed(2));
            const candidateTin=[record.customerTin,record.tin,party?.tin].find(value=>String(value || '').trim()&&/[1-9]/.test(String(value))) || '';
            return {source_id:String(record.id ?? record.number),document_number:record.certificate || record.number,date:record.date,status:record.status,tin:candidateTin,entity_type:record.entity_type || party?.entity_type || 'corporation',registered_name:record.customer || '',last_name:record.last_name || party?.last_name || '',first_name:record.first_name || party?.first_name || '',middle_name:record.middle_name || party?.middle_name || '',nature_income:record.nature_income ?? '',atc,tax_rate:Number(rate).toFixed(2),income_payment:(record.taxBase/100).toFixed(2),amount_withheld:(record.amount/100).toFixed(2)};
        })};
    }
    function salesDatPayload(ledger,company,range){
        const records=posted(ledger.sales(),range).sort((a,b)=>a.date.localeCompare(b.date)||String(a.number).localeCompare(String(b.number)));
        if(!records.length)throw Error('No posted sales rows found in the selected reporting period to export.');
        const salesRecords=records.map(record=>{
            const value=keys=>{for(const key of keys){if(record[key]!==undefined&&record[key]!==null&&String(record[key]).trim()!=='')return record[key];}return undefined;};
            const cents=(input,label)=>{
                const text=String(input).trim();
                if(typeof input!=='number'&&!/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/.test(text))throw Error('Sale '+record.number+': invalid '+label+'.');
                const result=Math.round(Number(text.replace(/,/g,''))*100);
                if(!Number.isSafeInteger(result))throw Error('Sale '+record.number+': invalid '+label+'.');
                return result;
            };
            const explicitGross=value(['gross_amount','Gross amount','GrossAmount','grossAmount']);
            const gross=explicitGross!==undefined?cents(explicitGross,'gross amount'):record.gross;
            if(!Number.isSafeInteger(gross))throw Error('Sale '+record.number+': missing or invalid gross amount.');
            const amounts=ledger.taxAmounts(gross,record.tax),info=amounts.tax;
            if(info.code==='NONVAT')throw Error('Sale '+record.number+' is Non-VAT. The supplied DAT layout has no Non-VAT amount field.');
            const aliases=[
                ['exempt_amount','Exempt','exempt_sales','exemptSales','exempt'],
                ['zero_rated_amount','Zero-rated','ZeroRated','zero_rated_sales','zeroRatedSales','zero_rated'],
                ['taxable_amount','Taxable','taxable_sales','taxableSales','taxable'],
                ['output_vat_amount','Output VAT','OutputVat','output_vat','outputVat','vat']
            ];
            const provided=aliases.map(value),hasBreakdown=provided.some(item=>item!==undefined);
            const fallback=hasBreakdown?[0,0,0,0]:[info.kind!=='VAT'&&info.kind!=='Zero Rated'?amounts.net:0,info.kind==='Zero Rated'?amounts.net:0,info.kind==='VAT'?amounts.net:0,gross-amounts.net];
            const figures=provided.map((item,index)=>item===undefined?fallback[index]:cents(item,aliases[index][0]));
            if(hasBreakdown&&provided[3]===undefined)figures[3]=gross-figures[0]-figures[1]-figures[2];
            if(figures.reduce((sum,item)=>sum+item,0)!==gross)throw Error('Sale '+record.number+': sales breakdown and output VAT do not equal the gross amount.');
            const identity={};
            for(const [field,keys] of Object.entries({customer_street:['customer_street','customerStreet'],customer_city:['customer_city','customerCity'],last_name:['customerLastName','customer_last_name','last_name'],first_name:['customerFirstName','customer_first_name','first_name'],middle_name:['customerMiddleName','customer_middle_name','middle_name']})){
                const input=value(keys);if(input!==undefined)identity[field]=String(input);
            }
            return {
                date:record.date,source_key:String(record.id ?? record.number),document_number:record.number,account_code:String(record.account || '').split(' · ')[0],account_name:ledger.accountProfile?.(String(record.account || '').split(' · ')[0])?.name || record.account || '',relief_category:ledger.accountProfile?.(String(record.account || '').split(' · ')[0])?.relief_category || '',...identity,
                customer_tin:String(value(['customerTin','customer_tin','TIN','tin']) ?? '').replace(/-/g,''),
                customer_name:String(value(['customer','customer_name','customerName','Customer']) ?? 'VARIOUS CUSTOMERS'),
                customer_address:String(value(['customerAddress','customer_address','Address','address']) ?? ''),
                gross_amount:gross/100,exempt_amount:figures[0]/100,zero_rated_amount:figures[1]/100,taxable_amount:figures[2]/100,output_vat_amount:figures[3]/100
            };
        });
        return {...datMetadata(company,range),sales_records:salesRecords};
    }
    function datMetadata(company,range){
        const base=String(company.tin || '').trim(),branch=String(company.branch_code || '').padStart(5,'0');
        const companyTin=base.replace(/-/g,'').length===9?base.replace(/-/g,'')+branch:base;
        return {
            company_tin:companyTin,company_name:company.registered_name || company.company_name,
            non_individual:company.entity_type!=='individual',last_name:company.last_name || '',first_name:company.first_name || '',middle_name:company.middle_name || '',
            trade_name:company.trade_name || company.company_name,rdo:String(company.rdo || ''),period_end:company.accounting_period==='fiscal'?Number(company.fiscal_end_month):12,
            street:company.dat_street ?? ['building','street','barangay'].map(key=>company[key]).filter(Boolean).join(' '),
            city:company.dat_city ?? ['city','province','zip_code'].map(key=>company[key]).filter(Boolean).join(' '),
            from:range.from,to:range.to,tax_period:range.to.slice(5,7)+'/'+range.to.slice(0,4),};
    }
    function purchasesDatPayload(ledger,company,range,masters={},overrides={}){
        const records=posted(ledger.purchases(),range).filter(record=>!isImportPurchase(record,ledger)).sort((a,b)=>a.date.localeCompare(b.date)||String(a.number).localeCompare(String(b.number)));
        if(!records.length)throw Error('No posted purchases rows found in the selected reporting period to export.');
        const purchases=records.map(record=>{
            const key=String(record.id ?? record.number),override=overrides[key] || {};
            if(!Number.isSafeInteger(record.gross))throw Error('Purchase '+record.number+': invalid gross amount.');
            const amounts=ledger.taxAmounts(record.gross,record.tax),info=amounts.tax;
            const accountCode=String(record.account || '').split(' · ')[0];
            const profile=ledger.accountProfile?.(accountCode);
            const party=(masters.parties || []).find(item=>(!item.classification||['Supplier','Both'].includes(item.classification))&&(record.partyId?String(item.id)===String(record.partyId):String(item.name).trim().toLowerCase()===String(record.supplier || '').trim().toLowerCase()));
            const candidates=[record.supplierTin,record.tin,party?.tin];
            const savedTin=candidates.find(value=>String(value || '').trim()&&!/^0+$/.test(String(value).replace(/-/g,''))) || '';
            const individual={};
            for(const field of ['last_name','first_name','middle_name']){
                const value=record['supplier_'+field] ?? record[field];if(value!==undefined)individual[field]=String(value);
            }
            return {
                date:record.date,source_key:key,document_number:record.number,account_code:accountCode,account_name:profile?.name || record.account || '',...individual,
                supplier_name:String(record.supplier || record.supplier_name || ''),supplier_tin:String(override.supplier_tin ?? savedTin),
                supplier_address:String(record.supplierAddress || record.supplier_address || party?.address || ''),
                ...(record.supplierStreet?{supplier_street:String(record.supplierStreet)}:{}),...(record.supplierCity?{supplier_city:String(record.supplierCity)}:{}),
                relief_category:profile?.relief_category || '',
                gross_amount:record.gross/100,exempt_amount:info.kind!=='VAT'&&info.kind!=='Zero Rated'?amounts.net/100:0,
                zero_rated_amount:info.kind==='Zero Rated'?amounts.net/100:0,taxable_amount:info.kind==='VAT'?amounts.net/100:0,
                input_vat_amount:(record.gross-amounts.net)/100,
                non_creditable_vat_amount:Number(override.non_creditable_vat_amount ?? record.non_creditable_vat_amount ?? record.nonCreditableVatAmount ?? 0)
            };
        });
        return {...datMetadata(company,range),purchase_records:purchases};
    }
    function purchasesExcelRows(ledger, range, masters = {}) {
        const groups = new Map();
        for (const record of posted(ledger.purchases(), range).filter(item => !isImportPurchase(item, ledger))) {
            const name = String(record.supplier || '').trim();
            const party = (masters.parties || []).find(item => (!item.classification || ['Supplier', 'Both'].includes(item.classification)) && (record.partyId ? String(item.id) === String(record.partyId) : String(item.name).trim().toLowerCase() === name.toLowerCase()));
            const tinCandidates = [record.supplierTin, record.tin, party?.tin];
            const savedTin = tinCandidates.find(value => String(value || '').trim() && /[1-9]/.test(String(value).replace(/\D/g, ''))) || tinCandidates.find(value => String(value || '').trim()) || '';
            const identity = tin(savedTin);
            const digits = identity.replace(/\D/g, ''), baseTin = digits.length >= 9 ? digits.slice(0, 9).replace(/(\d{3})(\d{3})(\d{3})/, '$1-$2-$3') : identity;
            const individual = (record.supplierEntityType || record.entity_type || party?.entity_type) === 'individual';
            const last = record.supplier_last_name || record.last_name || party?.last_name || '', first = record.supplier_first_name || record.first_name || party?.first_name || '', middle = record.supplier_middle_name || record.middle_name || party?.middle_name || '';
            const personalName = last && first ? `${last}, ${first}${middle ? ' ' + middle : ''}` : name;
            const address = String(record.supplierAddress || record.supplier_address || party?.address || '');
            const month = record.date.slice(0, 7), key = JSON.stringify([month, identity, name.toLowerCase(), individual, address.trim().toLowerCase()]);
            const amount = ledger.taxAmounts(record.gross, record.tax), isVat = amount.tax.kind === 'VAT', isZero = amount.tax.kind === 'Zero Rated';
            const category = ledger.accountProfile?.(String(record.account || '').split(' · ')[0])?.relief_category;
            if (!Number.isSafeInteger(record.gross) || !Number.isSafeInteger(amount.net)) throw Error(`${record.number}: invalid purchase amount.`);
            if (!groups.has(key)) groups.set(key, [month, baseTin, individual ? '' : name, individual ? personalName : '', address, ...Array(9).fill(0)]);
            const row = groups.get(key), taxable = isVat ? amount.net : 0, vat = record.gross - amount.net;
            const values = [amount.net, !isVat && !isZero ? amount.net : 0, isZero ? amount.net : 0, taxable, isVat && category === 'services' ? taxable : 0, isVat && category === 'capital_goods' ? taxable : 0, isVat && category === 'goods' ? taxable : 0, vat, taxable + vat];
            for (let index = 0; index < values.length; index++) row[index + 5] += values[index];
        }
        return [...groups.values()].sort((a, b) => a[0].localeCompare(b[0]) || (a[2] || a[3]).localeCompare(b[2] || b[3])).map(row => row.map((value, index) => index >= 5 ? value / 100 : value));
    }
    const api={qapAnnualDatPayload,qapDatPayload,build,salesDatPayload,purchasesDatPayload,sawtDatPayload,purchasesExcelRows};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root)root.TaxReportData=api;
})(typeof window==='undefined'?null:window);
