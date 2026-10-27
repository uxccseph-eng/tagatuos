(function(root){
    const columns={
        sales:['Date','Customer','Gross Amount','Tax Type','Revenue Account','Income Withholding ATC','VAT Withholding ATC','Terms','Invoice Reference','Classification','Deposit Account','Description','Particulars'],
        purchases:['Date','Supplier','Supplier TIN','Gross Amount','Tax Type','Account','Income Withholding ATC','VAT Withholding ATC','Terms','Invoice Reference','Bank','Description','Notes'],
        collections:['Date','Customer','Source Invoice','Amount','Bank','Reference','Payment Method','Entry Type','Notes'],
        payments:['Date','Supplier','Supplier TIN','Source Bill','Amount','Bank','Payment Method','Reference','Entry Type','Account','Notes'],
        cwt:['Date','Source Invoice','Customer TIN','Certificate Number','Notes'],
        wtp:['Date','Source Bill','Supplier','Supplier TIN','Tax Base','Income Withholding ATC','VAT Withholding ATC','Offset Account','Certificate Number','Notes'],
        journal:['Voucher Key','Date','Reference','Particulars','Entry Type','Account','Party','Debit','Credit','Remarks','Notes']
    };
    const methods={sales:'sales',purchases:'purchases',collections:'collections',payments:'payments',cwt:'cwtCertificates',wtp:'wtpCertificates',journal:'journalEntries'};
    const key=value=>String(value || '').trim().toLowerCase().replace(/[^a-z0-9]/g,'');
    function parseDelimited(text,preserveEmpty=false,delimiter=','){
        text=String(text).replace(/^\uFEFF/,'');const rows=[];let row=[],cell='',quoted=false,closed=false;
        for(let i=0;i<text.length;i++){
            const char=text[i];
            if(quoted){if(char==='"'){if(text[i+1]==='"'){cell+='"';i++;}else{quoted=false;closed=true;}}else cell+=char;continue;}
            if(char==='"'){if(cell||closed)throw Error('Unexpected quote in CSV. Use the downloaded template and save as CSV UTF-8.');quoted=true;}
            else if(char===delimiter||char==='\n'||char==='\r'){row.push(cell);cell='';closed=false;if(char!==delimiter){if(char==='\r'&&text[i+1]==='\n')i++;if(preserveEmpty||row.some(value=>value.trim()))rows.push(row);row=[];}}
            else {if(closed&&!/\s/.test(char))throw Error('Unexpected text after a quoted CSV field.');if(!closed)cell+=char;}
        }
        if(quoted)throw Error('The CSV contains an unclosed quoted field.');row.push(cell);if(row.some(value=>value.trim()))rows.push(row);return rows;
    }
    function parseCsv(text,preserveEmpty=false){return parseDelimited(text,preserveEmpty);}
    function parseClipboard(text){
        text=String(text);if(text.length>5*1024*1024)throw Error('Paste up to 5 MB of spreadsheet cells.');
        if(text.includes('\0'))throw Error('Paste plain spreadsheet cells without binary content.');
        let rows;try{rows=parseDelimited(text,true,'\t');}catch(error){throw Error(error.message.replaceAll('CSV','pasted cells').replace('Use the downloaded template and save as pasted cells UTF-8.','Copy the cells again from Excel or Google Sheets.'));}
        if(!rows.some(row=>row.some(cell=>cell.trim())))throw Error('Paste cells copied from Excel or Google Sheets.');
        if(rows.length>521||rows.some(row=>row.length>100))throw Error('Paste up to 500 entries, 100 columns and a header area of at most 20 rows.');
        if(rows.some(row=>row.some(cell=>cell.length>4000)))throw Error('Each pasted cell must contain at most 4,000 characters.');
        return rows;
    }
    function sampleRows(sheet){
        const date='2026-10-01',customer='REPLACE WITH CUSTOMER CODE',supplier='REPLACE WITH SUPPLIER CODE',bank='REPLACE WITH CASH OR BANK ACCOUNT';
        const samples={
            sales:[{Date:date,Customer:customer,'Gross Amount':'1120.00','Tax Type':'VAT','Revenue Account':'REPLACE WITH INCOME ACCOUNT','Income Withholding ATC':'None','VAT Withholding ATC':'None',Terms:'On Account','Invoice Reference':'SAMPLE-SI-001-REPLACE',Classification:'Regular','Deposit Account':bank,Description:'SAMPLE ONLY - replace with your actual sale',Particulars:'Replace or delete this sample row'}],
            purchases:[{Date:date,Supplier:supplier,'Supplier TIN':'','Gross Amount':'1120.00','Tax Type':'VAT',Account:'REPLACE WITH EXPENSE ACCOUNT','Income Withholding ATC':'None','VAT Withholding ATC':'None',Terms:'On Account','Invoice Reference':'SAMPLE-BILL-001-REPLACE',Bank:bank,Description:'SAMPLE ONLY - replace with your actual purchase',Notes:'Replace or delete this sample row'}],
            collections:[{Date:date,Customer:customer,'Source Invoice':'REPLACE WITH POSTED INVOICE NUMBER',Amount:'1000.00',Bank:bank,Reference:'SAMPLE-COL-001-REPLACE','Payment Method':'Bank Transfer','Entry Type':'Settlement',Notes:'SAMPLE ONLY - replace with actual receipt details'}],
            payments:[{Date:date,Supplier:supplier,'Supplier TIN':'','Source Bill':'REPLACE WITH POSTED BILL NUMBER',Amount:'1000.00',Bank:bank,'Payment Method':'Bank Transfer',Reference:'SAMPLE-PAY-001-REPLACE','Entry Type':'AP Settlement',Account:'',Notes:'SAMPLE ONLY - replace with actual payment details'}],
            cwt:[{Date:date,'Source Invoice':'REPLACE WITH INVOICE WITH INCOME WITHHOLDING','Customer TIN':'','Certificate Number':'SAMPLE-2307-001-REPLACE',Notes:'SAMPLE ONLY - use an actual source invoice and certificate'}],
            wtp:[{Date:date,'Source Bill':'REPLACE WITH BILL WITH WITHHOLDING',Supplier:'','Supplier TIN':'','Tax Base':'','Income Withholding ATC':'','VAT Withholding ATC':'','Offset Account':'REPLACE WITH PAYABLE ACCOUNT','Certificate Number':'SAMPLE-WTP-001-REPLACE',Notes:'SAMPLE ONLY - source bill supplies the tax details'}],
            journal:[{Account:'REPLACE WITH DEBIT ACCOUNT',Debit:'1000.00',Credit:'0.00'},{Account:'REPLACE WITH CREDIT ACCOUNT',Debit:'0.00',Credit:'1000.00'}].map(line=>({'Voucher Key':'SAMPLE-JV-001',Date:date,Reference:'SAMPLE-JV-001-REPLACE',Particulars:'SAMPLE ONLY - balanced two-line journal','Entry Type':'General',Party:'',Remarks:'Replace with actual journal details',Notes:'Replace both sample lines before importing',...line}))
        };
        if(!samples[sheet])throw Error('Unknown sheet.');
        return samples[sheet];
    }
    function template(sheet,withSamples=false){
        if(!columns[sheet])throw Error('Unknown sheet.');
        const rows=[columns[sheet],...(withSamples?sampleRows(sheet).map(row=>columns[sheet].map(column=>row[column]??'')):[])];
        return '\uFEFF'+rows.map(row=>row.map(value=>'"'+String(value).replace(/"/g,'""')+'"').join(',')).join('\r\n')+'\r\n';
    }
    function amount(value,label='Amount',zero=false){const text=String(value || '').replace(/[₱,\s]/g,'');if(!text&&zero)return 0;if(!/^\d{1,9}(\.\d{1,2})?$/.test(text))throw Error(label+' must be a number with no more than two decimal places.');const [whole,fraction='']=text.split('.');const cents=Number(whole)*100+Number(fraction.padEnd(2,'0'));if(!zero&&cents<=0)throw Error(label+' must be greater than zero.');return cents;}
    function date(value){let text=String(value || '').trim();const match=text.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);if(match)text=match[3]+'-'+match[1].padStart(2,'0')+'-'+match[2].padStart(2,'0');const parsed=new Date(text+'T12:00:00Z');if(!/^\d{4}-\d{2}-\d{2}$/.test(text)||!Number.isFinite(parsed.getTime())||parsed.toISOString().slice(0,10)!==text)throw Error('Date must be a real date in YYYY-MM-DD or MM/DD/YYYY format.');return text;}
    function fingerprint(sheet,record){const fields={sales:['date','customer','gross','reference','account','tax','terms','cwt','vatCwt'],purchases:['date','supplier','gross','reference','account','tax','terms','ewt','wvat'],collections:['date','customer','invoice','amount','reference','type'],payments:['date','supplier','bill','amount','reference','type','account'],cwt:['date','invoice','customer','tin','taxBase','atc','amount'],wtp:['date','bill','supplier','tin','taxBase','ewt','wvat','ewtCode','wvatCode','offset'],journal:['date','reference','type','lines']}[sheet];return JSON.stringify(fields.map(field=>{const value=record[field];return typeof value==='string'?value.trim().toLowerCase():value ?? '';}));}
    function build(sheet,raw,context){
        const {ledger,masters,accounts}=context;
        const get=name=>raw[key(name)] || '',find=(items,value,label,predicate=()=>true)=>{const normalized=key(value);const matches=items.filter(item=>predicate(item)&&[item.code,item.name,item.value,item.label,item.number,item.code&&item.name?item.code+' · '+item.name:''].some(text=>text&&key(text)===normalized));if(!normalized||matches.length!==1)throw Error(label+' must match one active record in Masters.');return matches[0];};
        const account=(value,types=null,cash=false)=>{const item=find(accounts.filter(item=>!item.archived),value,'Account',item=>(!types||types.includes(item.type))&&(!cash||item.is_cash_bank));return item.code+' · '+item.name;};
        const bank=value=>{if(!value)return account(accounts.find(item=>!item.archived&&item.is_cash_bank&&item.code==='1010')?.code || accounts.find(item=>!item.archived&&item.is_cash_bank)?.code,null,true);const named=masters.bankChoices().filter(item=>key(item.value)===key(value)||key(item.label)===key(value));return named.length===1?named[0].value:account(value,null,true);};
        const party=(value,kind)=>find(masters.parties(kind),value,kind),tax=(value,sales)=>find(masters.taxChoices(sales),value || 'VAT','Tax type').value;
        const sourceParty=(value,source,kind)=>source&&(!value||key(value)===key(source.customer || source.supplier))?{name:source.customer || source.supplier,tin:source.tin}:party(value,kind);
        const atc=(value,vat=false)=>{if(!value||['none','0','0%'].includes(value.toLowerCase()))return {rate:0,label:'None',code:''};const item=find(masters.atcCodes(vat?'VAT Withholding':'Income-tax CWT'),value,'Withholding ATC');return {rate:Number(item.rate),label:item.code+' · '+Number(item.rate)+'%',code:item.code};};
        const choice=(value,values,fallback)=>{const selected=values.find(item=>key(item)===key(value || fallback));if(!selected)throw Error('Choose '+values.join(', ')+'.');return selected;};
        const record={date:date(get('Date')),notes:get('Notes'),_row:raw._row};
        if(sheet==='sales'||sheet==='purchases'){
            const sales=sheet==='sales',person=party(get(sales?'Customer':'Supplier'),sales?'Customer':'Supplier');
            const draft={status:'draft',[sales?'customer':'supplier']:person.name,account:get(sales?'Revenue Account':'Account'),tax:get('Tax Type'),...(sales?{cwt:get('Income Withholding ATC'),vatCwt:get('VAT Withholding ATC')}:{ewt:'',wvat:get('VAT Withholding ATC')?'':0,ewtCode:get('Income Withholding ATC'),wvatCode:get('VAT Withholding ATC')})};
            const suggestion=masters.partyDefaults?.(draft,sales) || {values:{},sourceNumber:''},defaults=suggestion.values;
            const suggestedAtc=vat=>sales?String(defaults[vat?'vatCwt':'cwt'] || '').match(/^W[ICVB][0-9]{3}/)?.[0] || '':defaults[vat?'wvatCode':'ewtCode'] || '';
            const it=atc(get('Income Withholding ATC') || suggestedAtc(false)),vt=atc(get('VAT Withholding ATC') || suggestedAtc(true),true);
            const taxValue=get('Tax Type') || defaults.tax;
            if(context.guided&&!taxValue)throw Error('Choose a Tax Type from Masters, or apply one to the selected rows.');
            if(suggestion.sourceNumber)record._suggestion={source:suggestion.sourceNumber,fields:Object.keys(defaults)};
            Object.assign(record,{[sales?'customer':'supplier']:person.name,tin:masters.formatTin(get('Supplier TIN') || person.tin || ''),partyId:person.id,gross:amount(get('Gross Amount'),'Gross amount'),tax:tax(taxValue,sales),account:account(get(sales?'Revenue Account':'Account') || defaults.account,sales?['Income']:['Asset','Expense']),terms:choice(get('Terms'),['Cash','On Account'],'Cash'),reference:get('Invoice Reference'),classification:choice(get('Classification'),['Regular','Top Withholding Agent','Government','ZeroRated','PEZA'],'Regular'),bank:bank(get(sales?'Deposit Account':'Bank'))});
            if(sales)Object.assign(record,{cwt:it.label,vatCwt:vt.label,memo:get('Description'),notes:get('Particulars')});else Object.assign(record,{ewt:it.rate,wvat:vt.rate,ewtCode:it.code,wvatCode:vt.code,description:get('Description')});
        }else if(sheet==='collections'){
            const invoice=get('Source Invoice')?find(ledger.invoices(),get('Source Invoice'),'Source invoice'):null;
            const person=sourceParty(get('Customer'),invoice,'Customer');Object.assign(record,{customer:person.name,invoice:invoice?.number || 'Unlinked / Advance Deposit',saleId:invoice?.id || null,amount:amount(get('Amount')),bank:bank(get('Bank') || invoice?.bank),reference:get('Reference'),method:choice(get('Payment Method'),['Cash','Check','Bank Transfer','GCash','Maya','Other'],'Cash'),type:choice(get('Entry Type'),['Settlement','Downpayment','Advance'],invoice?'Settlement':'Advance'),origin:'manual'});
        }else if(sheet==='payments'){
            const bill=get('Source Bill')?find(ledger.payableBills(),get('Source Bill'),'Source bill'):null,person=sourceParty(get('Supplier'),bill,'Supplier');Object.assign(record,{supplier:person.name,tin:masters.formatTin(get('Supplier TIN') || bill?.tin || person.tin || ''),billId:bill?.id || null,bill:bill?.number || '',amount:amount(get('Amount')),bank:bank(get('Bank') || bill?.bank),reference:get('Reference'),method:choice(get('Payment Method'),['Cash','Check','Bank Transfer','GCash','Maya','Other'],'Cash'),type:choice(get('Entry Type'),['AP Settlement','Direct Expense','Supplier Advance'],bill?'AP Settlement':'Direct Expense'),account:bill?account('2010'):get('Account')?account(get('Account'),['Asset','Expense']):'',origin:'manual'});
        }else if(sheet==='cwt'){
            const sale=find(ledger.sales().filter(item=>item.status==='posted'),get('Source Invoice'),'Source invoice'),source=ledger.cwtSource(sale.id);Object.assign(record,{saleId:sale.id,invoice:sale.number,customer:sale.customer,tin:masters.formatTin(get('Customer TIN') || sale.tin || ''),taxBase:source.taxBase,atc:sale.cwt,amount:source.withheld,offset:account('1150'),certificate:get('Certificate Number')});
        }else if(sheet==='wtp'){
            const bill=get('Source Bill')?find(ledger.purchases().filter(item=>item.status==='posted'),get('Source Bill'),'Source bill'):null,source=bill?ledger.wtpSource(bill.id):null,person=sourceParty(get('Supplier'),bill,'Supplier'),it=get('Income Withholding ATC')?atc(get('Income Withholding ATC')):{rate:source?.ewt || 0,code:source?.ewtCode || ''},vt=get('VAT Withholding ATC')?atc(get('VAT Withholding ATC'),true):{rate:source?.wvat || 0,code:source?.wvatCode || ''};
            Object.assign(record,{billId:bill?.id || null,bill:bill?.number || '',supplier:person.name,tin:masters.formatTin(get('Supplier TIN') || bill?.tin || person.tin || ''),taxBase:get('Tax Base')?amount(get('Tax Base'),'Tax base'):source?.taxBase || 0,ewt:it.rate,wvat:vt.rate,ewtCode:it.code,wvatCode:vt.code,offset:account(get('Offset Account') || '2010'),certificate:get('Certificate Number')});
        }else if(sheet==='journal'){
            Object.assign(record,{voucherKey:get('Voucher Key'),reference:get('Reference'),particulars:get('Particulars'),type:choice(get('Entry Type'),['General','Adjusting','Opening','Closing','Reclassification'],'General'),lines:[{account:account(get('Account')),party:get('Party')?party(get('Party'),null).name:'',debit:amount(get('Debit'),'Debit',true),credit:amount(get('Credit'),'Credit',true),remarks:get('Remarks')}]});if(!record.voucherKey)throw Error('Voucher Key is required to group the journal lines.');
        }
        return record;
    }
    function preview(sheet,text,context){
        if(!columns[sheet])throw Error('Unknown sheet.');if(text.length>5*1024*1024)throw Error('Use a CSV file smaller than 5 MB.');
        const rows=parseCsv(text);if(rows.length<2)throw Error('Add entry rows below the template header.');if(rows.length>501)throw Error('Import up to 500 rows at a time.');
        const headers=rows.shift().map(key),allowed=columns[sheet].map(key);if(headers.some(header=>!allowed.includes(header))||new Set(headers).size!==headers.length)throw Error('The file has unknown or duplicate columns. Use this page’s template.');
        const errors=[],records=[],groups=new Map(),seen=new Set(context.ledger[methods[sheet]]().filter(record=>['posted','draft'].includes(record.status)).map(record=>fingerprint(sheet,record)));
        rows.forEach((values,index)=>{const row=index+2;try{if(values.length!==headers.length)throw Error('Column count does not match the header. Quote values containing commas.');const raw={...Object.fromEntries(headers.map((header,i)=>[header,values[i].trim()])),_row:row};let record=build(sheet,raw,context);
            if(sheet==='journal'){const previous=groups.get(record.voucherKey);if(previous){if(['date','reference','particulars','type','notes'].some(field=>previous[field]!==record[field]))throw Error('Rows with the same Voucher Key must have matching voucher details.');previous.lines.push(...record.lines);return;}groups.set(record.voucherKey,record);}
            records.push(record);
        }catch(problem){errors.push({row,message:problem.message});}});
        for(const record of records){const signature=fingerprint(sheet,record);if(seen.has(signature))errors.push({row:record._row,message:'Duplicate entry: already in this file or the current sheet.'});seen.add(signature);}
        if(!errors.length){const validation=context.ledger.previewImport(sheet,records);errors.push(...validation.errors);}
        return {records,errors,rowCount:rows.length};
    }
    function guessMapping(sheet,headers){
        const synonyms={Date:['transactiondate','invoicedate','postingdate'],Customer:['customername','client','clientname'],Supplier:['suppliername','vendor','vendorname'], 'Gross Amount':['amount','total','totalamount','gross','invoiceamount'], 'Tax Type':['taxcode','vatcode','vat'], 'Revenue Account':['account','accountcode','glaccount'],Account:['accountcode','glaccount','expenseaccount'], 'Invoice Reference':['invoicenumber','invoiceno','invoice','siorno','orno','referenceno','reference'], 'Income Withholding ATC':['atc','atccode','ewtatc','cwtatc'], 'VAT Withholding ATC':['vatatc'],Description:['details','particulars','memo']};
        const used=new Set(),mapping={};
        for(const field of columns[sheet]){const names=[key(field),...(synonyms[field] || [])],matches=headers.flatMap((header,index)=>names.includes(key(header))&&!used.has(index)?[index]:[]);mapping[field]=matches.length===1?matches[0]:null;if(matches.length===1)used.add(matches[0]);}
        return mapping;
    }
    function mapRows(sheet,matrix,mapping,headerRow=1){
        if(!['sales','purchases'].includes(sheet))throw Error('Guided mapping supports Sales and Purchases.');
        if(!Number.isInteger(headerRow)||headerRow<1||headerRow>20||!Array.isArray(matrix)||matrix.length<=headerRow)throw Error('Choose a header row between 1 and 20 with data below it.');
        const indices=Object.values(mapping).filter(value=>value!==null&&value!=='');
        if(indices.some(index=>!Number.isInteger(index)||index<0||index>=100)||new Set(indices).size!==indices.length)throw Error('Map each source column to one field only.');
        if(Object.keys(mapping).some(field=>!columns[sheet].includes(field)))throw Error('Unsupported import field.');
        const rows=matrix.slice(headerRow).map((cells,index)=>({_row:index+headerRow+1,values:Object.fromEntries(columns[sheet].map(field=>[field,mapping[field]===null||mapping[field]===undefined||mapping[field]===''?'':String(cells[mapping[field]] ?? '').trim()])),skip:false,duplicateReviewed:false})).filter(row=>Object.values(row.values).some(value=>value!==''));
        if(!rows.length||rows.length>500)throw Error('Import between 1 and 500 nonempty data rows.');
        return rows;
    }
    function previewMapped(sheet,rows,context){
        if(!['sales','purchases'].includes(sheet)||!Array.isArray(rows)||rows.length>500)throw Error('Choose up to 500 Sales or Purchases rows.');
        const records=[],errors=[],warnings=[],existing=context.ledger[methods[sheet]]().filter(record=>['posted','draft'].includes(record.status)),seen=new Set(existing.map(record=>fingerprint(sheet,record))),comparisons=[...existing];
        for(const row of rows.filter(row=>!row.skip)){
            try{
                const raw={...Object.fromEntries(columns[sheet].map(field=>[key(field),String(row.values[field] ?? '').trim()])),_row:row._row};
                const record=build(sheet,raw,{...context,guided:true}),signature=fingerprint(sheet,record);
                if(seen.has(signature))errors.push({row:row._row,message:'Duplicate entry: already in this file or the current sheet. Skip this row.'});
                else if(comparisons.some(other=>other.date===record.date&&(other.customer || other.supplier)?.trim().toLowerCase()===(record.customer || record.supplier)?.trim().toLowerCase()&&other.gross===record.gross&&(!String(other.reference || '').trim()||!String(record.reference || '').trim())))warnings.push({row:row._row,message:'Possible duplicate: same party, date and amount with a missing reference. Review and acknowledge this row or skip it.'});
                seen.add(signature);comparisons.push(record);records.push(record);
            }catch(problem){errors.push({row:row._row,message:problem.message});}
        }
        if(!records.length&&!errors.length)errors.push({row:0,message:'No rows are included. Unskip at least one row.'});
        if(!errors.length&&records.length)errors.push(...context.ledger.previewImport(sheet,records).errors);
        return {records,errors,warnings,rowCount:rows.length,included:rows.filter(row=>!row.skip).length,totalGross:records.reduce((sum,record)=>sum+record.gross,0)};
    }
    const api={columns,methods,parseCsv,parseClipboard,sampleRows,template,amount,date,fingerprint,preview,guessMapping,mapRows,previewMapped};if(typeof module!=='undefined'&&module.exports)module.exports=api;if(root)root.SheetImport=api;
})(typeof window==='undefined'?null:window);
